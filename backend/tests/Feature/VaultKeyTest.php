<?php

namespace Tests\Feature;

use App\Enums\ResourceState;
use App\Enums\VaultPurpose;
use App\Enums\VaultState;
use App\Models\Organization;
use App\Models\Resource;
use App\Models\User;
use App\Models\Vault;
use App\Models\VaultKey;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Epic 2.4 — VaultKey both-tier auth (spec §7): published vaults keyless,
 * private vaults reachable with a valid key; plus the vault-level hash
 * addresses (/h/{vaultHash}) the MCP connects through.
 */
class VaultKeyTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Workspace $ws;

    private Vault $vault;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create(['slug' => 'acme']);
        $this->ws = Workspace::factory()->create(['organization_id' => $this->org->id]);
        $this->vault = Vault::factory()->purpose(VaultPurpose::AI)->create([
            'organization_id' => $this->org->id,
            'slug' => 'private-vault',
            'state' => VaultState::PRIVATE->value,
        ]);

        DB::table('workspace_vault')->insert(['workspace_id' => $this->ws->id, 'vault_id' => $this->vault->id]);

        $resource = Resource::factory()->create(['organization_id' => $this->org->id, 'name' => 'Doc', 'state' => ResourceState::LIVE->value]);
        DB::table('dam_resource_workspace')->insert(['resource_id' => $resource->id, 'workspace_id' => $this->ws->id]);
    }

    // =========================================================================
    // Key auth on the consumer surface
    // =========================================================================

    public function test_private_vault_is_unreachable_without_key(): void
    {
        $this->getJson('/v/acme/private-vault')->assertStatus(404);
        $this->getJson("/h/{$this->vault->hash}")->assertStatus(404);
    }

    public function test_valid_key_opens_private_vault_via_header(): void
    {
        [, $plaintext] = VaultKey::mint($this->vault, 'ci-key');

        $this->withHeader('X-Vault-Key', $plaintext)
            ->getJson('/v/acme/private-vault')
            ->assertStatus(200)
            ->assertJsonPath('type', 'vault-index');

        $this->withHeader('X-Vault-Key', $plaintext)
            ->getJson("/h/{$this->vault->hash}/meta")
            ->assertStatus(200)
            ->assertJsonPath('type', 'vault')
            ->assertJsonPath('slug', 'private-vault');
    }

    public function test_valid_key_works_as_query_parameter(): void
    {
        [, $plaintext] = VaultKey::mint($this->vault, 'query-key');

        $this->getJson('/v/acme/private-vault?vault_key='.$plaintext)
            ->assertStatus(200);
    }

    public function test_wrong_key_is_rejected(): void
    {
        VaultKey::mint($this->vault, 'real-key');

        $this->withHeader('X-Vault-Key', 'tvk_not-the-real-key')
            ->getJson('/v/acme/private-vault')
            ->assertStatus(404);
    }

    public function test_revoked_key_is_rejected(): void
    {
        [$key, $plaintext] = VaultKey::mint($this->vault, 'old-key');
        $key->update(['revoked_at' => now()]);

        $this->withHeader('X-Vault-Key', $plaintext)
            ->getJson('/v/acme/private-vault')
            ->assertStatus(404);
    }

    public function test_key_use_touches_last_used_at(): void
    {
        [$key, $plaintext] = VaultKey::mint($this->vault, 'tracked-key');
        $this->assertNull($key->last_used_at);

        $this->withHeader('X-Vault-Key', $plaintext)
            ->getJson('/v/acme/private-vault')
            ->assertStatus(200);

        $this->assertNotNull($key->fresh()->last_used_at);
    }

    public function test_published_vault_stays_keyless(): void
    {
        $this->vault->update(['state' => VaultState::PUBLIC->value]);

        $this->getJson('/v/acme/private-vault')->assertStatus(200);
        $this->getJson("/h/{$this->vault->hash}")->assertStatus(200);
    }

    // =========================================================================
    // Vault-level hash addresses (MCP connection point)
    // =========================================================================

    public function test_vault_hash_root_serves_index_and_operations(): void
    {
        $this->vault->update(['state' => VaultState::PUBLIC->value]);

        $this->getJson("/h/{$this->vault->hash}")
            ->assertStatus(200)
            ->assertJsonPath('type', 'vault-index')
            ->assertJsonPath('pagination.total', 1);

        $this->getJson("/h/{$this->vault->hash}/tags")
            ->assertStatus(200)
            ->assertJsonPath('type', 'vault-tags');

        $this->getJson("/h/{$this->vault->hash}/search?q=Doc")
            ->assertStatus(200)
            ->assertJsonPath('pagination.total', 1);
    }

    public function test_chunk_search_is_tier1_gated(): void
    {
        $gallery = Vault::factory()->purpose(VaultPurpose::GALLERY)->published()->create([
            'organization_id' => $this->org->id,
            'slug' => 'gallery-vault',
        ]);
        DB::table('workspace_vault')->insert(['workspace_id' => $this->ws->id, 'vault_id' => $gallery->id]);

        $this->getJson('/v/acme/gallery-vault/search?q=doc&scope=chunks')->assertStatus(403);

        // ai vault allows chunks; without ES data/index it returns empty results
        $this->vault->update(['state' => VaultState::PUBLIC->value]);
        $this->getJson('/v/acme/private-vault/search?q=doc&scope=chunks')
            ->assertStatus(200)
            ->assertJsonPath('type', 'chunk-search')
            ->assertJsonPath('results', []);
    }

    // =========================================================================
    // Platform key management
    // =========================================================================

    public function test_platform_key_lifecycle(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $token = $superAdmin->createToken('test')->plainTextToken;

        // Mint — plaintext appears exactly once
        $created = $this->withToken($token)
            ->postJson("/api/v1/platform/vaults/{$this->vault->id}/keys", ['name' => 'Partner A'])
            ->assertStatus(201)
            ->assertJsonPath('data.key.name', 'Partner A');

        $plaintext = $created->json('data.plaintext');
        $keyId = $created->json('data.key.id');
        $this->assertStringStartsWith('tvk_', $plaintext);

        // List — prefixes only, never the plaintext or hash
        $list = $this->withToken($token)
            ->getJson("/api/v1/platform/vaults/{$this->vault->id}/keys")
            ->assertStatus(200);

        $this->assertSame(substr($plaintext, 0, 12), $list->json('data.keys.0.key_prefix'));
        $this->assertArrayNotHasKey('key_hash', $list->json('data.keys.0'));

        // The minted key actually opens the vault
        $this->withHeader('X-Vault-Key', $plaintext)
            ->getJson('/v/acme/private-vault')
            ->assertStatus(200);

        // Revoke — immediately rejected afterwards
        $this->withToken($token)
            ->deleteJson("/api/v1/platform/vaults/{$this->vault->id}/keys/{$keyId}")
            ->assertStatus(200);

        $this->withHeader('X-Vault-Key', $plaintext)
            ->getJson('/v/acme/private-vault')
            ->assertStatus(404);
    }
}
