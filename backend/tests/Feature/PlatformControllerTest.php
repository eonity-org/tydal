<?php

namespace Tests\Feature;

use App\Models\Collection;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $superAdmin;

    protected User $regularUser;

    protected string $superAdminToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdmin = User::factory()->superAdmin()->create();
        $this->regularUser = User::factory()->create();
        $this->superAdminToken = $this->superAdmin->createToken('test-token')->plainTextToken;
    }

    // ========== Organization Management Tests ==========

    public function test_superadmin_can_list_all_organizations(): void
    {
        Organization::factory()->count(3)->create();

        $response = $this->withToken($this->superAdminToken)
            ->getJson('/api/v1/platform/organizations');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'organizations' => [
                        'data' => [],
                        'current_page',
                        'total',
                    ],
                ],
            ]);
    }

    public function test_regular_user_cannot_list_all_organizations(): void
    {
        $token = $this->regularUser->createToken('test-token')->plainTextToken;

        $response = $this->withToken($token)
            ->getJson('/api/v1/platform/organizations');

        $response->assertStatus(403)
            ->assertJson([
                'success' => false,
                'message' => 'Platform superadmin access required',
            ]);
    }

    public function test_guest_cannot_access_platform_endpoints(): void
    {
        $response = $this->getJson('/api/v1/platform/organizations');

        $response->assertStatus(401);
    }

    public function test_superadmin_can_create_organization(): void
    {
        $owner = User::factory()->create();

        $response = $this->withToken($this->superAdminToken)
            ->postJson('/api/v1/platform/organizations', [
                'name' => 'Test Organization',
                'type' => 'business',
                'owner_email' => $owner->email,
                'owner_name' => 'Test Owner',
            ]);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'message' => 'Organization created successfully',
            ])
            ->assertJsonStructure([
                'data' => [
                    'organization' => [
                        'id',
                        'name',
                        'slug',
                        'type',
                    ],
                ],
            ]);

        $this->assertDatabaseHas('organizations', [
            'name' => 'Test Organization',
            'type' => 'business',
        ]);
    }

    public function test_create_organization_validates_input(): void
    {
        $response = $this->withToken($this->superAdminToken)
            ->postJson('/api/v1/platform/organizations', [
                'name' => '', // Invalid: empty
                'type' => 'invalid_type', // Invalid: not in enum
                'owner_email' => 'not-an-email', // Invalid: not an email
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'type', 'owner_email']);
    }

    public function test_superadmin_can_suspend_organization(): void
    {
        $organization = Organization::factory()->create(['is_active' => true]);

        $response = $this->withToken($this->superAdminToken)
            ->postJson("/api/v1/platform/organizations/{$organization->id}/suspend");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Organization suspended',
            ]);

        $this->assertDatabaseHas('organizations', [
            'id' => $organization->id,
            'is_active' => false,
        ]);
    }

    public function test_superadmin_can_activate_organization(): void
    {
        $organization = Organization::factory()->create(['is_active' => false]);

        $response = $this->withToken($this->superAdminToken)
            ->postJson("/api/v1/platform/organizations/{$organization->id}/activate");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Organization activated',
            ]);

        $this->assertDatabaseHas('organizations', [
            'id' => $organization->id,
            'is_active' => true,
        ]);
    }

    public function test_superadmin_cannot_delete_organization_with_resources(): void
    {
        $organization = Organization::factory()->create();
        $collection = Collection::factory()->create(['organization_id' => $organization->id]);
        \App\Models\Resource::factory()->create([
            'organization_id' => $organization->id,
            'collection_id' => $collection->id,
        ]);

        $response = $this->withToken($this->superAdminToken)
            ->deleteJson("/api/v1/platform/organizations/{$organization->id}");

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'message' => 'Cannot delete an organization that has resources',
            ]);
    }

    public function test_superadmin_can_delete_empty_organization(): void
    {
        $organization = Organization::factory()->create();

        $response = $this->withToken($this->superAdminToken)
            ->deleteJson("/api/v1/platform/organizations/{$organization->id}");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Organization deleted successfully',
            ]);

        $this->assertDatabaseMissing('organizations', [
            'id' => $organization->id,
        ]);
    }

    // ========== Platform Stats Tests ==========

    public function test_superadmin_can_view_platform_stats(): void
    {
        Organization::factory()->count(5)->create(['is_active' => true]);
        Organization::factory()->count(2)->create(['is_active' => false]);
        User::factory()->superAdmin()->count(3)->create();

        $response = $this->withToken($this->superAdminToken)
            ->getJson('/api/v1/platform/stats');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'stats' => [
                        'organizations' => [
                            'total',
                            'active',
                            'suspended',
                        ],
                        'users' => [
                            'total',
                            'superadmins',
                            'active',
                        ],
                        'resources',
                        'storage',
                    ],
                ],
            ]);

        $stats = $response->json('data.stats');
        $this->assertEquals(7, $stats['organizations']['total']);
        $this->assertEquals(5, $stats['organizations']['active']);
        $this->assertEquals(2, $stats['organizations']['suspended']);
    }

    // ========== Platform Settings Tests ==========

    public function test_superadmin_can_get_platform_settings(): void
    {
        $response = $this->withToken($this->superAdminToken)
            ->getJson('/api/v1/platform/settings');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'settings' => [
                        'allow_registrations',
                        'default_org_type',
                        'max_orgs_per_user',
                        'require_email_verification',
                    ],
                ],
            ]);
    }

    public function test_superadmin_can_update_platform_settings(): void
    {
        $response = $this->withToken($this->superAdminToken)
            ->putJson('/api/v1/platform/settings', [
                'allow_registrations' => false,
                'max_orgs_per_user' => 5,
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Settings updated successfully',
            ]);
    }

    public function test_update_settings_validates_input(): void
    {
        $response = $this->withToken($this->superAdminToken)
            ->putJson('/api/v1/platform/settings', [
                'max_orgs_per_user' => 'not-an-integer', // Invalid
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['max_orgs_per_user']);
    }

    // ========== Platform Users Tests ==========

    public function test_superadmin_can_list_all_users(): void
    {
        User::factory()->count(10)->create();
        User::factory()->superAdmin()->count(3)->create();

        $response = $this->withToken($this->superAdminToken)
            ->getJson('/api/v1/platform/users');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'users' => [
                        'data' => [],
                        'total',
                    ],
                ],
            ]);

        // Should have at least our superadmin + created users
        $this->assertGreaterThanOrEqual(14, $response->json('data.users.total'));
    }

    public function test_superadmin_can_filter_users_by_superadmin_status(): void
    {
        User::factory()->count(5)->create();
        User::factory()->superAdmin()->count(3)->create();

        $response = $this->withToken($this->superAdminToken)
            ->getJson('/api/v1/platform/users?is_superadmin=1');

        $response->assertStatus(200);

        // Should return only superadmins (our setup superadmin + 3 created)
        $this->assertEquals(4, $response->json('data.users.total'));
    }
}
