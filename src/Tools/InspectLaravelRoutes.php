<?php

declare(strict_types=1);

namespace McpAi\Tools;

use McpAi\Config\Config;
use PhpMcp\Server\Attributes\Schema;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * MCP tool: inspect_laravel_routes.
 *
 * Runs `php artisan route:list --json` in the configured Laravel project and
 * returns a parsed, optionally-filtered list of routes.
 *
 * Security:
 *   - Only the `route:list` subcommand is run; no user input is passed to artisan.
 *   - Filtering is performed in-process after the JSON is parsed.
 *   - Fails gracefully (`is_laravel: false`) when `artisan` is missing or the
 *     command errors out — no information from the exception is leaked beyond
 *     a short message.
 */
final class InspectLaravelRoutes
{
    public function __construct(
        private readonly Config          $config,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @param string|null $method     Optional HTTP method filter (e.g. GET, POST). Case-insensitive.
     * @param string|null $uriContains Optional case-insensitive substring to match against the URI.
     * @param string|null $controller  Optional case-insensitive substring to match against the action/controller.
     * @param int         $limit       Max routes to return (<=500).
     *
     * @return array{is_laravel:bool,count:int,total:int,truncated:bool,routes:list<array{methods:list<string>,uri:string,name:?string,action:string,middleware:list<string>}>,message?:string}
     */
    public function list(
        #[Schema(enum: ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'ANY'])]
        ?string $method      = null,
        #[Schema(maxLength: 200)]
        ?string $uriContains = null,
        #[Schema(maxLength: 200)]
        ?string $controller  = null,
        #[Schema(minimum: 1, maximum: 500)]
        int     $limit       = 200,
    ): array {
        $root = $this->config->laravelRoot;
        $artisan = $root . DIRECTORY_SEPARATOR . 'artisan';
        if (!is_file($artisan)) {
            return [
                'is_laravel' => false,
                'count'      => 0,
                'total'      => 0,
                'truncated'  => false,
                'routes'     => [],
                'message'    => "No `artisan` found at {$root}; cannot list routes.",
            ];
        }

        $p = new Process(['php', 'artisan', 'route:list', '--json'], $root);
        $p->setTimeout((float) $this->config->routeTimeout);
        $p->run();
        if (!$p->isSuccessful()) {
            $err = trim($p->getErrorOutput() ?: $p->getOutput());
            // Keep message short to avoid leaking env details.
            $short = substr(preg_replace('/\s+/', ' ', $err) ?? '', 0, 300);
            return [
                'is_laravel' => true,
                'count'      => 0,
                'total'      => 0,
                'truncated'  => false,
                'routes'     => [],
                'message'    => "artisan route:list failed: {$short}",
            ];
        }

        $decoded = json_decode($p->getOutput(), true);
        if (!is_array($decoded)) {
            return [
                'is_laravel' => true,
                'count'      => 0,
                'total'      => 0,
                'truncated'  => false,
                'routes'     => [],
                'message'    => 'artisan route:list did not return valid JSON.',
            ];
        }

        $normalized = array_values(array_filter(array_map(
            fn($r) => is_array($r) ? $this->normalizeRoute($r) : null,
            $decoded,
        )));
        $total = count($normalized);

        $filtered = array_values(array_filter($normalized, function (array $r) use ($method, $uriContains, $controller): bool {
            if ($method !== null && $method !== '') {
                $m = strtoupper($method);
                if (!in_array($m, $r['methods'], true) && !in_array('ANY', $r['methods'], true)) {
                    return false;
                }
            }
            if ($uriContains !== null && $uriContains !== '' && stripos($r['uri'], $uriContains) === false) {
                return false;
            }
            if ($controller !== null && $controller !== '' && stripos($r['action'], $controller) === false) {
                return false;
            }
            return true;
        }));

        $truncated = count($filtered) > $limit;
        $slice = array_slice($filtered, 0, $limit);

        $this->logger->info('inspect_laravel_routes completed', [
            'total' => $total, 'filtered' => count($filtered), 'returned' => count($slice),
        ]);

        return [
            'is_laravel' => true,
            'count'      => count($slice),
            'total'      => $total,
            'truncated'  => $truncated,
            'routes'     => $slice,
        ];
    }

    /**
     * @param array<string,mixed> $raw
     * @return array{methods:list<string>,uri:string,name:?string,action:string,middleware:list<string>}
     */
    private function normalizeRoute(array $raw): array
    {
        $methods = $raw['method'] ?? $raw['methods'] ?? '';
        if (is_string($methods)) {
            $methods = array_values(array_filter(array_map('trim', explode('|', $methods))));
        } elseif (!is_array($methods)) {
            $methods = [];
        }
        $methods = array_values(array_map(fn($m) => strtoupper((string) $m), $methods));

        $middleware = $raw['middleware'] ?? [];
        if (is_string($middleware)) {
            $middleware = array_values(array_filter(array_map('trim', explode(',', $middleware))));
        } elseif (!is_array($middleware)) {
            $middleware = [];
        }
        $middleware = array_values(array_map('strval', $middleware));

        return [
            'methods'    => $methods,
            'uri'        => (string) ($raw['uri'] ?? ''),
            'name'       => isset($raw['name']) && $raw['name'] !== '' ? (string) $raw['name'] : null,
            'action'     => (string) ($raw['action'] ?? ''),
            'middleware' => $middleware,
        ];
    }
}
