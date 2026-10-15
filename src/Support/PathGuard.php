<?php

declare(strict_types=1);

namespace McpAi\Support;

use RuntimeException;

/**
 * Filesystem path guard. Keeps every tool strictly inside the configured
 * Laravel repository root and rejects path-traversal attempts before any
 * I/O happens.
 */
final class PathGuard
{
    public function __construct(private readonly string $root)
    {
        $real = realpath($root);
        if ($real === false || !is_dir($real)) {
            throw new RuntimeException("PathGuard: invalid root {$root}");
        }
        $this->rootReal = self::normalize($real);
    }

    private readonly string $rootReal;

    public function root(): string
    {
        return $this->rootReal;
    }

    /**
     * Resolve $relative against the root and ensure the result stays inside
     * the root. Returns the canonical absolute path.
     *
     * The relative path MUST NOT be absolute and MUST NOT contain `..` segments
     * that escape the root. Symlinks pointing outside the root are rejected.
     */
    public function resolve(string $relative): string
    {
        if ($relative === '' || $relative === '.' || $relative === './') {
            return $this->rootReal;
        }

        $normalized = str_replace('\\', '/', $relative);
        // Reject absolute paths (unix `/foo`, windows `C:/foo` or UNC `//host/share`).
        if (str_starts_with($normalized, '/') || preg_match('#^[A-Za-z]:/#', $normalized) === 1) {
            throw new RuntimeException("PathGuard: absolute paths are not allowed: {$relative}");
        }

        $candidate = $this->rootReal . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $normalized);
        $real = realpath($candidate);
        if ($real === false) {
            // Path doesn't exist. Validate the lexical form so callers can still
            // get a sensible error without an fs touch.
            $lexical = self::lexicalResolve($this->rootReal, $normalized);
            if (!self::isInside($this->rootReal, $lexical)) {
                throw new RuntimeException("PathGuard: path escapes root: {$relative}");
            }
            throw new RuntimeException("PathGuard: path not found: {$relative}");
        }
        $real = self::normalize($real);
        if (!self::isInside($this->rootReal, $real)) {
            throw new RuntimeException("PathGuard: path escapes root: {$relative}");
        }
        return $real;
    }

    public function containsPath(string $absolutePath): bool
    {
        $real = realpath($absolutePath);
        if ($real === false) {
            return false;
        }
        return self::isInside($this->rootReal, self::normalize($real));
    }

    public function relative(string $absolutePath): string
    {
        $normalized = self::normalize($absolutePath);
        if (!self::isInside($this->rootReal, $normalized)) {
            throw new RuntimeException("PathGuard: {$absolutePath} is not inside {$this->rootReal}");
        }
        if ($normalized === $this->rootReal) {
            return '';
        }
        return ltrim(substr($normalized, strlen($this->rootReal)), '/\\');
    }

    private static function normalize(string $path): string
    {
        return rtrim(str_replace('/', DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR);
    }

    private static function isInside(string $root, string $path): bool
    {
        if ($path === $root) {
            return true;
        }
        return str_starts_with($path . DIRECTORY_SEPARATOR, $root . DIRECTORY_SEPARATOR);
    }

    private static function lexicalResolve(string $root, string $relative): string
    {
        $parts = [];
        foreach (explode('/', $relative) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                if (empty($parts)) {
                    // Would escape root.
                    return '';
                }
                array_pop($parts);
                continue;
            }
            $parts[] = $segment;
        }
        return self::normalize($root . DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $parts));
    }
}
