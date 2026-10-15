<?php

declare(strict_types=1);

namespace McpAi\Tests\Tools;

use McpAi\Tests\Support\FakeLaravelRepo;
use McpAi\Tools\GetGitDiff;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

final class GetGitDiffTest extends TestCase
{
    private FakeLaravelRepo $repo;
    private GetGitDiff $tool;

    protected function setUp(): void
    {
        $this->repo = new FakeLaravelRepo();
        $this->repo->initGit();
        $this->tool = new GetGitDiff($this->repo->config(), new NullLogger());
    }

    protected function tearDown(): void
    {
        $this->repo->cleanup();
    }

    public function test_detects_non_repo(): void
    {
        $plain = new FakeLaravelRepo('mcpai-nogit-');
        try {
            $tool = new GetGitDiff($plain->config(), new NullLogger());
            $result = $tool->diff('unstaged');
            $this->assertFalse($result['is_repo']);
            $this->assertSame([], $result['changed_files']);
        } finally {
            $plain->cleanup();
        }
    }

    public function test_no_changes_returns_message(): void
    {
        $result = $this->tool->diff('unstaged');
        $this->assertTrue($result['is_repo']);
        $this->assertSame([], $result['changed_files']);
        $this->assertArrayHasKey('message', $result);
    }

    public function test_unstaged_diff(): void
    {
        file_put_contents(
            $this->repo->root . '/app/Http/Controllers/UserController.php',
            "<?php\nnamespace App\\Http\\Controllers;\nclass UserController { public function show(\$id) { return intval(\$id); } }\n"
        );
        $result = $this->tool->diff('unstaged');

        $this->assertTrue($result['is_repo']);
        $paths = array_column($result['changed_files'], 'path');
        $this->assertContains('app/Http/Controllers/UserController.php', $paths);
        $this->assertStringContainsString('UserController', $result['diff']);
    }

    public function test_staged_diff(): void
    {
        file_put_contents($this->repo->root . '/app/NewFile.php', "<?php\n// new\n");
        $this->repo->runGit(['add', 'app/NewFile.php']);

        $result = $this->tool->diff('staged');
        $paths = array_column($result['changed_files'], 'path');
        $this->assertContains('app/NewFile.php', $paths);
    }

    public function test_branch_scope_requires_base_ref(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('`base_ref` is required');
        $this->tool->diff('branch');
    }

    public function test_branch_scope_rejects_invalid_ref(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('invalid ref');
        $this->tool->diff('branch', baseRef: 'main; rm -rf /');
    }

    public function test_branch_scope_rejects_leading_dash(): void
    {
        $this->expectException(RuntimeException::class);
        $this->tool->diff('branch', baseRef: '-upload-pack');
    }

    public function test_rejects_invalid_scope(): void
    {
        $this->expectException(RuntimeException::class);
        $this->tool->diff('reset');
    }

    public function test_truncation_flag_set_for_large_diff(): void
    {
        // Produce a large diff by modifying a tracked file (unstaged git diff
        // only shows tracked files).
        $big = str_repeat("line-of-content-" . bin2hex(random_bytes(2)) . "\n", 5000);
        file_put_contents(
            $this->repo->root . '/app/Http/Controllers/UserController.php',
            "<?php\n{$big}"
        );
        $cfg = $this->repo->config(['git' => ['max_diff_bytes' => 1024]]);
        $tool = new GetGitDiff($cfg, new NullLogger());

        $result = $tool->diff('unstaged');
        $this->assertTrue($result['truncated']);
        $this->assertLessThanOrEqual(1024 + 200, strlen($result['diff'])); // 200 slack for truncation banner
    }
}
