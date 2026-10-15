<?php

declare(strict_types=1);

namespace McpAi\Tests\Support;

use McpAi\Config\Config;
use Symfony\Component\Process\Process;

/**
 * Builds a minimal, throw-away "Laravel-shaped" directory tree used by the
 * tool test suite. The tree is NOT a real Laravel install — it contains just
 * enough files (artisan, composer.json, a controller, a .env, a vendor
 * folder) for the tools to behave realistically.
 */
final class FakeLaravelRepo
{
    public readonly string $root;

    public function __construct(?string $prefix = null)
    {
        $this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . ($prefix ?: 'mcpai-test-') . bin2hex(random_bytes(4));
        mkdir($this->root, 0777, true);
        $this->scaffold();
    }

    private function scaffold(): void
    {
        $this->write('artisan', "#!/usr/bin/env php\n<?php\n");
        $this->write('composer.json', json_encode(['name' => 'test/app'], JSON_PRETTY_PRINT));
        $this->write('.env', "APP_KEY=super-secret\nDB_PASSWORD=should-not-be-returned\n");

        $this->write('app/Http/Controllers/UserController.php', <<<'PHP'
<?php
namespace App\Http\Controllers;

class UserController
{
    public function show($id)
    {
        // TODO: validate $id (potential SQL injection target)
        $sql = "SELECT * FROM users WHERE id = $id";
        return $sql;
    }
}
PHP);

        $this->write('app/Models/User.php', <<<'PHP'
<?php
namespace App\Models;
class User {}
PHP);

        $this->write('routes/web.php', <<<'PHP'
<?php
// web routes
PHP);

        $this->write('resources/views/user/show.blade.php',
            "<h1>User {{ \$id }}</h1>\n{{-- Shows user --}}\n");

        // Files that MUST NOT appear in search results.
        $this->write('vendor/foo/bar/evil.php', "<?php // vendor file: SELECT * FROM users\n");
        $this->write('storage/logs/laravel.log', "SELECT * FROM users (secret)\n");
        $this->write('.env.local', "DB_PASSWORD=also-secret\n");
        $this->write('deploy.key', "-----BEGIN RSA PRIVATE KEY-----\nSELECT\n");
    }

    private function write(string $relative, string $content): void
    {
        $full = $this->root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $dir = dirname($full);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($full, $content);
    }

    public function initGit(): void
    {
        $this->runGit(['init', '-q', '-b', 'main']);
        $this->runGit(['config', 'user.email', 'test@example.com']);
        $this->runGit(['config', 'user.name', 'Test']);
        $this->runGit(['config', 'commit.gpgsign', 'false']);
        $this->runGit(['add', '-A']);
        $this->runGit(['commit', '-q', '-m', 'initial']);
    }

    /** @param list<string> $args */
    public function runGit(array $args): Process
    {
        $p = new Process(array_merge(['git'], $args), $this->root);
        $p->setTimeout(30.0);
        $p->mustRun();
        return $p;
    }

    public function config(array $overrides = []): Config
    {
        $base = [
            'laravel_root' => $this->root,
            'search' => [
                'max_files' => 500,
                'max_results' => 100,
                'max_file_bytes' => 262144,
                'max_excerpt_len' => 240,
                'allowed_directories' => [],
                'allowed_extensions' => ['php', 'blade.php'],
                'excluded_path_patterns' => ['vendor/', 'storage/', '.git/'],
                'excluded_file_patterns' => ['.env', '.env.*', '*.key', '*.log'],
            ],
            'git'    => ['max_diff_bytes' => 65536, 'timeout' => 15],
            'routes' => ['timeout' => 15],
        ];
        return Config::fromArray(array_replace_recursive($base, $overrides));
    }

    public function cleanup(): void
    {
        self::rmrf($this->root);
    }

    public static function rmrf(string $path): void
    {
        if (!is_dir($path)) {
            if (is_file($path) || is_link($path)) {
                @chmod($path, 0666);
                @unlink($path);
            }
            return;
        }
        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iter as $f) {
            @chmod($f->getPathname(), 0666);
            if ($f->isDir()) {
                @rmdir($f->getPathname());
            } else {
                @unlink($f->getPathname());
            }
        }
        @rmdir($path);
    }
}
