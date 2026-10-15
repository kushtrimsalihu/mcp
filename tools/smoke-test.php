<?php

/**
 * Minimal MCP smoke test.
 *
 * Spawns bin/mcp-server (STDIO by default, HTTP on Windows), performs the MCP
 * handshake, lists tools, and calls each one against the configured Laravel
 * root. Prints a short summary.
 *
 * This is NOT a replacement for the PHPUnit suite — it just proves the full
 * JSON-RPC wire protocol works end-to-end without an external MCP client.
 */

declare(strict_types=1);

$projectRoot = dirname(__DIR__);
$server      = $projectRoot . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'mcp-server';

$transport = ($argv[1] ?? null) ?: (PHP_OS_FAMILY === 'Windows' ? 'http' : 'stdio');
echo "Smoke test using transport: {$transport}\n";

$id = 0;
$sessionId = null;

if ($transport === 'http') {
    $port = 8765 + random_int(0, 500);
    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open(
        ['php', $server, "--transport=http", "--port={$port}"],
        $descriptors, $pipes, $projectRoot
    );
    if (!is_resource($proc)) {
        fwrite(STDERR, "Failed to start MCP server\n");
        exit(1);
    }

    // Wait up to 5s for the HTTP endpoint to accept connections.
    $endpoint = "http://127.0.0.1:{$port}/mcp";
    $deadline = microtime(true) + 5.0;
    while (microtime(true) < $deadline) {
        $fp = @stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $errstr, 0.2);
        if ($fp) { fclose($fp); break; }
        usleep(100_000);
    }

    $post = function (array $msg) use ($endpoint, &$sessionId): array {
        $headers = ['Content-Type: application/json', 'Accept: application/json, text/event-stream'];
        if ($sessionId !== null) {
            $headers[] = "Mcp-Session-Id: {$sessionId}";
        }
        $ctx = stream_context_create(['http' => [
            'method'        => 'POST',
            'header'        => implode("\r\n", $headers),
            'content'       => json_encode($msg),
            'ignore_errors' => true,
            'timeout'       => 10,
        ]]);
        $body = @file_get_contents($endpoint, false, $ctx);
        // Capture Mcp-Session-Id from first response if any.
        foreach ($http_response_header ?? [] as $h) {
            if (stripos($h, 'Mcp-Session-Id:') === 0) {
                $sessionId = trim(substr($h, strlen('Mcp-Session-Id:')));
            }
        }
        if ($body === false) {
            throw new RuntimeException("HTTP request failed for method {$msg['method']}");
        }
        // Server may return either plain JSON or an SSE stream. Extract last "data:" line in the latter case.
        $trimmed = ltrim($body);
        if (str_starts_with($trimmed, 'event:') || str_starts_with($trimmed, 'data:')) {
            $data = null;
            foreach (preg_split('/\r?\n/', $body) ?: [] as $line) {
                if (str_starts_with($line, 'data:')) {
                    $data = trim(substr($line, 5));
                }
            }
            $body = $data ?? '';
        }
        if ($body === '') {
            return []; // accepted notification
        }
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException("Non-JSON response to {$msg['method']}: {$body}");
        }
        return $decoded;
    };

    $request = function (string $method, array $params = []) use (&$id, $post): array {
        $id++;
        return $post(['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => $params]);
    };
    $notify = function (string $method, array $params = []) use ($post): void {
        $post(['jsonrpc' => '2.0', 'method' => $method, 'params' => $params]);
    };
    $shutdown = function () use ($proc, $pipes): void {
        @fclose($pipes[0]);
        $err = stream_get_contents($pipes[2]);
        @fclose($pipes[1]);
        @fclose($pipes[2]);
        proc_terminate($proc);
        proc_close($proc);
        if ($err !== false && $err !== '') {
            fwrite(STDERR, "\n--- server stderr ---\n{$err}");
        }
    };
} else {
    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open(['php', $server], $descriptors, $pipes, $projectRoot);
    if (!is_resource($proc)) {
        fwrite(STDERR, "Failed to start MCP server\n");
        exit(1);
    }
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $send = function (array $msg) use (&$pipes): void {
        fwrite($pipes[0], json_encode($msg) . "\n");
        fflush($pipes[0]);
    };
    $readLine = function (float $timeout = 5.0) use (&$pipes): ?string {
        $deadline = microtime(true) + $timeout;
        $buf = '';
        while (microtime(true) < $deadline) {
            $chunk = fgets($pipes[1]);
            if ($chunk !== false) {
                $buf .= $chunk;
                if (str_ends_with($buf, "\n")) {
                    return trim($buf);
                }
            } else {
                usleep(20_000);
            }
        }
        return null;
    };
    $request = function (string $method, array $params = []) use (&$id, $send, $readLine): array {
        $id++;
        $send(['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => $params]);
        $line = $readLine();
        if ($line === null) {
            throw new RuntimeException("Timeout waiting for response to {$method}");
        }
        $decoded = json_decode($line, true);
        if (!is_array($decoded)) {
            throw new RuntimeException("Non-JSON response to {$method}: {$line}");
        }
        return $decoded;
    };
    $notify = function (string $method, array $params = []) use ($send): void {
        $send(['jsonrpc' => '2.0', 'method' => $method, 'params' => $params]);
    };
    $shutdown = function () use ($proc, $pipes): void {
        @fclose($pipes[0]);
        $err = stream_get_contents($pipes[2]);
        @fclose($pipes[1]);
        @fclose($pipes[2]);
        proc_terminate($proc);
        proc_close($proc);
        if ($err !== false && $err !== '') {
            fwrite(STDERR, "\n--- server stderr ---\n{$err}");
        }
    };
}

$exit = 0;
try {
    $init = $request('initialize', [
        'protocolVersion' => '2024-11-05',
        'capabilities'    => new stdClass(),
        'clientInfo'      => ['name' => 'mcp-ai-smoke', 'version' => '0.1'],
    ]);
    if (!isset($init['result']['serverInfo'])) {
        throw new RuntimeException('initialize returned no serverInfo: ' . json_encode($init));
    }
    echo "initialize             OK  (server={$init['result']['serverInfo']['name']}"
        . " v{$init['result']['serverInfo']['version']})\n";

    $notify('notifications/initialized');

    $tools = $request('tools/list');
    $names = array_column($tools['result']['tools'] ?? [], 'name');
    echo 'tools/list             OK  (' . implode(', ', $names) . ")\n";
    foreach (['search_laravel_code', 'get_git_diff', 'inspect_laravel_routes'] as $name) {
        if (!in_array($name, $names, true)) {
            throw new RuntimeException("Missing expected tool: {$name}");
        }
    }

    $r = $request('tools/call', [
        'name'      => 'search_laravel_code',
        'arguments' => ['query' => 'Laravel', 'maxResults' => 3],
    ]);
    echo "search_laravel_code    OK  (isError=" . var_export($r['result']['isError'] ?? false, true) . ")\n";

    $r = $request('tools/call', [
        'name'      => 'get_git_diff',
        'arguments' => ['scope' => 'unstaged'],
    ]);
    echo "get_git_diff           OK  (isError=" . var_export($r['result']['isError'] ?? false, true) . ")\n";

    $r = $request('tools/call', [
        'name'      => 'inspect_laravel_routes',
        'arguments' => new stdClass(),
    ]);
    echo "inspect_laravel_routes OK  (isError=" . var_export($r['result']['isError'] ?? false, true) . ")\n";

    echo "\nAll smoke-test checks passed.\n";
} catch (\Throwable $e) {
    fwrite(STDERR, "SMOKE TEST FAILED: " . $e->getMessage() . "\n");
    $exit = 1;
} finally {
    $shutdown();
}
exit($exit);
