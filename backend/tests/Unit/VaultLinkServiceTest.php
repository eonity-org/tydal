<?php

namespace Tests\Unit;

use App\Models\File;
use App\Models\Organization;
use App\Models\Resource;
use App\Models\Vault;
use App\Models\VaultLink;
use App\Models\Workspace;
use App\Services\VaultLinkService;
use Hashids\Hashids;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class VaultLinkServiceTest extends TestCase
{
    use RefreshDatabase;

    private VaultLinkService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(VaultLinkService::class);
    }

    // =========================================================================
    // getOrCreateLink — hash generation & idempotency
    // =========================================================================

    public function test_get_or_create_link_generates_hash(): void
    {
        $vault = Vault::factory()->create(['salt' => 'known-salt']);
        $ws = Workspace::factory()->create();
        $res = Resource::factory()->create();

        $link = $this->service->getOrCreateLink($vault, $ws, $res->id, null);

        $this->assertNotNull($link->hash);
        $this->assertNotEmpty($link->hash);
    }

    public function test_hash_is_correctly_derived_from_link_id_and_salt(): void
    {
        $salt = 'deterministic-salt-42';
        $vault = Vault::factory()->create(['salt' => $salt]);
        $ws = Workspace::factory()->create();
        $res = Resource::factory()->create();

        $link = $this->service->getOrCreateLink($vault, $ws, $res->id, null);

        $expected = (new Hashids($salt, 8))->encode($link->id);
        $this->assertSame($expected, $link->hash);
    }

    public function test_get_or_create_link_is_idempotent(): void
    {
        $vault = Vault::factory()->create();
        $ws = Workspace::factory()->create();
        $res = Resource::factory()->create();

        $first = $this->service->getOrCreateLink($vault, $ws, $res->id, null);
        $second = $this->service->getOrCreateLink($vault, $ws, $res->id, null);

        $this->assertSame($first->id, $second->id);
        $this->assertSame($first->hash, $second->hash);
        $this->assertDatabaseCount('vault_links', 1);
    }

    public function test_resource_link_and_file_link_are_separate_records(): void
    {
        $vault = Vault::factory()->create();
        $ws = Workspace::factory()->create();
        $res = Resource::factory()->create();
        $file = File::factory()->for($res)->create();

        $resourceLink = $this->service->getOrCreateLink($vault, $ws, $res->id, null);
        $fileLink = $this->service->getOrCreateLink($vault, $ws, $res->id, $file->id);

        $this->assertNotSame($resourceLink->id, $fileLink->id);
        $this->assertDatabaseCount('vault_links', 2);
    }

    public function test_link_key_is_computed_deterministically(): void
    {
        $vaultId = 'vault-uuid-123';
        $wsId = '42';
        $resourceId = 'res-uuid-456';

        $keyA = VaultLink::computeLinkKey($vaultId, $wsId, $resourceId, null);
        $keyB = VaultLink::computeLinkKey($vaultId, $wsId, $resourceId, null);

        $this->assertSame($keyA, $keyB);
    }

    public function test_link_key_differs_for_null_vs_non_null_file(): void
    {
        $vaultId = 'vault-uuid-123';
        $wsId = '42';
        $resourceId = 'res-uuid-456';

        $keyWithNull = VaultLink::computeLinkKey($vaultId, $wsId, $resourceId, null);
        $keyWithFileId = VaultLink::computeLinkKey($vaultId, $wsId, $resourceId, 'file-uuid-789');

        $this->assertNotSame($keyWithNull, $keyWithFileId);
    }

    public function test_ttl_sets_expires_at_correctly(): void
    {
        $vault = Vault::factory()->withTtl(24)->create();
        $ws = Workspace::factory()->create();
        $res = Resource::factory()->create();

        $link = $this->service->getOrCreateLink($vault, $ws, $res->id, null);

        $this->assertNotNull($link->expires_at);
        $this->assertFalse($link->isExpired());
        // Should be roughly 24h from now (within a minute margin)
        $this->assertEqualsWithDelta(
            now()->addHours(24)->timestamp,
            $link->expires_at->timestamp,
            60
        );
    }

    public function test_null_ttl_sets_no_expiry(): void
    {
        $vault = Vault::factory()->create(['hash_ttl_hours' => null]);
        $ws = Workspace::factory()->create();
        $res = Resource::factory()->create();

        $link = $this->service->getOrCreateLink($vault, $ws, $res->id, null);

        $this->assertNull($link->expires_at);
        $this->assertFalse($link->isExpired());
    }

    // =========================================================================
    // isExpired()
    // =========================================================================

    public function test_is_expired_returns_true_for_past_expiry(): void
    {
        $link = VaultLink::factory()->expired()->create();

        $this->assertTrue($link->isExpired());
    }

    public function test_is_expired_returns_false_for_future_expiry(): void
    {
        $link = VaultLink::factory()->expiresIn(24)->create();

        $this->assertFalse($link->isExpired());
    }

    public function test_is_expired_returns_false_when_no_expiry(): void
    {
        $link = VaultLink::factory()->create(['expires_at' => null]);

        $this->assertFalse($link->isExpired());
    }

    // =========================================================================
    // purgeLinksForVault
    // =========================================================================

    public function test_purge_links_for_vault_deletes_all_matching_links(): void
    {
        $vaultA = Vault::factory()->create();
        $vaultB = Vault::factory()->create();

        VaultLink::factory()->count(3)->create(['vault_id' => $vaultA->id]);
        VaultLink::factory()->count(2)->create(['vault_id' => $vaultB->id]);

        $deleted = $this->service->purgeLinksForVault($vaultA->id);

        $this->assertSame(3, $deleted);
        $this->assertDatabaseCount('vault_links', 2);
        $this->assertDatabaseMissing('vault_links', ['vault_id' => $vaultA->id]);
    }

    public function test_purge_links_for_vault_returns_zero_when_nothing_to_delete(): void
    {
        $vault = Vault::factory()->create();

        $deleted = $this->service->purgeLinksForVault($vault->id);

        $this->assertSame(0, $deleted);
    }

    // =========================================================================
    // Epic 2.1 schema delta — vault links without workspace, per-vault slugs
    // =========================================================================

    public function test_vault_link_can_exist_without_workspace(): void
    {
        $vault = Vault::factory()->create();
        $res = Resource::factory()->create();

        $link = VaultLink::create([
            'vault_id' => $vault->id,
            'workspace_id' => null,
            'resource_id' => $res->id,
            'file_id' => null,
            'link_key' => hash('sha256', 'vault-link-no-ws'),
        ]);

        $this->assertNull($link->fresh()->workspace_id);
    }

    public function test_link_slug_is_unique_per_vault_but_reusable_across_vaults(): void
    {
        $vaultA = Vault::factory()->create();
        $vaultB = Vault::factory()->create();
        $res = Resource::factory()->create();

        VaultLink::create([
            'vault_id' => $vaultA->id,
            'workspace_id' => null,
            'resource_id' => $res->id,
            'link_key' => hash('sha256', 'slug-a'),
            'slug' => 'my-resource',
        ]);

        // Same slug in a different vault is fine
        VaultLink::create([
            'vault_id' => $vaultB->id,
            'workspace_id' => null,
            'resource_id' => $res->id,
            'link_key' => hash('sha256', 'slug-b'),
            'slug' => 'my-resource',
        ]);

        $this->assertDatabaseCount('vault_links', 2);

        // Duplicate slug within the same vault violates the unique index
        $this->expectException(QueryException::class);
        VaultLink::create([
            'vault_id' => $vaultA->id,
            'workspace_id' => null,
            'resource_id' => $res->id,
            'link_key' => hash('sha256', 'slug-a2'),
            'slug' => 'my-resource',
        ]);
    }

    // =========================================================================
    // buildUrl
    // =========================================================================

    public function test_build_url_uses_base_url_when_set(): void
    {
        $vault = Vault::factory()->withBaseUrl('https://vault.example.com')->create();
        $ws = Workspace::factory()->create();
        $res = Resource::factory()->create();

        $link = $this->service->getOrCreateLink($vault, $ws, $res->id, null);

        $url = $this->service->buildUrl($link->fresh()->load('vault'));

        $this->assertStringStartsWith('https://vault.example.com/vault/', $url);
        $this->assertStringContainsString($link->hash, $url);
    }

    public function test_build_url_falls_back_to_route_when_no_base_url(): void
    {
        $vault = Vault::factory()->create(['base_url' => null]);
        $ws = Workspace::factory()->create();
        $res = Resource::factory()->create();

        $link = $this->service->getOrCreateLink($vault, $ws, $res->id, null);

        $url = $this->service->buildUrl($link->fresh()->load('vault'));

        $this->assertStringContainsString('/vault/', $url);
        $this->assertStringContainsString($link->hash, $url);
    }

    // =========================================================================
    // resolveHash
    // =========================================================================

    public function test_resolve_hash_returns_link_for_valid_hash(): void
    {
        // One org across vault/workspace/resource — the boundary pins them.
        $org = Organization::factory()->create();
        $vault = Vault::factory()->create(['allowed_ips' => null, 'organization_id' => $org->id]);
        $ws = Workspace::factory()->create(['organization_id' => $org->id]);
        $res = Resource::factory()->create(['organization_id' => $org->id]);

        DB::table('workspace_vault')->insert(['workspace_id' => $ws->id, 'vault_id' => $vault->id]);
        DB::table('dam_resource_workspace')->insert(['resource_id' => $res->id, 'workspace_id' => $ws->id]);

        $link = $this->service->getOrCreateLink($vault, $ws, $res->id, null);

        $resolved = $this->service->resolveHash($link->hash, '1.2.3.4');

        $this->assertNotNull($resolved);
        $this->assertSame($link->id, $resolved->id);
    }

    public function test_resolve_hash_returns_null_for_unknown_hash(): void
    {
        $result = $this->service->resolveHash('nonexistenthash', '1.2.3.4');

        $this->assertNull($result);
    }

    public function test_resolve_hash_returns_null_for_expired_link(): void
    {
        $vault = Vault::factory()->create();
        $ws = Workspace::factory()->create();
        $res = Resource::factory()->create();

        // Create an already-expired link directly
        $linkKey = VaultLink::computeLinkKey($vault->id, (string) $ws->id, $res->id, null);
        VaultLink::create([
            'vault_id' => $vault->id,
            'workspace_id' => $ws->id,
            'resource_id' => $res->id,
            'file_id' => null,
            'link_key' => $linkKey,
            'hash' => 'expiredhash',
            'expires_at' => now()->subHour(),
        ]);

        $result = $this->service->resolveHash('expiredhash', '1.2.3.4');

        $this->assertNull($result);
    }

    public function test_resolve_hash_returns_null_for_inactive_vault(): void
    {
        $vault = Vault::factory()->inactive()->create();
        $ws = Workspace::factory()->create();
        $res = Resource::factory()->create();

        $linkKey = VaultLink::computeLinkKey($vault->id, (string) $ws->id, $res->id, null);
        VaultLink::create([
            'vault_id' => $vault->id,
            'workspace_id' => $ws->id,
            'resource_id' => $res->id,
            'file_id' => null,
            'link_key' => $linkKey,
            'hash' => 'inactivevaulthash',
            'expires_at' => null,
        ]);

        $result = $this->service->resolveHash('inactivevaulthash', '1.2.3.4');

        $this->assertNull($result);
    }

    public function test_resolve_hash_returns_null_when_ip_not_allowed(): void
    {
        $vault = Vault::factory()->withAllowedIps(['10.0.0.1'])->create();
        $ws = Workspace::factory()->create();
        $res = Resource::factory()->create();

        $linkKey = VaultLink::computeLinkKey($vault->id, (string) $ws->id, $res->id, null);
        VaultLink::create([
            'vault_id' => $vault->id,
            'workspace_id' => $ws->id,
            'resource_id' => $res->id,
            'file_id' => null,
            'link_key' => $linkKey,
            'hash' => 'ipblockedhash',
            'expires_at' => null,
        ]);

        $result = $this->service->resolveHash('ipblockedhash', '9.9.9.9');

        $this->assertNull($result);
    }

    public function test_resolve_hash_succeeds_when_ip_allowed(): void
    {
        $org = Organization::factory()->create();
        $vault = Vault::factory()->withAllowedIps(['9.9.9.9'])->create(['organization_id' => $org->id]);
        $ws = Workspace::factory()->create(['organization_id' => $org->id]);
        $res = Resource::factory()->create(['organization_id' => $org->id]);

        DB::table('workspace_vault')->insert(['workspace_id' => $ws->id, 'vault_id' => $vault->id]);
        DB::table('dam_resource_workspace')->insert(['resource_id' => $res->id, 'workspace_id' => $ws->id]);

        $linkKey = VaultLink::computeLinkKey($vault->id, (string) $ws->id, $res->id, null);
        VaultLink::create([
            'vault_id' => $vault->id,
            'workspace_id' => $ws->id,
            'resource_id' => $res->id,
            'file_id' => null,
            'link_key' => $linkKey,
            'hash' => 'ipalloweddhash',
            'expires_at' => null,
        ]);

        $result = $this->service->resolveHash('ipalloweddhash', '9.9.9.9');

        $this->assertNotNull($result);
    }
}
