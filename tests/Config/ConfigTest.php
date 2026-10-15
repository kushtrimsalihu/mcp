<?php

declare(strict_types=1);

namespace McpAi\Tests\Config;

use McpAi\Config\Config;
use McpAi\Tests\Support\FakeLaravelRepo;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ConfigTest extends TestCase
{
    private FakeLaravelRepo $repo;

    protected function setUp(): void
    {
        $this->repo = new FakeLaravelRepo();
    }

    protected function tearDown(): void
    {
        $this->repo->cleanup();
    }

    public function test_requires_laravel_root(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('laravel_root is required');
        Config::fromArray([]);
    }

    public function test_rejects_missing_root(): void
    {
        $this->expectException(RuntimeException::class);
        Config::fromArray(['laravel_root' => sys_get_temp_dir() . '/does-not-exist-' . bin2hex(random_bytes(4))]);
    }

    public function test_rejects_non_laravel_directory(): void
    {
        $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mcpai-empty-' . bin2hex(random_bytes(4));
        mkdir($tmp);
        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('no `artisan` or `composer.json`');
            Config::fromArray(['laravel_root' => $tmp]);
        } finally {
            FakeLaravelRepo::rmrf($tmp);
        }
    }

    public function test_resolves_valid_root(): void
    {
        $cfg = $this->repo->config();
        $this->assertSame(realpath($this->repo->root), $cfg->laravelRoot);
        $this->assertSame(500, $cfg->maxSearchFiles);
    }
}
