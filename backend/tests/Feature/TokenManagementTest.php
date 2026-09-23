<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TokenManagementTest extends TestCase
{
    use RefreshDatabase;

    // ── Login token rotation ──────────────────────────────────────────────

    public function test_login_rotates_session_tokens_but_preserves_api_keys(): void
    {
        $user = User::factory()->create(['password' => bcrypt('password123')]);

        $oldSession = $user->createToken('auth-token', ['*'], now()->addHour());
        $apiKey = $user->createToken('claude-desktop', ['read', 'ask']);

        $this->postJson('/api/v1/login', [
            'email' => $user->email,
            'password' => 'password123',
        ])->assertStatus(200);

        // Old session token rotated away; exactly one fresh one remains.
        $this->assertDatabaseMissing('personal_access_tokens', [
            'id' => $oldSession->accessToken->id,
        ]);
        $this->assertSame(1, $user->tokens()->where('name', 'auth-token')->count());
        $this->assertDatabaseHas('personal_access_tokens', [
            'id' => $apiKey->accessToken->id,
            'name' => 'claude-desktop',
        ]);
    }

    // ── Refresh escalation guard ──────────────────────────────────────────

    public function test_scoped_api_key_cannot_refresh(): void
    {
        $user = User::factory()->create();
        $apiKey = $user->createToken('claude-desktop', ['read', 'ask', 'write']);

        $this->withToken($apiKey->plainTextToken)
            ->postJson('/api/v1/refresh')
            ->assertStatus(403);

        // Key still valid — refresh must not have consumed it.
        $this->assertDatabaseHas('personal_access_tokens', [
            'id' => $apiKey->accessToken->id,
        ]);
    }

    // ── Token CRUD ────────────────────────────────────────────────────────

    public function test_session_token_can_create_list_and_revoke_api_keys(): void
    {
        $user = User::factory()->create();
        $session = $user->createToken('auth-token', ['*'], now()->addHour());

        $created = $this->withToken($session->plainTextToken)
            ->postJson('/api/v1/tokens', [
                'name' => 'claude-desktop',
                'abilities' => ['read', 'ask'],
                'expires_in_days' => 30,
            ]);

        $created->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', 'claude-desktop')
            ->assertJsonPath('data.abilities', ['read', 'ask']);
        $this->assertNotEmpty($created->json('data.token'));

        $list = $this->withToken($session->plainTextToken)
            ->getJson('/api/v1/tokens');

        $list->assertStatus(200);
        $names = array_column($list->json('data.tokens'), 'name');
        $this->assertContains('claude-desktop', $names);
        // Token values are never returned by the listing.
        $this->assertArrayNotHasKey('token', $list->json('data.tokens')[0]);

        $id = $created->json('data.id');
        $this->withToken($session->plainTextToken)
            ->deleteJson("/api/v1/tokens/{$id}")
            ->assertStatus(200);

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $id]);
    }

    public function test_duplicate_token_name_is_rejected(): void
    {
        $user = User::factory()->create();
        $session = $user->createToken('auth-token', ['*'], now()->addHour());
        $user->createToken('claude-desktop', ['read']);

        $this->withToken($session->plainTextToken)
            ->postJson('/api/v1/tokens', ['name' => 'claude-desktop'])
            ->assertStatus(422);
    }

    public function test_invalid_ability_is_rejected(): void
    {
        $user = User::factory()->create();
        $session = $user->createToken('auth-token', ['*'], now()->addHour());

        $this->withToken($session->plainTextToken)
            ->postJson('/api/v1/tokens', [
                'name' => 'bad-key',
                'abilities' => ['superpowers'],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['abilities.0']);
    }

    public function test_session_token_name_is_reserved(): void
    {
        $user = User::factory()->create();
        $session = $user->createToken('auth-token', ['*'], now()->addHour());

        $this->withToken($session->plainTextToken)
            ->postJson('/api/v1/tokens', ['name' => 'auth-token'])
            ->assertStatus(422);
    }

    public function test_scoped_api_key_cannot_manage_tokens(): void
    {
        $user = User::factory()->create();
        $apiKey = $user->createToken('claude-desktop', ['read', 'ask', 'write']);

        $this->withToken($apiKey->plainTextToken)
            ->getJson('/api/v1/tokens')
            ->assertStatus(403);

        $this->withToken($apiKey->plainTextToken)
            ->postJson('/api/v1/tokens', ['name' => 'escalated'])
            ->assertStatus(403);
    }

    // ── Ability enforcement ───────────────────────────────────────────────

    public function test_read_only_key_can_read_but_not_write(): void
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->create();
        $organization->users()->attach($user->id, ['role' => 'editor']);

        $readKey = $user->createToken('read-key', ['read', 'ask']);

        $this->withToken($readKey->plainTextToken)
            ->getJson('/api/v1/me')
            ->assertStatus(200);

        $this->withToken($readKey->plainTextToken)
            ->withHeader('X-Organization-ID', (string) $organization->id)
            ->postJson('/api/v1/workspaces', ['name' => 'Nope'])
            ->assertStatus(403)
            ->assertJsonPath('message', "Token is missing the 'write' ability.");
    }

    public function test_session_token_is_unaffected_by_ability_gate(): void
    {
        $user = User::factory()->create();
        $session = $user->createToken('auth-token', ['*'], now()->addHour());

        $this->withToken($session->plainTextToken)
            ->getJson('/api/v1/me')
            ->assertStatus(200);
    }
}
