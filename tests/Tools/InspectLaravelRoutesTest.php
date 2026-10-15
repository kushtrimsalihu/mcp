<?php

declare(strict_types=1);

namespace McpAi\Tests\Tools;

use McpAi\Tests\Support\FakeLaravelRepo;
use McpAi\Tools\InspectLaravelRoutes;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Rather than booting a real Laravel app in the test suite, we replace the
 * `artisan` script in the fake repo with a tiny PHP script that mimics the
 * JSON shape produced by `php artisan route:list --json`. That lets us
 * exercise the full subprocess + parse + filter pipeline deterministically.
 */
final class InspectLaravelRoutesTest extends TestCase
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

    private function installFakeArtisan(array $routes): void
    {
        $json = json_encode($routes);
        $script = <<<PHP
<?php
// Fake artisan: ignores all arguments and prints a canned JSON array.
echo <<<'JSON'
{$json}
JSON;
PHP;
        file_put_contents($this->repo->root . '/artisan', $script);
    }

    public function test_handles_missing_artisan(): void
    {
        @unlink($this->repo->root . '/artisan');
        $tool = new InspectLaravelRoutes($this->repo->config(), new NullLogger());
        $result = $tool->list();
        $this->assertFalse($result['is_laravel']);
        $this->assertArrayHasKey('message', $result);
    }

    public function test_parses_route_list(): void
    {
        $this->installFakeArtisan([
            ['method' => 'GET|HEAD', 'uri' => 'api/users',       'name' => 'users.index', 'action' => 'App\\Http\\Controllers\\UserController@index',  'middleware' => ['api']],
            ['method' => 'POST',     'uri' => 'api/users',       'name' => 'users.store', 'action' => 'App\\Http\\Controllers\\UserController@store',  'middleware' => ['api', 'auth:sanctum']],
            ['method' => 'GET',      'uri' => 'admin/dashboard', 'name' => null,          'action' => 'App\\Http\\Controllers\\Admin\\Dashboard@index', 'middleware' => 'web,auth'],
        ]);

        $tool = new InspectLaravelRoutes($this->repo->config(), new NullLogger());
        $result = $tool->list();
        $this->assertTrue($result['is_laravel']);
        $this->assertSame(3, $result['total']);
        $this->assertSame(3, $result['count']);
        $this->assertSame(['GET', 'HEAD'], $result['routes'][0]['methods']);
        $this->assertSame('users.index', $result['routes'][0]['name']);
        $this->assertSame(['web', 'auth'], $result['routes'][2]['middleware']);
    }

    public function test_filter_by_method(): void
    {
        $this->installFakeArtisan([
            ['method' => 'GET',  'uri' => 'a', 'name' => null, 'action' => 'X@a', 'middleware' => []],
            ['method' => 'POST', 'uri' => 'b', 'name' => null, 'action' => 'X@b', 'middleware' => []],
        ]);
        $tool = new InspectLaravelRoutes($this->repo->config(), new NullLogger());
        $result = $tool->list(method: 'POST');
        $this->assertSame(1, $result['count']);
        $this->assertSame('b', $result['routes'][0]['uri']);
    }

    public function test_filter_by_uri_and_controller(): void
    {
        $this->installFakeArtisan([
            ['method' => 'GET', 'uri' => 'api/users',  'name' => null, 'action' => 'UserController@index', 'middleware' => []],
            ['method' => 'GET', 'uri' => 'api/orders', 'name' => null, 'action' => 'OrderController@index','middleware' => []],
        ]);
        $tool = new InspectLaravelRoutes($this->repo->config(), new NullLogger());
        $r1 = $tool->list(uriContains: 'users');
        $this->assertSame(1, $r1['count']);
        $r2 = $tool->list(controller: 'OrderController');
        $this->assertSame(1, $r2['count']);
    }

    public function test_handles_artisan_failure(): void
    {
        file_put_contents($this->repo->root . '/artisan', "<?php\nfwrite(STDERR, 'boom'); exit(2);\n");
        $tool = new InspectLaravelRoutes($this->repo->config(), new NullLogger());
        $result = $tool->list();
        $this->assertTrue($result['is_laravel']);
        $this->assertSame(0, $result['total']);
        $this->assertArrayHasKey('message', $result);
        $this->assertStringContainsString('failed', $result['message']);
    }

    public function test_handles_non_json_output(): void
    {
        file_put_contents($this->repo->root . '/artisan', "<?php\necho 'not json';\n");
        $tool = new InspectLaravelRoutes($this->repo->config(), new NullLogger());
        $result = $tool->list();
        $this->assertStringContainsString('valid JSON', $result['message']);
    }
}
