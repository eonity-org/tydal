<?php

namespace Tests\Feature;

use App\Enums\ResourceState;
use App\Enums\VaultPurpose;
use App\Enums\VaultState;
use App\Models\Organization;
use App\Models\Resource;
use App\Models\Vault;
use App\Models\VaultKey;
use App\Models\VaultLink;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Vault write methods (VAULT_WRITE_METHODS.md) — the inbound boundary. A
 * gallery vault accepts activate/open/close through a write-capable vault key;
 * everything else is refused at the same silent 404/403 the read side uses.
 */
class VaultWriteMethodsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Workspace $submissions;

    private Vault $vault;

    /** @var array<string, resource> hash => resource */
    private array $works = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create(['slug' => 'acme']);
        $this->submissions = Workspace::factory()->create(['organization_id' => $this->org->id]);
        $this->vault = Vault::factory()->purpose(VaultPurpose::GALLERY)->create([
            'organization_id' => $this->org->id,
            'slug' => 'show',
            'state' => VaultState::PRIVATE->value,
        ]);

        DB::table('workspace_vault')->insert(['workspace_id' => $this->submissions->id, 'vault_id' => $this->vault->id]);

        foreach (['LINKAAAA', 'LINKBBBB', 'LINKCCCC'] as $hash) {
            $this->works[$hash] = $this->addWork($this->submissions, $hash);
        }
    }

    /** Create a live resource in the submissions workspace with a known link hash. */
    private function addWork(Workspace $ws, string $hash, ?string $orgId = null): Resource
    {
        $resource = Resource::factory()->create([
            'organization_id' => $orgId ?? $this->org->id,
            'state' => ResourceState::LIVE->value,
        ]);
        DB::table('dam_resource_workspace')->insert(['resource_id' => $resource->id, 'workspace_id' => $ws->id]);

        VaultLink::create([
            'vault_id' => $this->vault->id,
            'workspace_id' => $ws->id,
            'resource_id' => $resource->id,
            'file_id' => null,
            'link_key' => VaultLink::computeLinkKey($this->vault->id, (string) $ws->id, $resource->id, null),
            'hash' => $hash,
            'expires_at' => null,
        ]);

        return $resource;
    }

    private function writeKey(array $abilities = ['w:activate', 'w:open', 'w:close'], ?Vault $vault = null): string
    {
        [, $plaintext] = VaultKey::mint($vault ?? $this->vault, 'writer', $abilities);

        return $plaintext;
    }

    private function readKey(): string
    {
        [, $plaintext] = VaultKey::mint($this->vault, 'reader', ['read']);

        return $plaintext;
    }

    private function projectedTotal(string $readKey): int
    {
        return (int) $this->withHeader('X-Vault-Key', $readKey)
            ->getJson("/h/{$this->vault->hash}")
            ->json('pagination.total');
    }

    private function write(string $method, array $body = [], ?string $key = null): TestResponse
    {
        $headers = $key !== null ? ['X-Vault-Key' => $key] : [];

        return $this->postJson("/h/{$this->vault->hash}/w/{$method}", $body, $headers);
    }

    private function writeInfo(?string $key = null, ?Vault $vault = null): TestResponse
    {
        $vault ??= $this->vault;
        $headers = $key !== null ? ['X-Vault-Key' => $key] : [];

        return $this->getJson("/h/{$vault->hash}/w", $headers);
    }

    // =========================================================================
    // Key ability model
    // =========================================================================

    public function test_ability_matrix(): void
    {
        [$readKey, $readPlain] = VaultKey::mint($this->vault, 'r', ['read']);
        [$writeKey, $writePlain] = VaultKey::mint($this->vault, 'w', ['w:activate', 'w:open']);

        // Read key: reads, but authorizes no write.
        $this->assertTrue(VaultKey::verify($this->vault, $readPlain));
        $this->assertTrue(VaultKey::verifyWithAbility($this->vault, $readPlain, 'read'));
        $this->assertNull(VaultKey::resolveForWrite($this->vault, $readPlain, 'activate'));

        // Write key: authorizes exactly its listed methods, and does NOT read.
        $this->assertFalse(VaultKey::verify($this->vault, $writePlain));
        $this->assertNotNull(VaultKey::resolveForWrite($this->vault, $writePlain, 'activate'));
        $this->assertNull(VaultKey::resolveForWrite($this->vault, $writePlain, 'close'));
        $this->assertTrue(VaultKey::verifyWithAbility($this->vault, $writePlain, 'w:open'));

        unset($readKey, $writeKey);
    }

    // =========================================================================
    // Gate
    // =========================================================================

    public function test_read_key_cannot_write(): void
    {
        $this->write('activate', ['resources' => ['LINKAAAA']], $this->readKey())
            ->assertStatus(403);
    }

    public function test_write_key_missing_the_specific_ability_is_rejected(): void
    {
        $this->write('activate', ['resources' => ['LINKAAAA']], $this->writeKey(['w:open']))
            ->assertStatus(403);
    }

    public function test_method_not_exposed_by_purpose_is_rejected(): void
    {
        // A delivery vault exposes no write methods — even a key that literally
        // lists w:activate cannot invoke it.
        $delivery = Vault::factory()->purpose(VaultPurpose::DELIVERY)->create([
            'organization_id' => $this->org->id, 'slug' => 'cdn',
        ]);
        [, $plain] = VaultKey::mint($delivery, 'w', ['w:activate']);

        $this->postJson("/h/{$delivery->hash}/w/activate", ['resources' => ['x']], ['X-Vault-Key' => $plain])
            ->assertStatus(403);
    }

    public function test_unknown_method_is_rejected(): void
    {
        $this->write('frobnicate', [], $this->writeKey())->assertStatus(403);
    }

    public function test_missing_key_is_rejected(): void
    {
        $this->write('activate', ['resources' => ['LINKAAAA']])->assertStatus(403);
    }

    public function test_disabled_vault_is_not_found(): void
    {
        $key = $this->writeKey();
        $this->vault->update(['state' => VaultState::DISABLED->value]);

        $this->write('open', [], $key)->assertStatus(404);
    }

    public function test_ip_allowlist_blocks_with_404(): void
    {
        $this->vault->update(['allowed_ips' => ['9.9.9.9']]);

        $this->write('open', [], $this->writeKey())->assertStatus(404);
    }

    // =========================================================================
    // Write-auth probe (GET /w) — non-destructive
    // =========================================================================

    public function test_write_probe_reports_authorized_methods(): void
    {
        $this->writeInfo($this->writeKey(['w:activate', 'w:open']))
            ->assertStatus(200)
            ->assertJsonPath('ok', true)
            ->assertExactJson([
                'ok' => true,
                'methods' => ['activate', 'open'],
                // Only write-key holders learn whose vault it is.
                'organization' => $this->org->only(['id', 'slug', 'name']),
            ]);
    }

    public function test_write_probe_rejects_read_key(): void
    {
        $this->writeInfo($this->readKey())->assertStatus(403);
    }

    public function test_write_probe_requires_a_key(): void
    {
        $this->writeInfo()->assertStatus(403);
    }

    public function test_write_probe_performs_no_write(): void
    {
        $read = $this->readKey();
        $this->assertSame(3, $this->projectedTotal($read));

        $this->writeInfo($this->writeKey())->assertStatus(200);

        // Purely a probe: projection, selection, and audit are all untouched.
        $this->assertSame(3, $this->projectedTotal($read));
        $this->assertNull($this->vault->fresh()->selection_snapshot);
        $this->assertDatabaseCount('vault_writes', 0);
    }

    public function test_write_probe_on_delivery_vault_is_403(): void
    {
        // A purpose that exposes no write methods: even a w:activate key probes empty.
        $delivery = Vault::factory()->purpose(VaultPurpose::DELIVERY)->create([
            'organization_id' => $this->org->id, 'slug' => 'cdn',
        ]);
        [, $plain] = VaultKey::mint($delivery, 'w', ['w:activate']);

        $this->writeInfo($plain, $delivery)->assertStatus(403);
    }

    public function test_write_probe_on_disabled_vault_is_404(): void
    {
        $key = $this->writeKey();
        $this->vault->update(['state' => VaultState::DISABLED->value]);

        $this->writeInfo($key)->assertStatus(404);
    }

    // =========================================================================
    // activate
    // =========================================================================

    public function test_activate_narrows_projection_and_audits(): void
    {
        $read = $this->readKey();
        $this->assertSame(3, $this->projectedTotal($read));

        $this->write('activate', ['resources' => ['LINKAAAA', 'LINKBBBB']], $this->writeKey())
            ->assertStatus(200)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('result.activated', 2);

        // Projection is now exactly the two selected works.
        $this->assertSame(2, $this->projectedTotal($read));

        // Selection captured; public-workspace forced off; baseline snapshotted.
        $vault = $this->vault->fresh();
        $this->assertFalse($vault->has_public_workspace);
        $this->assertNotNull($vault->selection_snapshot);
        $this->assertEqualsCanonicalizing(
            [$this->submissions->id],
            $vault->selection_snapshot['workspace_ids'],
        );

        $this->assertDatabaseHas('vault_writes', [
            'vault_id' => $this->vault->id,
            'method' => 'activate',
        ]);
    }

    public function test_activate_rejects_unknown_hash_atomically(): void
    {
        $read = $this->readKey();

        $this->write('activate', ['resources' => ['LINKAAAA', 'NOPE']], $this->writeKey())
            ->assertStatus(400);

        // Nothing changed: full set still projected, no selection, no audit.
        $this->assertSame(3, $this->projectedTotal($read));
        $this->assertNull($this->vault->fresh()->selection_snapshot);
        $this->assertDatabaseCount('vault_writes', 0);
    }

    public function test_activate_is_idempotent(): void
    {
        $read = $this->readKey();
        $key = $this->writeKey();

        $this->write('activate', ['resources' => ['LINKAAAA', 'LINKBBBB']], $key)->assertStatus(200);
        $snapshot = $this->vault->fresh()->selection_snapshot;

        $this->write('activate', ['resources' => ['LINKAAAA', 'LINKBBBB']], $key)->assertStatus(200);

        $this->assertSame(2, $this->projectedTotal($read));
        // Baseline is captured once, not overwritten by the re-cut.
        $this->assertEquals($snapshot, $this->vault->fresh()->selection_snapshot);
    }

    public function test_org_pin_holds_against_a_crafted_foreign_hash(): void
    {
        // A link in THIS vault that points at another org's resource is not a
        // shape production can produce, but even if forced the serve-time org
        // pin keeps it out of the projection.
        $otherOrg = Organization::factory()->create();
        $this->addWork($this->submissions, 'FOREIGNX', $otherOrg->id);

        $this->write('activate', ['resources' => ['FOREIGNX']], $this->writeKey())
            ->assertStatus(200);

        $this->assertSame(0, $this->projectedTotal($this->readKey()));
    }

    // =========================================================================
    // open / close
    // =========================================================================

    public function test_open_publishes_and_close_reverts(): void
    {
        $read = $this->readKey();
        $key = $this->writeKey();

        $this->write('activate', ['resources' => ['LINKAAAA']], $key)->assertStatus(200);

        $this->write('open', [], $key)
            ->assertStatus(200)
            ->assertJsonPath('result.state', 'public');
        $this->assertSame(VaultState::PUBLIC, $this->vault->fresh()->state);

        // Published: the address alone now resolves, keyless.
        $this->getJson("/h/{$this->vault->hash}")->assertStatus(200);

        $this->write('close', [], $key)
            ->assertStatus(200)
            ->assertJsonPath('result.state', 'private');

        $vault = $this->vault->fresh();
        $this->assertSame(VaultState::PRIVATE, $vault->state);
        $this->assertNull($vault->selection_snapshot);
        // Full submission set restored.
        $this->assertSame(3, $this->projectedTotal($read));
    }
}
