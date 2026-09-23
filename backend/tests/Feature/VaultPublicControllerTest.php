<?php

namespace Tests\Feature;

use App\Enums\VaultState;
use App\Models\File;
use App\Models\Organization;
use App\Models\Resource;
use App\Models\Vault;
use App\Models\VaultLink;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class VaultPublicControllerTest extends TestCase
{
    use RefreshDatabase;

    private function makeLinkWithHash(array $vaultOverrides = [], array $linkOverrides = []): VaultLink
    {
        // Vault, workspace and resource share one org: the boundary pins the
        // projection to the vault's organization, so a mixed-org fixture is
        // not a shape production can produce.
        $org = Organization::factory()->create();
        $ws = Workspace::factory()->create(['organization_id' => $org->id]);
        $resource = Resource::factory()->create(['organization_id' => $org->id]);
        $vault = Vault::factory()->create(array_merge(['organization_id' => $org->id], $vaultOverrides));

        // Wire up required pivot associations for VaultLinkService::resolveHash
        DB::table('workspace_vault')->insert(['workspace_id' => $ws->id, 'vault_id' => $vault->id]);
        DB::table('dam_resource_workspace')->insert(['resource_id' => $resource->id, 'workspace_id' => $ws->id]);

        $linkKey = VaultLink::computeLinkKey($vault->id, (string) $ws->id, $resource->id, null);

        $link = VaultLink::create(array_merge([
            'vault_id' => $vault->id,
            'workspace_id' => $ws->id,
            'resource_id' => $resource->id,
            'file_id' => null,
            'link_key' => $linkKey,
            'hash' => 'TESTHASH',
            'expires_at' => null,
        ], $linkOverrides));

        return $link;
    }

    // =========================================================================
    // Resolve/info endpoint — GET /vault/{hash}/info
    // =========================================================================

    public function test_resolve_valid_resource_hash_returns_metadata(): void
    {
        $link = $this->makeLinkWithHash();

        $response = $this->getJson("/vault/{$link->hash}/info");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'type',
                'id',
                'name',
                'files',
                'expires_at',
            ])
            ->assertJsonPath('type', 'resource');
    }

    public function test_resolve_returns_404_for_unknown_hash(): void
    {
        $this->getJson('/vault/unknownhash/info')
            ->assertStatus(404)
            ->assertJson(['error' => 'Not found or expired']);
    }

    public function test_resolve_returns_404_for_expired_link(): void
    {
        $link = $this->makeLinkWithHash([], ['expires_at' => now()->subMinute()]);

        $this->getJson("/vault/{$link->hash}/info")
            ->assertStatus(404);
    }

    public function test_resolve_returns_404_when_vault_inactive(): void
    {
        $link = $this->makeLinkWithHash(['state' => VaultState::DISABLED->value]);

        $this->getJson("/vault/{$link->hash}/info")
            ->assertStatus(404);
    }

    public function test_resolve_returns_404_when_ip_not_in_allowlist(): void
    {
        $link = $this->makeLinkWithHash(['allowed_ips' => ['1.2.3.4', '5.6.7.8']]);

        // Default test client IP is 127.0.0.1 which is not in the list
        $this->getJson("/vault/{$link->hash}/info")
            ->assertStatus(404);
    }

    public function test_resolve_succeeds_when_ip_in_allowlist(): void
    {
        $link = $this->makeLinkWithHash(['allowed_ips' => ['127.0.0.1']]);

        $this->getJson("/vault/{$link->hash}/info")
            ->assertStatus(200);
    }

    public function test_resolve_succeeds_when_allowlist_is_empty(): void
    {
        $link = $this->makeLinkWithHash(['allowed_ips' => null]);

        $this->getJson("/vault/{$link->hash}/info")
            ->assertStatus(200);
    }

    public function test_resolve_does_not_require_authentication(): void
    {
        $link = $this->makeLinkWithHash();

        // No withToken call — this is a public endpoint
        $this->getJson("/vault/{$link->hash}/info")
            ->assertStatus(200);
    }

    public function test_resolve_file_hash_returns_file_metadata(): void
    {
        $org = Organization::factory()->create();
        $ws = Workspace::factory()->create(['organization_id' => $org->id]);
        $resource = Resource::factory()->create(['organization_id' => $org->id]);
        $file = File::factory()->for($resource)->create();
        $vault = Vault::factory()->create(['organization_id' => $org->id]);

        DB::table('workspace_vault')->insert(['workspace_id' => $ws->id, 'vault_id' => $vault->id]);
        DB::table('dam_resource_workspace')->insert(['resource_id' => $resource->id, 'workspace_id' => $ws->id]);

        $linkKey = VaultLink::computeLinkKey($vault->id, (string) $ws->id, $resource->id, $file->id);

        $link = VaultLink::create([
            'vault_id' => $vault->id,
            'workspace_id' => $ws->id,
            'resource_id' => $resource->id,
            'file_id' => $file->id,
            'link_key' => $linkKey,
            'hash' => 'FILEHASH1',
            'expires_at' => null,
        ]);

        $response = $this->getJson("/vault/{$link->hash}/info");

        // Boundary: the id is the vault-scoped link hash, never the file UUID.
        $response->assertStatus(200)
            ->assertJsonPath('type', 'file')
            ->assertJsonPath('id', $link->hash);
        $this->assertNotSame($file->id, $response->json('id'));
    }

    // =========================================================================
    // Download endpoint — GET /vault/{hash}/download
    // =========================================================================

    public function test_download_returns_403_when_vault_not_downloadable(): void
    {
        $link = $this->makeLinkWithHash(['is_downloadable' => false]);

        $this->getJson("/vault/{$link->hash}/download")
            ->assertStatus(403)
            ->assertJsonPath('error', 'Downloads are not enabled for this Vault');
    }

    public function test_download_returns_404_for_unknown_hash(): void
    {
        $this->getJson('/vault/unknown/download')
            ->assertStatus(404);
    }

    public function test_download_returns_404_for_expired_link(): void
    {
        $link = $this->makeLinkWithHash(
            ['is_downloadable' => true],
            ['expires_at' => now()->subMinute()]
        );

        $this->getJson("/vault/{$link->hash}/download")
            ->assertStatus(404);
    }
}
