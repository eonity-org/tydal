<?php

namespace Tests\Feature;

use App\Enums\FileRole;
use App\Enums\ResourceState;
use App\Enums\VaultPurpose;
use App\Enums\VaultState;
use App\Models\File;
use App\Models\Organization;
use App\Models\Resource;
use App\Models\User;
use App\Models\Vault;
use App\Models\Workspace;
use App\Services\VaultLinkService;
use App\Services\VaultSignatureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Epic 5.4 — signed grants: time-limited URLs (`?sig=&exp=`) that open a
 * PRIVATE vault (or exactly one of its links) without a vault key. Keyed by
 * the vault salt, so salt rotation revokes everything at once. Active + IP
 * policy are never bypassed.
 */
class VaultSignedUrlTest extends TestCase
{
    use RefreshDatabase;

    private VaultLinkService $links;

    private VaultSignatureService $signatures;

    private Organization $org;

    private Workspace $ws;

    private Vault $vault;

    protected function setUp(): void
    {
        parent::setUp();
        $this->links = app(VaultLinkService::class);
        $this->signatures = app(VaultSignatureService::class);
        $this->org = Organization::factory()->create(['slug' => 'acme']);
        $this->ws = Workspace::factory()->create(['organization_id' => $this->org->id]);

        // PRIVATE vault throughout — grants are the only way in.
        $this->vault = Vault::factory()->purpose(VaultPurpose::GALLERY)->create([
            'organization_id' => $this->org->id,
            'slug' => 'private-expo',
            'state' => VaultState::PRIVATE->value,
        ]);
        DB::table('workspace_vault')->insert(['workspace_id' => $this->ws->id, 'vault_id' => $this->vault->id]);
    }

    private function addResource(string $name): Resource
    {
        $resource = Resource::factory()->create([
            'organization_id' => $this->org->id,
            'name' => $name,
            'state' => ResourceState::LIVE->value,
        ]);
        DB::table('dam_resource_workspace')->insert(['resource_id' => $resource->id, 'workspace_id' => $this->ws->id]);

        return $resource;
    }

    private function vaultGrant(int $hours = 1): string
    {
        $g = $this->signatures->sign($this->vault, null, now()->addHours($hours));

        return "sig={$g['sig']}&exp={$g['exp']}";
    }

    // =========================================================================
    // Vault-scope grants
    // =========================================================================

    public function test_vault_grant_opens_the_whole_surface_without_a_key(): void
    {
        $this->addResource('Winter Catalogue');

        $this->getJson('/h/'.$this->vault->hash.'/meta')->assertStatus(404);
        $this->getJson('/h/'.$this->vault->hash.'/meta?'.$this->vaultGrant())->assertStatus(200);
        $this->getJson('/h/'.$this->vault->hash.'/resources?'.$this->vaultGrant())
            ->assertStatus(200)
            ->assertJsonPath('resources.0.name', 'Winter Catalogue');
    }

    public function test_vault_grant_works_on_the_human_form_too(): void
    {
        $this->getJson('/v/acme/private-expo/meta')->assertStatus(404);
        $this->getJson('/v/acme/private-expo/meta?'.$this->vaultGrant())->assertStatus(200);
    }

    public function test_expired_or_tampered_grants_are_rejected(): void
    {
        $expired = $this->signatures->sign($this->vault, null, now()->subMinute());
        $this->getJson('/h/'.$this->vault->hash."/meta?sig={$expired['sig']}&exp={$expired['exp']}")
            ->assertStatus(404);

        $valid = $this->signatures->sign($this->vault, null, now()->addHour());
        // Extending the expiry without re-signing must fail.
        $later = $valid['exp'] + 3600;
        $this->getJson('/h/'.$this->vault->hash."/meta?sig={$valid['sig']}&exp={$later}")
            ->assertStatus(404);
        $this->getJson('/h/'.$this->vault->hash.'/meta?sig=forged&exp='.$valid['exp'])
            ->assertStatus(404);
    }

    public function test_salt_rotation_revokes_outstanding_grants(): void
    {
        $grant = $this->vaultGrant();
        $this->getJson('/h/'.$this->vault->hash.'/meta?'.$grant)->assertStatus(200);

        $this->vault->update(['salt' => 'rotated-salt-revokes-all']);

        $this->getJson('/h/'.$this->vault->hash.'/meta?'.$grant)->assertStatus(404);
    }

