<?php

/**
 * MCP-AI end-to-end demonstration (in-process).
 *
 * What this script does (and nothing more):
 *
 *   1. Materialises the `examples/sample-laravel` fixture into a disposable
 *      git working tree under the system temp dir (so the fixture inside the
 *      repo is never dirtied).
 *   2. Introduces a small, representative change that reintroduces a classic
 *      SQL-injection pattern (`"... WHERE id = $id"`) in UserController@show.
 *   3. Instantiates the three MCP tool classes directly against that temp
 *      copy and invokes each one in the order an AI reviewer would.
 *   4. Prints each tool's structured response. These responses are the exact
 *      shape served to a connected MCP client — the only thing skipped is
 *      the JSON-RPC transport layer, which is already proven end-to-end by
 *      `tools/smoke-test.php` (spawns `bin/mcp-server` over HTTP, performs
 *      initialize + tools/list + tools/call for all three tools).
 *   5. Runs three security spot-checks: `.env` must never appear in search
 *      output, path-traversal must be rejected, and shell-metachar git refs
 *      must be rejected.
 *
 * This script does NOT ask an LLM anything. It only proves that what the AI
 * would receive is real, machine-usable data from the MCP tools. The AI
 * writing the review itself has to happen in a real MCP client (Claude
 * Desktop, Cursor, etc.) — see README §6.
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use McpAi\Config\Config;
use McpAi\Logger\StderrLogger;
use McpAi\Tools\GetGitDiff;
use McpAi\Tools\InspectLaravelRoutes;
use McpAi\Tools\SearchLaravelCode;

$projectRoot = dirname(__DIR__);
$fixtureSrc  = $projectRoot . DIRECTORY_SEPARATOR . 'examples' . DIRECTORY_SEPARATOR . 'sample-laravel';

if (!is_dir($fixtureSrc)) {
    fwrite(STDERR, "Missing fixture: {$fixtureSrc}\n");
    exit(1);
}

$workDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mcpai-demo-' . bin2hex(random_bytes(4));
mkdir($workDir, 0777, true);

$exit = 0;
try {
    // -----------------------------------------------------------------------
    // STEP 1: materialise the fixture.
    // -----------------------------------------------------------------------
    copyTree($fixtureSrc, $workDir);
    runCmd(['git', 'init', '-q', '-b', 'main'], $workDir);
    runCmd(['git', 'config', 'user.email', 'demo@example.com'], $workDir);
    runCmd(['git', 'config', 'user.name',  'Demo'], $workDir);
    runCmd(['git', 'config', 'commit.gpgsign', 'false'], $workDir);
    runCmd(['git', 'add', '-A'], $workDir);
    runCmd(['git', 'commit', '-q', '-m', 'initial fixture'], $workDir);

    banner('STEP 1: fixture materialised + git-initialised');
    echo "working tree: {$workDir}\n";

    // -----------------------------------------------------------------------
    // STEP 2: introduce the vulnerable change the AI has to find.
    // -----------------------------------------------------------------------
    $ctrl = $workDir . '/app/Http/Controllers/UserController.php';
    $src  = file_get_contents($ctrl);
    $src  = preg_replace(
        '#    public function show\(\$id\)\s*\{\s*// Safe implementation.*?\n        return User::findOrFail\(\$id\);\s*\n    \}#s',
        "    public function show(\$id)\n    {\n"
        . "        // Reintroduced: raw \$id concatenated into SQL.\n"
        . "        \$row = DB::select(\"SELECT * FROM users WHERE id = \$id\");\n"
        . "        return response()->json(\$row);\n"
        . "    }",
        $src
    );
    file_put_contents($ctrl, $src);

    banner('STEP 2: unsafe change applied to UserController@show');
    echo rtrim(runCmd(['git', 'diff', '--stat'], $workDir)) . "\n";

    // -----------------------------------------------------------------------
    // STEP 3: build the tools directly against the temp fixture.
    // -----------------------------------------------------------------------
    banner('STEP 3: instantiating MCP tool classes (same code paths the server uses)');
    $config = Config::fromArray([
        'laravel_root' => $workDir,
        'search' => [
            'allowed_extensions'     => ['php', 'blade.php'],
            'excluded_path_patterns' => ['vendor/', 'node_modules/', 'storage/', 'bootstrap/cache/', '.git/'],
            'excluded_file_patterns' => ['.env', '.env.*', '*.key', '*.pem', '*.log'],
        ],
    ]);
    // Quiet logger for the demo transcript. Set MCP_AI_DEMO_VERBOSE=1 to see
    // the structured info logs each tool emits.
    $logger = new StderrLogger(getenv('MCP_AI_DEMO_VERBOSE') ? 'info' : 'error');
    $search = new SearchLaravelCode($config, $logger);
    $diff   = new GetGitDiff($config, $logger);
    $routes = new InspectLaravelRoutes($config, $logger);
    echo "  laravel_root: {$config->laravelRoot}\n";
    echo "  tools:        search_laravel_code, get_git_diff, inspect_laravel_routes\n";

    // -----------------------------------------------------------------------
    // STEP 4: invoke the three tools in the order an AI reviewer would.
    // -----------------------------------------------------------------------
    banner('STEP 4a: get_git_diff(scope="unstaged")');
    $r = $diff->diff('unstaged');
    printPayload([
        'is_repo'       => $r['is_repo'],
        'changed_files' => $r['changed_files'],
        'bytes'         => $r['bytes'],
        'truncated'     => $r['truncated'],
        'diff_preview'  => truncateText($r['diff'], 20),
    ]);

    banner('STEP 4b: search_laravel_code(query="SELECT * FROM users")');
    printPayload(callSafely(
        fn() => $search->search('SELECT * FROM users', maxResults: 10)
    ));

    banner('STEP 4c: search_laravel_code(query="class UserController")');
    printPayload(callSafely(
        fn() => $search->search('class UserController', maxResults: 5)
    ));

    banner('STEP 4d: inspect_laravel_routes(uriContains="users")');
    printPayload(callSafely(
        fn() => $routes->list(uriContains: 'users')
    ));

    // -----------------------------------------------------------------------
    // STEP 5: security spot-checks.
    // -----------------------------------------------------------------------
    banner('STEP 5: security spot-checks');

    echo "  5a. .env must never appear in search results...\n";
    $r = $search->search('fixture-must-never-leak');
    $leaked = false;
    foreach ($r['results'] as $hit) {
        if (str_contains($hit['excerpt'], 'fixture-must-never-leak')) { $leaked = true; break; }
    }
    echo '      ' . ($leaked ? 'FAIL — secret appeared' : 'OK  — .env excluded') . "\n";

    echo "  5b. path traversal must be rejected...\n";
    try {
        $search->search('x', directories: ['../../etc']);
        echo "      FAIL — allowed\n";
    } catch (\Throwable $e) {
        echo "      OK  — rejected ({$e->getMessage()})\n";
    }

    echo "  5c. shell-metachar git ref must be rejected...\n";
    try {
        $diff->diff('branch', baseRef: 'main; rm -rf /');
        echo "      FAIL — allowed\n";
    } catch (\Throwable $e) {
        echo "      OK  — rejected ({$e->getMessage()})\n";
    }

    banner('DONE');
    echo "The outputs above are exactly what an AI MCP client would receive\n";
    echo "when calling these tools. Protocol-level verification (initialize,\n";
    echo "tools/list, tools/call over JSON-RPC) is covered by tools/smoke-test.php.\n";
    echo "AI-authored review itself requires running the server from Claude\n";
    echo "Desktop / Cursor / Codex — see README \xC2\xA76.\n";
} catch (\Throwable $e) {
    fwrite(STDERR, "\nDEMO FAILED: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
    $exit = 1;
} finally {
    rmrf($workDir);
}
exit($exit);

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function banner(string $title): void
{
    echo "\n" . str_repeat('=', 72) . "\n{$title}\n" . str_repeat('=', 72) . "\n";
}

function printPayload(mixed $payload): void
{
    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    foreach (preg_split('/\r?\n/', $json) ?: [] as $line) {
        echo '  ' . $line . "\n";
    }
}

function truncateText(string $text, int $maxLines): string
{
    $lines = preg_split('/\r?\n/', $text) ?: [];
    if (count($lines) <= $maxLines) {
        return $text;
    }
    return implode("\n", array_slice($lines, 0, $maxLines))
        . "\n[... " . (count($lines) - $maxLines) . " more lines ...]";
}

function callSafely(callable $fn): mixed
{
    try {
        return $fn();
    } catch (\Throwable $e) {
        return ['error' => $e->getMessage()];
    }
}

/** @param list<string> $cmd */
function runCmd(array $cmd, string $cwd): string
{
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd);
    if (!is_resource($proc)) {
        throw new RuntimeException('cmd failed: ' . implode(' ', $cmd));
    }
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $code = proc_close($proc);
    if ($code !== 0) {
        throw new RuntimeException("cmd failed ({$code}): " . implode(' ', $cmd) . "\n{$err}");
    }
    return $out;
}

function copyTree(string $src, string $dst): void
{
    $src = rtrim($src, DIRECTORY_SEPARATOR); $dst = rtrim($dst, DIRECTORY_SEPARATOR);
    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iter as $item) {
        /** @var SplFileInfo $item */
        $rel = substr($item->getPathname(), strlen($src) + 1);
        $target = $dst . DIRECTORY_SEPARATOR . $rel;
        if ($item->isDir()) {
            if (!is_dir($target)) { mkdir($target, 0777, true); }
        } else {
            if (!is_dir(dirname($target))) { mkdir(dirname($target), 0777, true); }
            copy($item->getPathname(), $target);
        }
    }
}

function rmrf(string $path): void
{
    if (!is_dir($path)) { @unlink($path); return; }
    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iter as $f) {
        @chmod($f->getPathname(), 0666);
        $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
    @rmdir($path);
}
