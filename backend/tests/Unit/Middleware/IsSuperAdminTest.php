<?php

namespace Tests\Unit\Middleware;

use App\Http\Middleware\IsSuperAdmin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class IsSuperAdminTest extends TestCase
{
    use RefreshDatabase;

    protected User $superAdmin;

    protected User $regularUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdmin = User::factory()->superAdmin()->create();
        $this->regularUser = User::factory()->create();
    }

    public function test_middleware_allows_superadmin(): void
    {
        $this->actingAs($this->superAdmin, 'sanctum');

        $middleware = new IsSuperAdmin;
        $request = Request::create('/api/v1/platform/stats', 'GET');
        $nextCalled = false;
        $next = function ($request) use (&$nextCalled) {
            $nextCalled = true;

            return response()->json(['success' => true]);
        };

        $response = $middleware->handle($request, $next);

        $this->assertTrue($nextCalled, 'Middleware should allow superadmin to proceed');
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function test_middleware_blocks_regular_user(): void
    {
        $this->actingAs($this->regularUser, 'sanctum');

        $middleware = new IsSuperAdmin;
        $request = Request::create('/api/v1/platform/stats', 'GET');
        $nextCalled = false;
        $next = function ($request) use (&$nextCalled) {
            $nextCalled = true;

            return response()->json(['success' => true]);
        };

        $response = $middleware->handle($request, $next);

        $this->assertFalse($nextCalled, 'Middleware should not call next for regular user');
        $this->assertEquals(403, $response->getStatusCode());
        $json = $response->getData(true);
        $this->assertFalse($json['success']);
        $this->assertEquals('Platform superadmin access required', $json['message']);
    }

    public function test_middleware_blocks_unauthenticated_user(): void
    {
        $middleware = new IsSuperAdmin;
        $request = Request::create('/api/v1/platform/stats', 'GET');
        $nextCalled = false;
        $next = function ($request) use (&$nextCalled) {
            $nextCalled = true;

            return response()->json(['success' => true]);
        };

        $response = $middleware->handle($request, $next);

        $this->assertFalse($nextCalled, 'Middleware should not call next for unauthenticated user');
        $this->assertEquals(401, $response->getStatusCode());
        $json = $response->getData(true);
        $this->assertFalse($json['success']);
        $this->assertEquals('Authentication required', $json['message']);
    }

    public function test_middleware_returns_correct_response_format(): void
    {
        $this->actingAs($this->regularUser, 'sanctum');

        $middleware = new IsSuperAdmin;
        $request = Request::create('/api/v1/platform/organizations', 'GET');
        $next = function ($request) {
            return response()->json(['success' => true]);
        };

        $response = $middleware->handle($request, $next);

        $json = $response->getData(true);
        $this->assertArrayHasKey('success', $json);
        $this->assertArrayHasKey('message', $json);
        $this->assertFalse($json['success']);
    }
}