    public function test_revoking_grants_bumps_epoch_and_spares_links(): void
    {
        $resource = $this->addResource('Kept Piece');
        $link = $this->links->getOrCreateLink($this->vault, null, $resource->id, null);
        $originalHash = $link->hash;

        $grant = $this->vaultGrant();
        $this->getJson('/h/'.$this->vault->hash.'/meta?'.$grant)->assertStatus(200);

        // Light revoke: bump grant_epoch — grants die, no link is purged.
        $token = User::factory()->superAdmin()->create()->createToken('t')->plainTextToken;
        $this->withToken($token)
            ->postJson("/api/v1/platform/vaults/{$this->vault->id}/revoke-grants")
            ->assertStatus(200)
            ->assertJsonPath('data.grant_epoch', 1);

        // The old grant no longer verifies…
        $this->getJson('/h/'.$this->vault->hash.'/meta?'.$grant)->assertStatus(404);

        // …but the link row survives unchanged (salt rotation would have purged it)…
        $this->assertDatabaseHas('vault_links', ['id' => $link->id, 'hash' => $originalHash]);

        // …and a freshly minted grant (signed at the new epoch) works again.
        $this->vault->refresh();
        $this->getJson('/h/'.$this->vault->hash.'/meta?'.$this->vaultGrant())->assertStatus(200);
    }

    public function test_regular_users_cannot_revoke_grants(): void
    {
        $token = User::factory()->create()->createToken('t')->plainTextToken;

        $this->withToken($token)
            ->postJson("/api/v1/platform/vaults/{$this->vault->id}/revoke-grants")
            ->assertStatus(403);
    }

    public function test_grants_never_bypass_active_or_ip_policy(): void
    {
        $grant = $this->vaultGrant();

        $this->vault->update(['state' => VaultState::DISABLED->value]);
        $this->getJson('/h/'.$this->vault->hash.'/meta?'.$grant)->assertStatus(404);

        $this->vault->update(['state' => VaultState::PRIVATE->value, 'allowed_ips' => ['203.0.113.7']]);
        $this->getJson('/h/'.$this->vault->hash.'/meta?'.$grant)->assertStatus(404);
    }

    // =========================================================================
    // Link-scope grants
    // =========================================================================

    public function test_link_grant_opens_exactly_that_address_and_its_files(): void
    {
        $shared = $this->addResource('Shared Piece');
        $other = $this->addResource('Other Piece');
        File::factory()->for($shared)->create(['role' => FileRole::CANONICAL, 'filename' => 'piece.jpg', 'mime_type' => 'image/jpeg']);

        $sharedLink = $this->links->getOrCreateLink($this->vault, null, $shared->id, null);
        $otherLink = $this->links->getOrCreateLink($this->vault, null, $other->id, null);
        $fileLink = $this->links->getOrCreateLink($this->vault, null, $shared->id, $shared->files()->first()->id);

        $g = $this->signatures->sign($this->vault, $sharedLink->hash, now()->addHour());
        $qs = "sig={$g['sig']}&exp={$g['exp']}";

        // The shared resource and its file addresses open…
        $this->getJson('/h/'.$this->vault->hash.'/'.$sharedLink->hash.'/meta?'.$qs)->assertStatus(200);
        $this->getJson('/h/'.$this->vault->hash.'/'.$fileLink->hash.'/meta?'.$qs)->assertStatus(200);

        // …but neither sibling links nor the vault surface do.
        $this->getJson('/h/'.$this->vault->hash.'/'.$otherLink->hash.'/meta?'.$qs)->assertStatus(404);
        $this->getJson('/h/'.$this->vault->hash.'/meta?'.$qs)->assertStatus(404);
        $this->getJson('/h/'.$this->vault->hash.'/resources?'.$qs)->assertStatus(404);
    }

    // =========================================================================
    // Minting endpoint
    // =========================================================================

    public function test_superadmin_mints_working_signed_urls(): void
    {
        $token = User::factory()->superAdmin()->create()->createToken('t')->plainTextToken;

        $data = $this->withToken($token)
            ->postJson("/api/v1/platform/vaults/{$this->vault->id}/signed-urls", ['expires_in_hours' => 24])
            ->assertStatus(201)
            ->json('data');

        $this->assertSame('vault', $data['scope']);

        $path = parse_url($data['url'], PHP_URL_PATH).'?'.parse_url($data['url'], PHP_URL_QUERY);
        $this->getJson($path.'&_probe=meta')->assertStatus(200); // entry resolves
        $this->getJson('/h/'.$this->vault->hash.'/meta?sig='.$data['sig'].'&exp='.$data['exp'])
            ->assertStatus(200);
    }

    public function test_minting_validates_link_and_expiry(): void
    {
        $token = User::factory()->superAdmin()->create()->createToken('t')->plainTextToken;

        $this->withToken($token)
            ->postJson("/api/v1/platform/vaults/{$this->vault->id}/signed-urls", ['expires_in_hours' => 0])
            ->assertStatus(422);

        $this->withToken($token)
            ->postJson("/api/v1/platform/vaults/{$this->vault->id}/signed-urls", ['expires_in_hours' => 1, 'link_hash' => 'nope'])
            ->assertStatus(404);
    }

    public function test_regular_users_cannot_mint(): void
    {
        $token = User::factory()->create()->createToken('t')->plainTextToken;

        $this->withToken($token)
            ->postJson("/api/v1/platform/vaults/{$this->vault->id}/signed-urls", ['expires_in_hours' => 1])
            ->assertStatus(403);
    }
}
