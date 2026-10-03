<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class McpTokenCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_issues_a_scoped_key_on_a_new_machine_user(): void
    {
        $organization = Organization::factory()->create(['slug' => 'acme']);

        $this->artisan('mcp:token', ['--org' => 'acme'])
            ->assertSuccessful();

        $user = User::where('email', 'mcp@tydal.test')->first();
        $this->assertNotNull($user);
        $this->assertTrue($organization->users()->where('users.id', $user->id)->exists());

        // Fallback org context for requests that omit X-Organization-ID.
        $this->assertSame($organization->id, $user->fresh()->last_organization_id);

        $token = $user->tokens()->where('name', 'claude-desktop')->first();
        $this->assertNotNull($token);
        $this->assertSame(['read', 'ask'], $token->abilities);
        $this->assertNull($token->expires_at);
    }

    public function test_reissuing_with_the_same_name_revokes_the_previous_key(): void
    {
        $organization = Organization::factory()->create(['slug' => 'acme']);

        $this->artisan('mcp:token', ['--org' => 'acme'])->assertSuccessful();
        $user = User::where('email', 'mcp@tydal.test')->first();
        $firstTokenId = $user->tokens()->where('name', 'claude-desktop')->first()->id;

        $this->artisan('mcp:token', ['--org' => 'acme'])->assertSuccessful();

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $firstTokenId]);
        $this->assertSame(1, $user->tokens()->where('name', 'claude-desktop')->count());
    }

    private function roleOf(Organization $organization): ?string
    {
        $user = User::where('email', 'mcp@tydal.test')->first();

        return $organization->users()->where('users.id', $user->id)->first()?->pivot->role;
    }

    public function test_a_new_machine_user_is_an_editor_by_default(): void
    {
        $organization = Organization::factory()->create(['slug' => 'acme']);

        $this->artisan('mcp:token', ['--org' => 'acme'])->assertSuccessful();

        $this->assertSame('editor', $this->roleOf($organization));
    }

    public function test_an_explicit_role_changes_an_existing_members_role(): void
    {
        // An editor may only change resources it owns: an agent curating
        // others' resources has to be raised to admin, and re-issuing must do it.
        $organization = Organization::factory()->create(['slug' => 'acme']);
        $this->artisan('mcp:token', ['--org' => 'acme'])->assertSuccessful();

        $this->artisan('mcp:token', ['--org' => 'acme', '--role' => 'admin'])
            ->expectsOutputToContain('Role changed from editor to admin')
            ->expectsOutputToContain('(admin @')
            ->assertSuccessful();

        $this->assertSame('admin', $this->roleOf($organization));
    }

    public function test_reissuing_without_a_role_keeps_the_existing_role(): void
    {
        $organization = Organization::factory()->create(['slug' => 'acme']);
        $this->artisan('mcp:token', ['--org' => 'acme', '--role' => 'admin'])->assertSuccessful();

        // No --role: the key is renewed, the admin is not downgraded, and the
        // output reports the role actually held
        $this->artisan('mcp:token', ['--org' => 'acme'])
            ->expectsOutputToContain('(admin @')
            ->assertSuccessful();

        $this->assertSame('admin', $this->roleOf($organization));
    }

    public function test_rejects_invalid_abilities(): void
    {
        Organization::factory()->create(['slug' => 'acme']);

        $this->artisan('mcp:token', ['--org' => 'acme', '--abilities' => 'read,superpowers'])
            ->assertFailed();
    }

    public function test_rejects_reserved_token_name(): void
    {
        Organization::factory()->create(['slug' => 'acme']);

        $this->artisan('mcp:token', ['--org' => 'acme', '--name' => 'auth-token'])
            ->assertFailed();
    }

    public function test_fails_cleanly_when_organization_not_found(): void
    {
        $this->artisan('mcp:token', ['--org' => 'does-not-exist'])
            ->assertFailed();
    }
}
