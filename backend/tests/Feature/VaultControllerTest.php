<?php

namespace Tests\Feature;

use App\Enums\VaultState;
use App\Models\Organization;
use App\Models\Resource;
use App\Models\User;
use App\Models\Vault;
use App\Models\VaultLink;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class VaultControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $superAdmin;

    protected User $regularUser;

    protected string $superAdminToken;

    protected string $regularToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdmin = User::factory()->superAdmin()->create();
        $this->regularUser = User::factory()->create();
        $this->superAdminToken = $this->superAdmin->createToken('test')->plainTextToken;
        $this->regularToken = $this->regularUser->createToken('test')->plainTextToken;
    }

    // =========================================================================
    // Platform CRUD — superadmin only
    // =========================================================================

    public function test_superadmin_can_list_vaults(): void
    {
        Vault::factory()->count(3)->create();

        $response = $this->withToken($this->superAdminToken)
            ->getJson('/api/v1/platform/vaults');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => ['vaults'],
                'meta' => ['pagination'],
            ]);
    }

    public function test_regular_user_cannot_list_platform_vaults(): void
    {
        $this->withToken($this->regularToken)
            ->getJson('/api/v1/platform/vaults')
            ->assertStatus(403);
    }

    public function test_guest_cannot_access_platform_vaults(): void
    {
        $this->getJson('/api/v1/platform/vaults')
            ->assertStatus(401);
    }

    public function test_superadmin_can_create_vault(): void
    {
        $org = Organization::factory()->create();

        $response = $this->withToken($this->superAdminToken)
            ->postJson('/api/v1/platform/vaults', [
                'organization_id' => $org->id,
                'name' => 'Public Vault',
                'slug' => 'public-vault',
                'salt' => 'my-secret-salt',
                'has_public_workspace' => false,
                'is_downloadable' => true,
                'hash_ttl_hours' => null,
                'allowed_ips' => [],
                'state' => VaultState::PRIVATE->value,
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.vault.slug', 'public-vault')
            ->assertJsonPath('data.vault.purpose', 'delivery')
            ->assertJsonPath('data.vault.state', 'private');

        $this->assertDatabaseHas('vaults', ['slug' => 'public-vault', 'organization_id' => $org->id]);
    }

    public function test_create_vault_defaults_organization_to_the_current_org(): void
    {
        // The UI no longer sends organization_id — it comes from the org
        // context (the org selected in the header, here the X-Organization-ID).
        $org = Organization::factory()->create();

        $this->withToken($this->superAdminToken)
            ->withHeader('X-Organization-ID', $org->id)
            ->postJson('/api/v1/platform/vaults', [
                'name' => 'Context Vault',
                'slug' => 'context-vault',
                'salt' => 'my-secret-salt',
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.vault.organization_id', $org->id);

        $this->assertDatabaseHas('vaults', ['slug' => 'context-vault', 'organization_id' => $org->id]);
    }

    public function test_create_vault_requires_organization_when_no_context(): void
    {
        // No explicit org and no org context → still a hard validation error.
        $this->withToken($this->superAdminToken)
            ->postJson('/api/v1/platform/vaults', [
                'name' => 'Orphan Vault',
                'slug' => 'orphan-vault',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['organization_id']);
    }

    public function test_create_vault_auto_generates_the_salt(): void
    {
        // The UI never sends a salt — it's a generated secret, not a form field.
        $org = Organization::factory()->create();

        $this->withToken($this->superAdminToken)
            ->withHeader('X-Organization-ID', $org->id)
            ->postJson('/api/v1/platform/vaults', [
                'name' => 'Saltless Vault',
                'slug' => 'saltless-vault',
            ])
            ->assertStatus(201);

        $salt = Vault::where('slug', 'saltless-vault')->value('salt');
        $this->assertNotEmpty($salt);
        $this->assertSame(Vault::SALT_LENGTH, strlen($salt));
    }

    public function test_rotate_salt_changes_the_secret_and_purges_links(): void
    {
        $vault = Vault::factory()->create();
        $resource = Resource::factory()->create();
        VaultLink::create([
            'vault_id' => $vault->id,
            'resource_id' => $resource->id,
            'link_key' => VaultLink::computeLinkKey($vault->id, null, $resource->id, null),
            'hash' => 'KEEPHASH1',
        ]);
        $oldSalt = $vault->salt;

        $this->withToken($this->superAdminToken)
            ->postJson("/api/v1/platform/vaults/{$vault->id}/rotate-salt")
            ->assertStatus(200)
            ->assertJsonPath('data.links_purged', 1);

        $this->assertNotSame($oldSalt, $vault->fresh()->salt);
        $this->assertDatabaseMissing('vault_links', ['vault_id' => $vault->id]);
    }

    public function test_regular_users_cannot_rotate_salt(): void
    {
        $vault = Vault::factory()->create();

        $this->withToken($this->regularToken)
            ->postJson("/api/v1/platform/vaults/{$vault->id}/rotate-salt")
            ->assertStatus(403);
    }

    public function test_vault_hash_is_generated_and_unique(): void
    {
        $a = Vault::factory()->create();
        $b = Vault::factory()->create();

        $this->assertNotEmpty($a->hash);
        $this->assertSame(Vault::HASH_LENGTH, strlen($a->hash));
        $this->assertNotSame($a->hash, $b->hash);
    }

    public function test_same_slug_is_allowed_across_organizations_but_not_within_one(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        Vault::factory()->create(['organization_id' => $orgA->id, 'slug' => 'shared-slug']);

        // Same slug in another org — fine
        $this->withToken($this->superAdminToken)
            ->postJson('/api/v1/platform/vaults', [
                'organization_id' => $orgB->id,
                'name' => 'Shared Slug B',
                'slug' => 'shared-slug',
                'salt' => 'my-secret-salt',
            ])
            ->assertStatus(201);

        // Same slug in the same org — rejected
        $this->withToken($this->superAdminToken)
            ->postJson('/api/v1/platform/vaults', [
                'organization_id' => $orgA->id,
                'name' => 'Shared Slug A2',
                'slug' => 'shared-slug',
                'salt' => 'my-secret-salt',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['slug']);
    }

    public function test_create_vault_rejects_invalid_purpose(): void
    {
        $org = Organization::factory()->create();

        $this->withToken($this->superAdminToken)
            ->postJson('/api/v1/platform/vaults', [
                'organization_id' => $org->id,
                'name' => 'Bad Purpose',
                'slug' => 'bad-purpose',
                'salt' => 'my-secret-salt',
                'purpose' => 'warehouse',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['purpose']);
    }

    public function test_superadmin_can_show_vault(): void
    {
        $vault = Vault::factory()->create();

        $this->withToken($this->superAdminToken)
            ->getJson("/api/v1/platform/vaults/{$vault->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.vault.id', $vault->id);
    }

    public function test_show_returns_404_for_nonexistent_vault(): void
    {
        $this->withToken($this->superAdminToken)
            ->getJson('/api/v1/platform/vaults/nonexistent-id')
            ->assertStatus(404);
    }

    public function test_superadmin_can_update_vault(): void
    {
        $vault = Vault::factory()->create(['name' => 'Old Name']);

        $this->withToken($this->superAdminToken)
            ->putJson("/api/v1/platform/vaults/{$vault->id}", ['name' => 'New Name'])
            ->assertStatus(200)
            ->assertJsonPath('data.vault.name', 'New Name');

        $this->assertDatabaseHas('vaults', ['id' => $vault->id, 'name' => 'New Name']);
    }

    public function test_updating_salt_purges_existing_links(): void
    {
        $vault = Vault::factory()->create(['salt' => 'original-salt']);
        $ws = Workspace::factory()->create();
        $res = Resource::factory()->create();

        VaultLink::factory()->create([
            'vault_id' => $vault->id,
            'workspace_id' => $ws->id,
            'resource_id' => $res->id,
        ]);

        $this->assertDatabaseCount('vault_links', 1);

        $this->withToken($this->superAdminToken)
            ->putJson("/api/v1/platform/vaults/{$vault->id}", ['salt' => 'new-salt'])
            ->assertStatus(200);

        $this->assertDatabaseCount('vault_links', 0);
    }

    public function test_updating_purpose_purges_existing_links(): void
    {
        $vault = Vault::factory()->create();
        $ws = Workspace::factory()->create();
        $res = Resource::factory()->create();

        VaultLink::factory()->create([
            'vault_id' => $vault->id,
            'workspace_id' => $ws->id,
            'resource_id' => $res->id,
        ]);

        $this->assertDatabaseCount('vault_links', 1);

        $this->withToken($this->superAdminToken)
            ->putJson("/api/v1/platform/vaults/{$vault->id}", ['purpose' => 'ai'])
            ->assertStatus(200)
            ->assertJsonPath('data.vault.purpose', 'ai');

        $this->assertDatabaseCount('vault_links', 0);
    }

    public function test_updating_with_same_purpose_does_not_purge_links(): void
    {
        $vault = Vault::factory()->create(); // purpose defaults to delivery
        $ws = Workspace::factory()->create();
        $res = Resource::factory()->create();

        VaultLink::factory()->create([
            'vault_id' => $vault->id,
            'workspace_id' => $ws->id,
            'resource_id' => $res->id,
        ]);

        $this->withToken($this->superAdminToken)
            ->putJson("/api/v1/platform/vaults/{$vault->id}", ['purpose' => 'delivery'])
            ->assertStatus(200);

        $this->assertDatabaseCount('vault_links', 1);
    }

    public function test_updating_without_salt_does_not_purge_links(): void
    {
        $vault = Vault::factory()->create(['salt' => 'original-salt']);
        $ws = Workspace::factory()->create();
        $res = Resource::factory()->create();

        VaultLink::factory()->create([
            'vault_id' => $vault->id,
            'workspace_id' => $ws->id,
            'resource_id' => $res->id,
        ]);

        $this->withToken($this->superAdminToken)
            ->putJson("/api/v1/platform/vaults/{$vault->id}", ['name' => 'Updated Name'])
            ->assertStatus(200);

        $this->assertDatabaseCount('vault_links', 1);
    }

    public function test_superadmin_can_delete_vault(): void
    {
        $vault = Vault::factory()->create();

        $this->withToken($this->superAdminToken)
            ->deleteJson("/api/v1/platform/vaults/{$vault->id}")
            ->assertStatus(200)
            ->assertJson(['success' => true]);

        $this->assertDatabaseMissing('vaults', ['id' => $vault->id]);
    }

    public function test_delete_returns_404_for_nonexistent_vault(): void
    {
        $this->withToken($this->superAdminToken)
            ->deleteJson('/api/v1/platform/vaults/nonexistent-id')
            ->assertStatus(404);
    }

    // =========================================================================
    // Public Vault list — any authenticated user
    // =========================================================================

    public function test_authenticated_user_can_list_active_vaults(): void
    {
        // The listing is org-scoped (spec §2): only the caller's current
        // organization's active vaults appear.
        $org = Organization::factory()->create();
        $org->users()->attach($this->regularUser->id, ['role' => 'editor']);
        $this->regularUser->update(['last_organization_id' => $org->id]);

        Vault::factory()->count(2)->create(['state' => VaultState::PRIVATE->value, 'organization_id' => $org->id]);
        Vault::factory()->inactive()->create(['organization_id' => $org->id]);
        Vault::factory()->create(['state' => VaultState::PRIVATE->value]); // foreign org — must not appear

        $response = $this->withToken($this->regularToken)
            ->getJson('/api/v1/vaults');

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data.vaults');

        // The salt is the boundary's signing secret — never serialized.
        $this->assertStringNotContainsString('"salt"', $response->getContent());
    }

    public function test_org_admin_can_publish_a_vault(): void
    {
        $org = Organization::factory()->create();
        $org->users()->attach($this->regularUser->id, ['role' => 'admin']);
        $this->regularUser->update(['last_organization_id' => $org->id]);
        $vault = Vault::factory()->create(['organization_id' => $org->id, 'state' => VaultState::PRIVATE->value]);

        $this->withToken($this->regularToken)
            ->postJson("/api/v1/vaults/{$vault->id}/publish", ['state' => VaultState::PUBLIC->value])
            ->assertStatus(200);

        $this->assertSame(VaultState::PUBLIC, $vault->fresh()->state);
    }

    public function test_editor_cannot_publish_and_foreign_org_vault_is_invisible(): void
    {
        $org = Organization::factory()->create();
        $org->users()->attach($this->regularUser->id, ['role' => 'editor']);
        $this->regularUser->update(['last_organization_id' => $org->id]);

        $own = Vault::factory()->create(['organization_id' => $org->id, 'state' => VaultState::PRIVATE->value]);
        $foreign = Vault::factory()->create(['state' => VaultState::PRIVATE->value]);

        $this->withToken($this->regularToken)
            ->postJson("/api/v1/vaults/{$own->id}/publish", ['state' => VaultState::PUBLIC->value])
            ->assertStatus(403);

        $this->withToken($this->regularToken)
            ->postJson("/api/v1/vaults/{$foreign->id}/publish", ['state' => VaultState::PUBLIC->value])
            ->assertStatus(404);

        $this->assertSame(VaultState::PRIVATE, $own->fresh()->state);
        $this->assertSame(VaultState::PRIVATE, $foreign->fresh()->state);
    }

    public function test_platform_listing_carries_the_organization_and_no_salt(): void
    {
        Vault::factory()->create();

        $response = $this->withToken($this->superAdminToken)
            ->getJson('/api/v1/platform/vaults');

        $response->assertStatus(200)
            ->assertJsonStructure(['data' => ['vaults' => [['organization' => ['id', 'name', 'slug']]]]]);
        $this->assertStringNotContainsString('"salt"', $response->getContent());
    }

    public function test_guest_cannot_list_active_vaults(): void
    {
        $this->getJson('/api/v1/vaults')
            ->assertStatus(401);
    }

    // =========================================================================
    // Workspace Vault association
    // =========================================================================

    public function test_can_list_vaults_for_workspace(): void
    {
        $ws = Workspace::factory()->create();
        $vault = Vault::factory()->create();
        $ws->vaults()->attach($vault->id);

        // Superadmin bypasses all workspace policy checks via before()
        $this->withToken($this->superAdminToken)
            ->getJson("/api/v1/workspaces/{$ws->id}/vaults")
            ->assertStatus(200)
            ->assertJsonCount(1, 'data.vaults');
    }

    public function test_can_attach_vault_to_workspace(): void
    {
        $ws = Workspace::factory()->create();
        $vault = Vault::factory()->create(['organization_id' => $ws->organization_id]);

        $this->withToken($this->superAdminToken)
            ->postJson("/api/v1/workspaces/{$ws->id}/vaults", ['vault_id' => $vault->id])
            ->assertStatus(200)
            ->assertJson(['success' => true]);

        $this->assertDatabaseHas('workspace_vault', [
            'workspace_id' => $ws->id,
            'vault_id' => $vault->id,
        ]);
    }

    public function test_cannot_attach_vault_from_another_organization(): void
    {
        // Tenancy boundary: a cross-org attach would project one org's
        // resources through another org's public boundary.
        $ws = Workspace::factory()->create();
        $vault = Vault::factory()->create(); // its own (different) org

        $this->withToken($this->superAdminToken)
            ->postJson("/api/v1/workspaces/{$ws->id}/vaults", ['vault_id' => $vault->id])
            ->assertStatus(422);

        $this->assertDatabaseMissing('workspace_vault', [
            'workspace_id' => $ws->id,
            'vault_id' => $vault->id,
        ]);
    }

    public function test_can_detach_vault_from_workspace(): void
    {
        $ws = Workspace::factory()->create();
        $vault = Vault::factory()->create();
        $ws->vaults()->attach($vault->id);

        $this->withToken($this->superAdminToken)
            ->deleteJson("/api/v1/workspaces/{$ws->id}/vaults/{$vault->id}")
            ->assertStatus(200)
            ->assertJson(['success' => true]);

        $this->assertDatabaseMissing('workspace_vault', [
            'workspace_id' => $ws->id,
            'vault_id' => $vault->id,
        ]);
    }

    // =========================================================================
    // Resource Vault links — lazy generation
    // =========================================================================

    public function test_resource_vault_links_returns_structured_response(): void
    {
        $org = Organization::factory()->create();
        $org->users()->attach($this->regularUser->id, ['role' => 'admin']);
        $ws = Workspace::factory()->create(['organization_id' => $org->id, 'is_default' => false]);
        $vault = Vault::factory()->create(['salt' => 'test-salt']);
        $ws->vaults()->attach($vault->id);

        $resource = Resource::factory()->create(['organization_id' => $org->id]);
        $resource->workspaces()->attach($ws->id);

        $response = $this->withToken($this->regularToken)
            ->getJson("/api/v1/resources/{$resource->id}/vault-links");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'resource' => ['id', 'name', 'links'],
                    'files',
                ],
            ]);
    }

    public function test_resource_vault_links_are_idempotent(): void
    {
        $org = Organization::factory()->create();
        $org->users()->attach($this->regularUser->id, ['role' => 'admin']);
        $ws = Workspace::factory()->create(['organization_id' => $org->id, 'is_default' => false]);
        $vault = Vault::factory()->create(['salt' => 'stable-salt']);
        $ws->vaults()->attach($vault->id);

        $resource = Resource::factory()->create(['organization_id' => $org->id]);
        $resource->workspaces()->attach($ws->id);

        $first = $this->withToken($this->regularToken)
            ->getJson("/api/v1/resources/{$resource->id}/vault-links")
            ->json('data.resource.links.0.hash');

        $second = $this->withToken($this->regularToken)
            ->getJson("/api/v1/resources/{$resource->id}/vault-links")
            ->json('data.resource.links.0.hash');

        $this->assertNotNull($first);
        $this->assertSame($first, $second);
        $this->assertDatabaseCount('vault_links', 1);
    }

    public function test_resource_vault_links_empty_when_no_workspace_vault_association(): void
    {
        $resource = Resource::factory()->create();
        $resource->organization->users()->attach($this->regularUser->id, ['role' => 'admin']);

        $response = $this->withToken($this->regularToken)
            ->getJson("/api/v1/resources/{$resource->id}/vault-links");

        $response->assertStatus(200);
        $this->assertEmpty($response->json('data.resource.links'));
        $this->assertEmpty($response->json('data.files'));
    }

    public function test_resource_vault_links_require_auth(): void
    {
        $resource = Resource::factory()->create();

        $this->getJson("/api/v1/resources/{$resource->id}/vault-links")
            ->assertStatus(401);
    }

    public function test_resource_vault_links_denied_for_another_organizations_resource(): void
    {
        // Link hashes are public addresses: authentication alone must not
        // mint or reveal them for a resource the caller cannot see.
        $org = Organization::factory()->create();
        $ws = Workspace::factory()->create(['organization_id' => $org->id, 'is_default' => false]);
        $vault = Vault::factory()->create(['organization_id' => $org->id, 'salt' => 'foreign-salt']);
        $ws->vaults()->attach($vault->id);

        $resource = Resource::factory()->create(['organization_id' => $org->id]);
        $resource->workspaces()->attach($ws->id);

        $this->withToken($this->regularToken)
            ->getJson("/api/v1/resources/{$resource->id}/vault-links")
            ->assertStatus(404);

        // Nothing was minted on the way out.
        $this->assertDatabaseCount('vault_links', 0);
    }

    public function test_resource_vault_links_for_unknown_resource_is_not_found(): void
    {
        $this->withToken($this->regularToken)
            ->getJson('/api/v1/resources/'.Str::uuid7()->toString().'/vault-links')
            ->assertStatus(404);
    }

    public function test_public_workspace_vault_generates_link_via_default_workspace(): void
    {
        $org = Organization::factory()->create();
        $org->users()->attach($this->regularUser->id, ['role' => 'admin']);
        $defaultWorkspace = Workspace::factory()->create([
            'organization_id' => $org->id,
            'is_default' => true,
        ]);
        $publicVault = Vault::factory()->withPublicWorkspace()->create(['organization_id' => $org->id]);

        $resource = Resource::factory()->create(['organization_id' => $org->id]);
        // Resource is NOT explicitly attached to any workspace

        $response = $this->withToken($this->regularToken)
            ->getJson("/api/v1/resources/{$resource->id}/vault-links");

        $response->assertStatus(200);
        $links = $response->json('data.resource.links');
        $this->assertCount(1, $links);
        $this->assertEquals($publicVault->name, $links[0]['vault_name']);
        $this->assertEquals($defaultWorkspace->name, $links[0]['workspace_name']);
    }
}
