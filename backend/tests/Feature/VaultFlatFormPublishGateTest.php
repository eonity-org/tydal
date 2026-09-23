<?php

namespace Tests\Feature;

use App\Enums\FileRole;
use App\Enums\ResourceState;
use App\Enums\VaultPurpose;
use App\Enums\VaultState;
use App\Models\File;
use App\Models\Organization;
use App\Models\Resource;
use App\Models\Vault;
use App\Models\VaultKey;
use App\Models\Workspace;
use App\Services\VaultLinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The legacy flat address /vault/{hash} and the publish gate.
 *
 * `delivery` vaults keep their CDN semantics — the hash alone is the whole
 * credential, as it was before `is_published` existed. Projection purposes
 * (gallery/obsidian/ai/mixed) must answer to the same publish gate as the
 * /h/ and /v/ forms, or a private vault's works would be reachable by anyone
 * who ever saw a link hash.
 */
class VaultFlatFormPublishGateTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Workspace $ws;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organization::factory()->create(['slug' => 'acme']);
        $this->ws = Workspace::factory()->create(['organization_id' => $this->org->id]);
    }

    private function linkInVault(Vault $vault): string
    {
        DB::table('workspace_vault')->insert(['workspace_id' => $this->ws->id, 'vault_id' => $vault->id]);

        $resource = Resource::factory()->create(['organization_id' => $this->org->id, 'state' => ResourceState::LIVE->value]);
        DB::table('dam_resource_workspace')->insert([
            'resource_id' => $resource->id, 'workspace_id' => $this->ws->id,
        ]);
        File::factory()->for($resource)->create(['role' => FileRole::CANONICAL, 'filename' => 'work.jpg']);

        return app(VaultLinkService::class)->getOrCreateLink($vault, null, $resource->id, null)->hash;
    }

    public function test_flat_form_refuses_an_unpublished_projection_vault(): void
    {
        $vault = Vault::factory()->purpose(VaultPurpose::GALLERY)->create([
            'organization_id' => $this->org->id,
            'slug' => 'private-vault',
            'state' => VaultState::PRIVATE->value,
        ]);
        $hash = $this->linkInVault($vault);

        $this->get("/vault/{$hash}/info")->assertStatus(404);
        $this->get("/vault/{$hash}")->assertStatus(404);
        $this->get("/vault/{$hash}/download")->assertStatus(404);
    }

    public function test_flat_form_serves_a_published_projection_vault(): void
    {
        $vault = Vault::factory()->purpose(VaultPurpose::GALLERY)->published()->create([
            'organization_id' => $this->org->id,
            'slug' => 'open-vault',
        ]);
        $hash = $this->linkInVault($vault);

        $this->get("/vault/{$hash}/info")->assertStatus(200);
    }

    public function test_flat_form_accepts_a_vault_key_on_a_private_vault(): void
    {
        $vault = Vault::factory()->purpose(VaultPurpose::GALLERY)->create([
            'organization_id' => $this->org->id,
            'slug' => 'keyed-vault',
            'state' => VaultState::PRIVATE->value,
        ]);
        $hash = $this->linkInVault($vault);
        [, $plaintext] = VaultKey::mint($vault, 'test');

        $this->withHeader('X-Vault-Key', $plaintext)
            ->get("/vault/{$hash}/info")
            ->assertStatus(200);
    }

    public function test_delivery_vaults_keep_their_legacy_cdn_semantics(): void
    {
        // Unpublished, no key — the flat hash has always been enough here, and
        // existing CDN URLs must not break.
        $vault = Vault::factory()->purpose(VaultPurpose::DELIVERY)->create([
            'organization_id' => $this->org->id,
            'slug' => 'cdn-vault',
            'state' => VaultState::PRIVATE->value,
        ]);
        $hash = $this->linkInVault($vault);

        $this->get("/vault/{$hash}/info")->assertStatus(200);
    }
}
