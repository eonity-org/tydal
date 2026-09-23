<?php

namespace Tests\Feature;

use App\Enums\FileRole;
use App\Enums\VaultPurpose;
use App\Enums\VaultState;
use App\Models\File;
use App\Models\Organization;
use App\Models\Resource;
use App\Models\Vault;
use App\Models\VaultLink;
use App\Models\Workspace;
use App\Services\VaultLinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Epic 2.2 — vault-first /h/{vaultHash}/{linkHash} resolution, purpose-aware
 * link keys, role-filtered minting, and cached vault resolution.
 */
class VaultHashRouteTest extends TestCase
{
    use RefreshDatabase;

    private VaultLinkService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(VaultLinkService::class);
    }

    /**
     * A published ai-purpose vault projecting one resource through one
     * workspace, with a workspace-free link minted for the resource.
     *
     * @return array{vault: Vault, resource: resource, workspace: Workspace, link: VaultLink}
     */
    private function makeProjection(array $vaultOverrides = []): array
    {
        $org = Organization::factory()->create();
        $ws = Workspace::factory()->create(['organization_id' => $org->id]);
        $resource = Resource::factory()->create(['organization_id' => $org->id]);
        $vault = Vault::factory()->purpose(VaultPurpose::AI)->published()->create(
            array_merge(['organization_id' => $org->id], $vaultOverrides)
        );

        DB::table('workspace_vault')->insert(['workspace_id' => $ws->id, 'vault_id' => $vault->id]);
        DB::table('dam_resource_workspace')->insert(['resource_id' => $resource->id, 'workspace_id' => $ws->id]);

        $link = $this->service->getOrCreateLink($vault, null, $resource->id, null);

        return ['vault' => $vault, 'resource' => $resource, 'workspace' => $ws, 'link' => $link];
    }

    // =========================================================================
    // Purpose-aware link keys
    // =========================================================================

    public function test_vault_purpose_link_is_workspace_free(): void
    {
        ['link' => $link, 'vault' => $vault, 'resource' => $resource] = $this->makeProjection();

        $this->assertNull($link->workspace_id);
        $this->assertSame(
            hash('sha256', "{$vault->id}:{$resource->id}:null"),
            $link->link_key
        );
    }

    public function test_same_resource_via_two_workspaces_gets_one_address(): void
    {
        ['vault' => $vault, 'resource' => $resource, 'workspace' => $ws] = $this->makeProjection();

        // Attach the resource through a second workspace on the same vault
        $ws2 = Workspace::factory()->create(['organization_id' => $vault->organization_id]);
        DB::table('workspace_vault')->insert(['workspace_id' => $ws2->id, 'vault_id' => $vault->id]);
        DB::table('dam_resource_workspace')->insert(['resource_id' => $resource->id, 'workspace_id' => $ws2->id]);

        $result = $this->service->getOrCreateLinksForResource($resource->id);

        $vaultLinks = array_filter(
            $result['resource']['links'],
            fn (array $l) => $l['vault_id'] === $vault->id
        );

        $this->assertCount(1, $vaultLinks);
    }

    public function test_delivery_link_key_form_is_unchanged(): void
    {
        $this->assertSame(
            hash('sha256', 'v1:7:r1:null'),
            VaultLink::computeLinkKey('v1', '7', 'r1', null)
        );
    }

    // =========================================================================
    // Role-filtered minting
    // =========================================================================

    public function test_supporting_files_are_not_addressed_by_vault_purposes(): void
    {
        ['vault' => $vault, 'resource' => $resource] = $this->makeProjection();

        $canonical = File::factory()->for($resource)->create(['role' => FileRole::CANONICAL]);
        $supporting = File::factory()->for($resource)->create(['role' => FileRole::SUPPORTING]);

        $result = $this->service->getOrCreateLinksForResource($resource->id);
        $byFile = collect($result['files'])->keyBy('id');

        $this->assertNotEmpty($byFile[$canonical->id]['links']);
        $this->assertEmpty($byFile[$supporting->id]['links']);
    }

    public function test_exposure_policy_can_widen_addressed_roles(): void
    {
        ['vault' => $vault, 'resource' => $resource] = $this->makeProjection([
            'exposure_policy' => ['address_roles' => ['canonical', 'component', 'supporting']],
        ]);

        $supporting = File::factory()->for($resource)->create(['role' => FileRole::SUPPORTING]);

        $result = $this->service->getOrCreateLinksForResource($resource->id);
        $byFile = collect($result['files'])->keyBy('id');

        $this->assertNotEmpty($byFile[$supporting->id]['links']);
    }

    // =========================================================================
    // /h/{vaultHash}/{linkHash} resolution
    // =========================================================================

    public function test_h_route_serves_info_for_published_vault(): void
    {
        ['vault' => $vault, 'link' => $link, 'resource' => $resource] = $this->makeProjection();

        // Boundary: id is the vault-scoped link hash, never the resource UUID.
        $response = $this->getJson("/h/{$vault->hash}/{$link->hash}/info")
            ->assertStatus(200)
            ->assertJsonPath('type', 'resource')
            ->assertJsonPath('id', $link->hash);
        $this->assertNotSame($resource->id, $response->json('id'));
    }

    public function test_h_route_link_lookup_is_scoped_to_the_vault(): void
    {
        ['link' => $link] = $this->makeProjection();
        ['vault' => $otherVault] = $this->makeProjection();

        // Valid link hash under the WRONG vault hash must not resolve
        $this->getJson("/h/{$otherVault->hash}/{$link->hash}/info")
            ->assertStatus(404);
    }

    public function test_h_route_rejects_unpublished_vault(): void
    {
        ['vault' => $vault, 'link' => $link] = $this->makeProjection();
        $vault->update(['state' => VaultState::PRIVATE->value]);

        $this->getJson("/h/{$vault->hash}/{$link->hash}/info")
            ->assertStatus(404);
    }

    public function test_h_route_rejects_inactive_vault(): void
    {
        ['vault' => $vault, 'link' => $link] = $this->makeProjection();
        $vault->update(['state' => VaultState::DISABLED->value]);

        $this->getJson("/h/{$vault->hash}/{$link->hash}/info")
            ->assertStatus(404);
    }

    public function test_h_route_enforces_ip_allowlist(): void
    {
        ['vault' => $vault, 'link' => $link] = $this->makeProjection([
            'allowed_ips' => ['10.9.9.9'],
        ]);

        $this->getJson("/h/{$vault->hash}/{$link->hash}/info")
            ->assertStatus(404);
    }

    public function test_h_route_revocation_is_implicit(): void
    {
        ['vault' => $vault, 'link' => $link, 'resource' => $resource, 'workspace' => $ws] = $this->makeProjection();

        // Removing the resource from the only vault-linked workspace kills the address
        DB::table('dam_resource_workspace')
            ->where('resource_id', $resource->id)
            ->where('workspace_id', $ws->id)
            ->delete();

        $this->getJson("/h/{$vault->hash}/{$link->hash}/info")
            ->assertStatus(404);
    }

    public function test_public_workspace_vault_serves_same_org_resource_only(): void
    {
        $org = Organization::factory()->create();
        $vault = Vault::factory()->purpose(VaultPurpose::GALLERY)->published()->create([
            'organization_id' => $org->id,
            'has_public_workspace' => true,
        ]);

        $sameOrgResource = Resource::factory()->create(['organization_id' => $org->id]);
        $crossOrgResource = Resource::factory()->create(); // different org

        $sameOrgLink = $this->service->getOrCreateLink($vault, null, $sameOrgResource->id, null);
        $crossOrgLink = $this->service->getOrCreateLink($vault, null, $crossOrgResource->id, null);

        $this->getJson("/h/{$vault->hash}/{$sameOrgLink->hash}/info")->assertStatus(200);
        $this->getJson("/h/{$vault->hash}/{$crossOrgLink->hash}/info")->assertStatus(404);
    }

    public function test_h_route_download_respects_is_downloadable(): void
    {
        ['vault' => $vault, 'link' => $link] = $this->makeProjection(['is_downloadable' => false]);

        $this->getJson("/h/{$vault->hash}/{$link->hash}/download")
            ->assertStatus(403);
    }

    // =========================================================================
    // buildUrl per purpose
    // =========================================================================

    public function test_build_url_uses_h_form_for_vault_purposes(): void
    {
        ['vault' => $vault, 'link' => $link] = $this->makeProjection();

        $url = $this->service->buildUrl($link->fresh()->load('vault'));

        $this->assertStringContainsString("/h/{$vault->hash}/{$link->hash}", $url);
    }

    // =========================================================================
    // Cached vault resolution
    // =========================================================================

    public function test_vault_hash_resolution_is_cached_and_invalidated_on_update(): void
    {
        ['vault' => $vault, 'link' => $link] = $this->makeProjection();

        // Prime the cache
        $this->getJson("/h/{$vault->hash}/{$link->hash}/info")->assertStatus(200);
        $this->assertNotNull(Cache::get("vault:hash:{$vault->hash}"));

        // Policy change must invalidate the cached vault immediately
        $vault->update(['state' => VaultState::DISABLED->value]);
        $this->assertNull(Cache::get("vault:hash:{$vault->hash}"));

        $this->getJson("/h/{$vault->hash}/{$link->hash}/info")->assertStatus(404);
    }
}
