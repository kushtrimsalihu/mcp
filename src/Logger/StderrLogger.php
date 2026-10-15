<?php

declare(strict_types=1);

namespace McpAi\Logger;

use Psr\Log\AbstractLogger;
use Stringable;

/**
 * PSR-3 logger that writes to STDERR so it never corrupts the JSON-RPC
 * stream on STDOUT (required by the MCP STDIO transport).
 */
final class StderrLogger extends AbstractLogger
{
    public function __construct(private readonly string $minLevel = 'info') {}

    private const LEVELS = [
        'debug' => 0, 'info' => 1, 'notice' => 2, 'warning' => 3,
        'error' => 4, 'critical' => 5, 'alert' => 6, 'emergency' => 7,
    ];

    public function log($level, Stringable|string $message, array $context = []): void
    {
        $lvl = (string) $level;
        if ((self::LEVELS[$lvl] ?? 1) < (self::LEVELS[$this->minLevel] ?? 1)) {
            return;
        }
        $ctx = $context === [] ? '' : ' ' . json_encode($context, JSON_UNESCAPED_SLASHES);
        fwrite(STDERR, sprintf("[%s][%s] %s%s\n", date('Y-m-d H:i:s'), strtoupper($lvl), $message, $ctx));
    }
}
