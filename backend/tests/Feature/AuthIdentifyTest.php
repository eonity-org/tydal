<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * `POST /api/v1/auth/identify` — TYDAL as identity provider for a product
 * (Full Frame's studio): confirms who someone is and which organizations
 * they belong to, without issuing a token or touching their sessions.
 */
class AuthIdentifyTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('identify:ana@example.org');

        $this->user = User::factory()->create([
            'email' => 'ana@example.org',
            'password' => Hash::make('correct horse'),
            'is_active' => true,
        ]);
        $this->org = Organization::factory()->create(['slug' => 'lucila', 'name' => 'Lucila']);
        $this->org->users()->attach($this->user->id, ['role' => 'editor']);
    }

    private function identify(string $password = 'correct horse')
    {
        return $this->postJson('/api/v1/auth/identify', ['email' => 'ana@example.org', 'password' => $password]);
    }

    public function test_returns_the_user_and_their_organizations_with_roles(): void
    {
        $this->identify()
            ->assertOk()
            ->assertJsonPath('data.user.email', 'ana@example.org')
            ->assertJsonPath('data.organizations.0.slug', 'lucila')
            ->assertJsonPath('data.organizations.0.role', 'editor')
            ->assertJsonMissingPath('data.token');
    }

    public function test_issues_no_token_and_leaves_existing_sessions_alone(): void
    {
        $this->user->createToken('auth-token');

        $this->identify()->assertOk();

        $this->assertSame(1, $this->user->tokens()->count());
    }

    public function test_refuses_a_wrong_password_and_a_disabled_account(): void
    {
        $this->identify('wrong')->assertStatus(401);

        $this->user->update(['is_active' => false]);
        $this->identify()->assertStatus(403);
    }

    public function test_limits_attempts_per_email(): void
    {
        foreach (range(1, 5) as $_) {
            $this->identify('wrong')->assertStatus(401);
        }

        // Even the right password waits once the limit is reached.
        $this->identify()->assertStatus(429);
    }
}
