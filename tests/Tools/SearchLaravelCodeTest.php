<?php

declare(strict_types=1);

namespace McpAi\Tests\Tools;

use McpAi\Tests\Support\FakeLaravelRepo;
use McpAi\Tools\SearchLaravelCode;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

final class SearchLaravelCodeTest extends TestCase
{
    private FakeLaravelRepo $repo;
    private SearchLaravelCode $tool;

    protected function setUp(): void
    {
        $this->repo = new FakeLaravelRepo();
        $this->tool = new SearchLaravelCode($this->repo->config(), new NullLogger());
    }

    protected function tearDown(): void
    {
        $this->repo->cleanup();
    }

    public function test_finds_match_in_controller(): void
    {
        $result = $this->tool->search('SELECT * FROM users');
        $this->assertNotEmpty($result['results']);
        $paths = array_map(fn($r) => $r['file'], $result['results']);
        $this->assertContains('app/Http/Controllers/UserController.php', $paths);
    }

    public function test_excludes_vendor_and_storage(): void
    {
        $result = $this->tool->search('SELECT * FROM users');
        foreach ($result['results'] as $r) {
            $this->assertStringStartsNotWith('vendor/',  $r['file'], 'vendor must be excluded');
            $this->assertStringStartsNotWith('storage/', $r['file'], 'storage must be excluded');
        }
    }

    public function test_excludes_env_and_key_files(): void
    {
        $result = $this->tool->search('super-secret');
        $this->assertSame([], $result['results'], '.env must never be searched');

        $result2 = $this->tool->search('BEGIN RSA PRIVATE KEY');
        $this->assertSame([], $result2['results'], '*.key files must never be searched');
    }

    public function test_empty_query_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->tool->search('   ');
    }

    public function test_rejects_directory_outside_allowlist_when_set(): void
    {
        $cfg = $this->repo->config([
            'search' => ['allowed_directories' => ['app']],
        ]);
        $tool = new SearchLaravelCode($cfg, new NullLogger());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not in allowlist');
        $tool->search('anything', directories: ['routes']);
    }

    public function test_rejects_traversal_in_directory_arg(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("contains '..'");
        $this->tool->search('x', directories: ['app/../..']);
    }

    public function test_rejects_disallowed_extension(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('extension not in allowlist');
        $this->tool->search('x', extensions: ['js']);
    }

    public function test_returns_message_on_no_match(): void
    {
        $result = $this->tool->search('definitely-not-in-the-repo-xyz');
        $this->assertSame([], $result['results']);
        $this->assertArrayHasKey('message', $result);
    }

    public function test_regex_mode(): void
    {
        $result = $this->tool->search('SELECT\s+\*', regex: true);
        $this->assertNotEmpty($result['results']);
    }

    public function test_respects_max_results_and_flags_truncation(): void
    {
        // Create many matching lines in one file.
        $path = $this->repo->root . '/app/Spammy.php';
        file_put_contents($path, "<?php\n" . str_repeat("// NEEDLE line\n", 20));

        $result = $this->tool->search('NEEDLE', maxResults: 5);
        $this->assertCount(5, $result['results']);
        $this->assertTrue($result['truncated']);
    }

    public function test_finds_match_in_blade_file(): void
    {
        $result = $this->tool->search('Shows user');
        $paths = array_map(fn($r) => $r['file'], $result['results']);
        $this->assertContains('resources/views/user/show.blade.php', $paths);
    }
}
