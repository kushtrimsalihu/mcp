<?php

declare(strict_types=1);

namespace McpAi\Config;

use RuntimeException;

/**
 * Immutable configuration loader for the MCP-AI server.
 *
 * Loads from (in order of precedence):
 *   1. explicit array passed to Config::fromArray()
 *   2. config file at $projectRoot/config/mcp-ai.php
 *   3. example file at $projectRoot/config/mcp-ai.php.example
 *
 * All fields are validated. The configured Laravel root is resolved to its
 * canonical absolute path so later path checks can use string prefix matching.
 */
final class Config
{
    public function __construct(
        public readonly string $laravelRoot,
        public readonly int    $maxSearchFiles,
        public readonly int    $maxSearchResults,
        public readonly int    $maxFileBytes,
        public readonly int    $maxExcerptLen,
        /** @var string[] */
        public readonly array  $allowedDirectories,
        /** @var string[] */
        public readonly array  $allowedExtensions,
        /** @var string[] */
        public readonly array  $excludedPathPatterns,
        /** @var string[] */
        public readonly array  $excludedFilePatterns,
        public readonly int    $maxDiffBytes,
        public readonly int    $gitTimeout,
        public readonly int    $routeTimeout,
    ) {}

    public static function load(string $projectRoot): self
    {
        $file = $projectRoot . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'mcp-ai.php';
        if (!is_file($file)) {
            $file = $projectRoot . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'mcp-ai.php.example';
        }
        if (!is_file($file)) {
            throw new RuntimeException("MCP-AI config not found. Expected config/mcp-ai.php under {$projectRoot}");
        }
        /** @var array<string,mixed> $data */
        $data = require $file;
        if (!is_array($data)) {
            throw new RuntimeException("MCP-AI config file {$file} must return an array");
        }
        return self::fromArray($data);
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        $root = (string) ($data['laravel_root'] ?? '');
        if ($root === '') {
            throw new RuntimeException('Config: laravel_root is required');
        }
        $real = realpath($root);
        if ($real === false || !is_dir($real)) {
            throw new RuntimeException("Config: laravel_root does not exist or is not a directory: {$root}");
        }
        // Must look at least superficially like a Laravel/PHP repo.
        if (!is_file($real . DIRECTORY_SEPARATOR . 'artisan')
            && !is_file($real . DIRECTORY_SEPARATOR . 'composer.json')) {
            throw new RuntimeException("Config: laravel_root {$real} has no `artisan` or `composer.json`");
        }

        $search = (array) ($data['search'] ?? []);
        $git    = (array) ($data['git']    ?? []);
        $routes = (array) ($data['routes'] ?? []);

        return new self(
            laravelRoot:          $real,
            maxSearchFiles:       max(1, (int) ($search['max_files']       ?? 2000)),
            maxSearchResults:     max(1, (int) ($search['max_results']     ?? 100)),
            maxFileBytes:         max(1024, (int) ($search['max_file_bytes'] ?? 1048576)),
            maxExcerptLen:        max(40,   (int) ($search['max_excerpt_len'] ?? 240)),
            allowedDirectories:   array_values(array_map('strval', $search['allowed_directories'] ?? [])),
            allowedExtensions:    array_values(array_map('strval', $search['allowed_extensions']  ?? ['php', 'blade.php'])),
            excludedPathPatterns: array_values(array_map('strval', $search['excluded_path_patterns'] ?? [])),
            excludedFilePatterns: array_values(array_map('strval', $search['excluded_file_patterns'] ?? [])),
            maxDiffBytes:         max(1024, (int) ($git['max_diff_bytes'] ?? 204800)),
            gitTimeout:           max(1,    (int) ($git['timeout']        ?? 15)),
            routeTimeout:         max(1,    (int) ($routes['timeout']     ?? 30)),
        );
    }
}
