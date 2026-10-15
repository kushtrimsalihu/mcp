<?php

declare(strict_types=1);

namespace McpAi\Tools;

use McpAi\Config\Config;
use McpAi\Support\PathGuard;
use PhpMcp\Server\Attributes\Schema;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * MCP tool: search_laravel_code.
 *
 * Case-insensitive substring (optionally regex) search over PHP/Blade source
 * files under the configured Laravel repository root. Returns structured
 * matches (file, line, excerpt) suitable for an AI client to reason about.
 *
 * Security:
 *   - All paths are resolved via PathGuard; nothing outside laravel_root is read.
 *   - Vendor, storage, cache, .env, keys, logs, etc. are excluded by default.
 *   - File scans are bounded by size, count, and excerpt length limits.
 */
final class SearchLaravelCode
{
    public function __construct(
        private readonly Config          $config,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Search Laravel PHP/Blade source files for a substring (or regex) match.
     *
     * @param string      $query        Required. The text to search for.
     * @param string[]|null $directories Optional. Relative directories under the project root
     *                                   to limit the search to. If omitted, searches the whole
     *                                   project (minus excluded paths).
     * @param string[]|null $extensions  Optional. File extensions to include (without the dot).
     *                                   Must be a subset of the configured allowlist.
     * @param bool         $regex        If true, $query is treated as a PCRE pattern body
     *                                   (anchored with /…/i automatically). Defaults to false.
     * @param int          $maxResults   Max match rows to return (<= configured cap).
     *
     * @return array{query:string,regex:bool,files_scanned:int,results:list<array{file:string,line:int,excerpt:string}>,truncated:bool,message?:string}
     */
    public function search(
        #[Schema(minLength: 1, maxLength: 200)]
        string $query,
        ?array $directories = null,
        ?array $extensions  = null,
        bool   $regex       = false,
        #[Schema(minimum: 1, maximum: 500)]
        int    $maxResults  = 50,
    ): array {
        if (trim($query) === '') {
            throw new RuntimeException('search_laravel_code: `query` must not be empty');
        }

        $maxResults = min($maxResults, $this->config->maxSearchResults);
        $guard = new PathGuard($this->config->laravelRoot);

        // Resolve directories (relative -> absolute, all guarded).
        $roots = [];
        $effectiveDirs = $directories ?: $this->config->allowedDirectories;
        if (empty($effectiveDirs)) {
            $roots[] = $guard->root();
        } else {
            foreach ($effectiveDirs as $dir) {
                if (!is_string($dir) || $dir === '') {
                    continue;
                }
                // Reject explicit traversal early with a clear error message.
                if (str_contains(str_replace('\\', '/', $dir), '..')) {
                    throw new RuntimeException("search_laravel_code: directory contains '..': {$dir}");
                }
                // If a caller-supplied directory list exists, enforce it as an allowlist
                // when the config also defines one.
                if ($directories !== null
                    && !empty($this->config->allowedDirectories)
                    && !in_array(ltrim(str_replace('\\', '/', $dir), '/'), $this->config->allowedDirectories, true)) {
                    throw new RuntimeException("search_laravel_code: directory not in allowlist: {$dir}");
                }
                $roots[] = $guard->resolve($dir);
            }
        }

        // Resolve extensions.
        $exts = $extensions ?: $this->config->allowedExtensions;
        foreach ($exts as $e) {
            if (!in_array($e, $this->config->allowedExtensions, true)) {
                throw new RuntimeException("search_laravel_code: extension not in allowlist: {$e}");
            }
        }

        // Compile pattern.
        if ($regex) {
            $pattern = '/' . str_replace('/', '\\/', $query) . '/i';
            if (@preg_match($pattern, '') === false) {
                throw new RuntimeException('search_laravel_code: invalid regex pattern');
            }
        } else {
            $pattern = '/' . preg_quote($query, '/') . '/i';
        }

        $results = [];
        $filesScanned = 0;
        $truncated = false;

        foreach ($roots as $rootDir) {
            foreach ($this->iterateFiles($rootDir, $exts) as $absPath) {
                if ($filesScanned >= $this->config->maxSearchFiles) {
                    $truncated = true;
                    break 2;
                }
                $filesScanned++;

                $size = @filesize($absPath);
                if ($size === false || $size > $this->config->maxFileBytes) {
                    continue;
                }
                $handle = @fopen($absPath, 'rb');
                if ($handle === false) {
                    continue;
                }
                $rel = $guard->relative($absPath);
                $lineNo = 0;
                while (($line = fgets($handle)) !== false) {
                    $lineNo++;
                    if (preg_match($pattern, $line) !== 1) {
                        continue;
                    }
                    $excerpt = rtrim($line, "\r\n");
                    if (strlen($excerpt) > $this->config->maxExcerptLen) {
                        $excerpt = substr($excerpt, 0, $this->config->maxExcerptLen) . '…';
                    }
                    $results[] = [
                        'file'    => str_replace('\\', '/', $rel),
                        'line'    => $lineNo,
                        'excerpt' => $excerpt,
                    ];
                    if (count($results) >= $maxResults) {
                        $truncated = true;
                        break;
                    }
                }
                fclose($handle);
                if ($truncated) {
                    break 2;
                }
            }
        }

        $this->logger->info('search_laravel_code completed', [
            'query' => $query, 'regex' => $regex,
            'files_scanned' => $filesScanned, 'results' => count($results),
            'truncated' => $truncated,
        ]);

        $out = [
            'query'         => $query,
            'regex'         => $regex,
            'files_scanned' => $filesScanned,
            'results'       => $results,
            'truncated'     => $truncated,
        ];
        if ($results === []) {
            $out['message'] = 'No matches found.';
        }
        return $out;
    }

    /**
     * @param string[] $extensions
     * @return \Generator<int,string>
     */
    private function iterateFiles(string $rootDir, array $extensions): \Generator
    {
        $dirIterator = new \RecursiveDirectoryIterator(
            $rootDir,
            \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::UNIX_PATHS
        );
        $filter = new \RecursiveCallbackFilterIterator(
            $dirIterator,
            function (\SplFileInfo $current): bool {
                $rel = $this->relativeTo($this->config->laravelRoot, $current->getPathname());
                return !$this->isExcludedPath($rel) && !$this->isExcludedFile($current->getFilename());
            }
        );
        $iter = new \RecursiveIteratorIterator($filter);
        foreach ($iter as $file) {
            /** @var \SplFileInfo $file */
            if (!$file->isFile()) {
                continue;
            }
            if (!$this->extensionMatches($file->getFilename(), $extensions)) {
                continue;
            }
            yield $file->getPathname();
        }
    }

    private function relativeTo(string $root, string $path): string
    {
        $root = rtrim(str_replace('\\', '/', $root), '/');
        $path = str_replace('\\', '/', $path);
        if (str_starts_with($path, $root . '/')) {
            return substr($path, strlen($root) + 1);
        }
        return $path;
    }

    private function isExcludedPath(string $relative): bool
    {
        $rel = str_replace('\\', '/', $relative);
        foreach ($this->config->excludedPathPatterns as $pat) {
            $norm = str_replace('\\', '/', $pat);
            if ($norm !== '' && (str_starts_with($rel, $norm) || str_contains($rel, '/' . $norm))) {
                return true;
            }
        }
        return false;
    }

    private function isExcludedFile(string $name): bool
    {
        foreach ($this->config->excludedFilePatterns as $pat) {
            if (fnmatch($pat, $name, FNM_CASEFOLD)) {
                return true;
            }
        }
        return false;
    }

    /** @param string[] $extensions */
    private function extensionMatches(string $name, array $extensions): bool
    {
        $lower = strtolower($name);
        foreach ($extensions as $ext) {
            $ext = ltrim(strtolower($ext), '.');
            if (str_ends_with($lower, '.' . $ext)) {
                return true;
            }
        }
        return false;
    }
}
