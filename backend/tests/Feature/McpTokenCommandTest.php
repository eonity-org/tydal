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
