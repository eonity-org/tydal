<?php

namespace Tests\Feature;

use App\Enums\ResourceState;
use App\Enums\VaultPurpose;
use App\Enums\VaultState;
use App\Models\Collection;
use App\Models\Organization;
use App\Models\User;
use App\Models\Vault;
use App\Models\VaultKey;
use App\Models\VaultLink;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The `ai` vault's `ingest` write method (VAULT_WRITE_METHODS.md §7): a derived
 * artifact (translated image + JSON descriptor document) is materialized as an
 * output resource in the vault's configured ingest target, behind a `w:ingest`
 * write key — the same gate the gallery methods use, extended for binary.
 */
class AiVaultIngestTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $user;

    private Workspace $target;

    private Collection $collection;

    private Vault $vault;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public'); // the media-library disk

        $this->org = Organization::factory()->create();
        $this->user = User::factory()->create();
        $this->target = Workspace::factory()->create([
            'organization_id' => $this->org->id,
            'user_owner_id' => $this->user->id,
        ]);
        $this->collection = Collection::factory()->create([
            'organization_id' => $this->org->id,
            'user_owner_id' => $this->user->id,
            'index_id' => null,
        ]);

        $this->vault = Vault::factory()->purpose(VaultPurpose::AI)->create([
            'organization_id' => $this->org->id,
            'slug' => 'figures',
            'state' => VaultState::PRIVATE->value,
            'exposure_policy' => [
                'ingest' => ['workspace_id' => $this->target->id, 'collection_id' => $this->collection->id],
            ],
        ]);
    }

    private function writeKey(array $abilities = ['w:ingest'], ?Vault $vault = null): string
    {
        [, $plaintext] = VaultKey::mint($vault ?? $this->vault, 'ingestor', $abilities);

        return $plaintext;
    }

    private function ingest(?string $key = null, ?array $descriptor = null, ?Vault $vault = null): TestResponse
    {
        $vault ??= $this->vault;
        $headers = $key !== null ? ['X-Vault-Key' => $key] : [];

        return $this->post("/h/{$vault->hash}/w/ingest", [
            'descriptor' => json_encode($descriptor ?? ['figures' => [['type' => 'formula', 'latex' => 'E=mc^2']]]),
            'image' => UploadedFile::fake()->image('translated.png', 40, 40),
            'name' => 'Figure 1',
            'source_hash' => 'SRC12345',
        ], $headers);
    }

    // =========================================================================
    // Gate
    // =========================================================================

    public function test_read_key_cannot_ingest(): void
    {
        [, $read] = VaultKey::mint($this->vault, 'reader', ['read']);
        $this->ingest($read)->assertStatus(403);
    }

    public function test_missing_key_is_rejected(): void
    {
        $this->ingest()->assertStatus(403);
    }

    public function test_ingest_not_exposed_on_non_ai_purpose(): void
    {
        $delivery = Vault::factory()->purpose(VaultPurpose::DELIVERY)->create([
            'organization_id' => $this->org->id, 'slug' => 'cdn',
        ]);
        // Even a key that literally lists w:ingest cannot invoke it where the
        // purpose exposes no such method.
        [, $plain] = VaultKey::mint($delivery, 'w', ['w:ingest']);

        $this->ingest($plain, vault: $delivery)->assertStatus(403);
    }

    public function test_unconfigured_target_is_a_400(): void
    {
        $this->vault->update(['exposure_policy' => null]);

        $this->ingest($this->writeKey())
            ->assertStatus(400)
            ->assertJsonPath('ok', false);
    }

    // =========================================================================
    // Happy path
    // =========================================================================

    public function test_ingest_materializes_an_output_resource_and_audits(): void
    {
        $res = $this->ingest($this->writeKey())
            ->assertStatus(200)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('result.files', 2);

        // The response names the new resource by its vault-purpose link hash —
        // no internal UUID or workspace id crosses the write boundary
        // (VAULT_WRITE_METHODS.md §7). Resolve it back to the internal id the
        // same way the app does, purely to assert on the DB rows below.
        $hash = $res->json('result.hash');
        $this->assertNotNull($hash);
        $this->assertNull($res->json('result.resource_id'));
        $this->assertNull($res->json('result.workspace_id'));

        $link = VaultLink::where('vault_id', $this->vault->id)->where('hash', $hash)->firstOrFail();
        $resourceId = $link->resource_id;
        $this->assertNotNull($resourceId);

        // Resource created live in the configured collection…
        $this->assertDatabaseHas('resources', [
            'id' => $resourceId,
            'organization_id' => $this->org->id,
            'collection_id' => $this->collection->id,
            'state' => ResourceState::LIVE->value,
        ]);

        // …attached to the ingest workspace…
        $this->assertDatabaseHas('dam_resource_workspace', [
            'resource_id' => $resourceId,
            'workspace_id' => $this->target->id,
        ]);

        // …composed of a canonical JSON descriptor + a component translation.
        $this->assertDatabaseHas('files', [
            'resource_id' => $resourceId,
            'role' => 'canonical',
            'filename' => 'descriptor.json',
        ]);
        $this->assertDatabaseHas('files', [
            'resource_id' => $resourceId,
            'role' => 'component',
            'relation' => 'translation',
        ]);

        // Audited on the vault.
        $this->assertDatabaseHas('vault_writes', [
            'vault_id' => $this->vault->id,
            'method' => 'ingest',
        ]);
    }

    public function test_ingest_requires_image_and_descriptor(): void
    {
        // Missing image → the validation exception is caught by the write
        // dispatcher and surfaced as a 400 (same shape as any refused write).
        $this->post("/h/{$this->vault->hash}/w/ingest", [
            'descriptor' => json_encode(['figures' => []]),
        ], ['X-Vault-Key' => $this->writeKey()])->assertStatus(400);
    }
}
