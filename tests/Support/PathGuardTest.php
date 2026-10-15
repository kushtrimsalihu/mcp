<?php

declare(strict_types=1);

namespace McpAi\Tests\Support;

use McpAi\Support\PathGuard;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PathGuardTest extends TestCase
{
    private FakeLaravelRepo $repo;
    private PathGuard $guard;

    protected function setUp(): void
    {
        $this->repo = new FakeLaravelRepo();
        $this->guard = new PathGuard($this->repo->root);
    }

    protected function tearDown(): void
    {
        $this->repo->cleanup();
    }

    public function test_resolves_relative_path(): void
    {
        $resolved = $this->guard->resolve('app/Http/Controllers/UserController.php');
        $this->assertFileExists($resolved);
    }

    public function test_rejects_traversal(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('escapes root');
        $this->guard->resolve('../../../../etc/passwd');
    }

    public function test_rejects_absolute_path(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('absolute paths');
        $this->guard->resolve(PHP_OS_FAMILY === 'Windows' ? 'C:/Windows/System32' : '/etc/passwd');
    }

    public function test_relative_returns_project_relative_path(): void
    {
        $abs = $this->guard->resolve('app/Models/User.php');
        $this->assertSame('app\\Models\\User.php', str_replace('/', '\\', $this->guard->relative($abs)));
    }
}
