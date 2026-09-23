<?php

namespace Tests\Feature;

use App\Enums\ResourceState;
use App\Enums\VaultPurpose;
use App\Models\Organization;
use App\Models\Resource;
use App\Models\User;
use App\Models\Vault;
use App\Models\VaultLink;
use App\Models\Workspace;
use App\Services\VaultLinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Two properties of the address space (VAULT_SYSTEM.md §4.2):
 *
 *  - one resource wears a DIFFERENT hash in every vault that projects it, so
 *    an address never identifies the thing itself, only the thing-as-seen-
 *    through-this-vault;
 *  - the default ("all resources") workspace is a computed membership, never
 *    materialized into the pivot table.
 */
class VaultAddressScopingTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Workspace $ws;

    private VaultLinkService $links;

    protected function setUp(): void
    {
        parent::setUp();
        $this->links = app(VaultLinkService::class);
        $this->org = Organization::factory()->create(['slug' => 'acme']);
        $this->ws = Workspace::factory()->create(['organization_id' => $this->org->id, 'is_default' => false]);
    }

    private function vault(string $slug): Vault
    {
        $vault = Vault::factory()->purpose(VaultPurpose::GALLERY)->published()->create([
            'organization_id' => $this->org->id,
            'slug' => $slug,
        ]);
        DB::table('workspace_vault')->insert(['workspace_id' => $this->ws->id, 'vault_id' => $vault->id]);

        return $vault;
    }

    // =========================================================================
    // One resource, one hash per vault
    // =========================================================================

    public function test_the_same_resource_gets_a_different_hash_in_each_vault(): void
    {
        $a = $this->vault('vault-a');
        $b = $this->vault('vault-b');

        $resource = Resource::factory()->create(['organization_id' => $this->org->id, 'state' => ResourceState::LIVE->value]);
        DB::table('dam_resource_workspace')->insert([
            'resource_id' => $resource->id, 'workspace_id' => $this->ws->id,
        ]);

        $inA = $this->links->getOrCreateLink($a, null, $resource->id, null);
        $inB = $this->links->getOrCreateLink($b, null, $resource->id, null);

        $this->assertNotSame($inA->hash, $inB->hash);
        $this->assertNotSame($inA->link_key, $inB->link_key);

        // Each address only answers inside its own vault — a hash borrowed
        // from the neighbouring vault is simply not there.
        $this->getJson("/h/{$a->hash}/{$inA->hash}/meta")->assertStatus(200);
        $this->getJson("/h/{$a->hash}/{$inB->hash}/meta")->assertStatus(404);
        $this->getJson("/h/{$b->hash}/{$inB->hash}/meta")->assertStatus(200);
        $this->getJson("/h/{$b->hash}/{$inA->hash}/meta")->assertStatus(404);
    }

    public function test_a_vaults_hashes_are_reminted_when_its_salt_rotates(): void
    {
        // The salt keys the hash, so rotating it moves the whole vault to a
        // fresh address space — the reason votes/state must key on the slug.
        $vault = $this->vault('rotating');
        $resource = Resource::factory()->create(['organization_id' => $this->org->id, 'state' => ResourceState::LIVE->value]);
        DB::table('dam_resource_workspace')->insert([
            'resource_id' => $resource->id, 'workspace_id' => $this->ws->id,
        ]);

        $before = $this->links->getOrCreateLink($vault, null, $resource->id, null)->hash;

        $superAdmin = User::factory()->superAdmin()->create();
        $this->withToken($superAdmin->createToken('t')->plainTextToken)
            ->postJson("/api/v1/platform/vaults/{$vault->id}/rotate-salt")
            ->assertStatus(200);

        $after = $this->links->getOrCreateLink($vault->fresh(), null, $resource->id, null)->hash;

        $this->assertNotSame($before, $after);
    }

    // =========================================================================
    // The default workspace is computed, not stored
    // =========================================================================

    public function test_default_workspace_membership_is_never_written_to_the_pivot(): void
    {
        $default = Workspace::factory()->create([
            'organization_id' => $this->org->id,
            'is_default' => true,
        ]);
        $vault = Vault::factory()->purpose(VaultPurpose::GALLERY)->published()->withPublicWorkspace()->create([
            'organization_id' => $this->org->id,
            'slug' => 'everything',
        ]);

        // Resources attached to NO workspace at all.
        Resource::factory()->count(3)->create(['organization_id' => $this->org->id, 'state' => ResourceState::LIVE->value]);

        // The vault projects them anyway…
        $this->getJson("/h/{$vault->hash}/resources")
            ->assertStatus(200)
            ->assertJsonCount(3, 'resources');

        // …without a single pivot row for the default workspace.
        $this->assertSame(0, DB::table('dam_resource_workspace')
            ->where('workspace_id', $default->id)
            ->count());
        $this->assertDatabaseCount('dam_resource_workspace', 0);
    }

    public function test_public_delivery_vault_never_reaches_another_organizations_resource(): void
    {
        // has_public_workspace means "this org's default workspace", not
        // "every org's". A foreign resource must neither mint an address here
        // nor resolve one.
        $foreignOrg = Organization::factory()->create();
        Workspace::factory()->create(['organization_id' => $foreignOrg->id, 'is_default' => true]);
        Workspace::factory()->create(['organization_id' => $this->org->id, 'is_default' => true]);

        $vault = Vault::factory()->purpose(VaultPurpose::DELIVERY)->withPublicWorkspace()->create([
            'organization_id' => $this->org->id,
            'slug' => 'cdn',
        ]);

        $foreign = Resource::factory()->create(['organization_id' => $foreignOrg->id, 'state' => ResourceState::LIVE->value]);

        $this->links->getOrCreateLinksForResource($foreign->id);

        $this->assertSame(0, VaultLink::where('vault_id', $vault->id)->count());
    }
}
