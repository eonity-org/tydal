<?php

namespace Tests\Unit;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SuperadminTest extends TestCase
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

    public function test_superadmin_has_is_superadmin_flag(): void
    {
        $this->assertTrue($this->superAdmin->is_superadmin);
        $this->assertFalse($this->regularUser->is_superadmin);
    }

    public function test_is_super_admin_method(): void
    {
        $this->assertTrue($this->superAdmin->isSuperAdmin());
        $this->assertFalse($this->regularUser->isSuperAdmin());
    }

    public function test_superadmin_can_access_any_organization(): void
    {
        $organizationId = (string) Str::uuid();

        $this->assertTrue(
            $this->superAdmin->canAccessOrganization($organizationId),
            'Superadmin should be able to access any organization'
        );
    }

    public function test_regular_user_cannot_access_organization_without_membership(): void
    {
        $organizationId = (string) Str::uuid();

        $this->assertFalse(
            $this->regularUser->canAccessOrganization($organizationId),
            'Regular user should not be able to access organization they are not a member of'
        );
    }

    public function test_regular_user_can_access_their_organization(): void
    {
        $organization = Organization::factory()->create();
        $organization->users()->attach($this->regularUser->id, ['role' => 'viewer']);

        $this->assertTrue(
            $this->regularUser->canAccessOrganization($organization->id),
            'Regular user should be able to access their own organization'
        );
    }

    public function test_factory_creates_superadmin(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $this->assertTrue($superAdmin->isSuperAdmin());
    }

    public function test_factory_creates_regular_user_by_default(): void
    {
        $user = User::factory()->create();

        $this->assertFalse($user->isSuperAdmin());
    }
}
