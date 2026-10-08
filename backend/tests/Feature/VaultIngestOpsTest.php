<?php

namespace Tests\Feature;

use App\Enums\ResourceState;
use App\Enums\SystemFilePurpose;
use App\Enums\VaultPurpose;
use App\Enums\VaultState;
use App\Jobs\ExtractFileText;
use App\Models\Collection;
use App\Models\CollectionScheme;
use App\Models\File;
use App\Models\Organization;
use App\Models\Resource;
use App\Models\SystemFile;
use App\Models\User;
use App\Models\Vault;
use App\Models\VaultKey;
use App\Models\VaultLink;
use App\Models\Workspace;
use App\Services\VaultLinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The purpose-agnostic inbound ops (VAULT_WRITE_METHODS.md §3), exercised on a
 * gallery vault: `ingest` adds a photograph from a metadata document, `update`
 * corrects one, `withdraw` removes one — each behind a write key, and
 * `update`/`withdraw` only on resources this vault ingested.
 */
class VaultIngestOpsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Workspace $submissions;

    private Collection $collection;

    private Vault $vault;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public'); // the media-library disk
        // Ingest starts AITY; its pipeline (Tika, vision) is not under test here.
        Queue::fake([ExtractFileText::class]);

        $this->org = Organization::factory()->create();
        $user = User::factory()->create();
        $this->submissions = Workspace::factory()->create([
            'organization_id' => $this->org->id,
            'user_owner_id' => $user->id,
        ]);
        $scheme = CollectionScheme::create([
            'name' => 'photo_test',
            'display_name' => 'Photo',
            'is_system' => false,
            'fields' => [],
            'accepted_mimetypes' => ['image/*'],
        ]);
        $this->collection = Collection::factory()->withScheme($scheme)->create([
            'organization_id' => $this->org->id,
            'user_owner_id' => $user->id,
            'index_id' => null,
        ]);

        $this->vault = Vault::factory()->purpose(VaultPurpose::GALLERY)->create([
            'organization_id' => $this->org->id,
            'slug' => 'show',
            'state' => VaultState::PRIVATE->value,
            'has_public_workspace' => false,
            'exposure_policy' => [
                'ingest' => ['workspace_id' => $this->submissions->id, 'collection_id' => $this->collection->id],
            ],
        ]);
        DB::table('workspace_vault')->insert(['workspace_id' => $this->submissions->id, 'vault_id' => $this->vault->id]);
    }

    private function writeKey(array $abilities = ['w:ingest', 'w:update', 'w:withdraw']): string
    {
        [, $plaintext] = VaultKey::mint($this->vault, 'curator', $abilities);

        return $plaintext;
    }

    /** @param  array<string, mixed>|null  $metadata */
    private function ingest(?string $key, ?array $metadata = null, ?UploadedFile $image = null, bool $suggest = false): TestResponse
    {
        $headers = $key !== null ? ['X-Vault-Key' => $key] : [];

        return $this->post("/h/{$this->vault->hash}/w/ingest", [
            ...($suggest ? ['suggest' => 'true'] : []),
            'image' => $image ?? UploadedFile::fake()->image('199008_Jane_Doe_K23.jpg', 60, 40),
            'metadata' => json_encode($metadata ?? [
                'name' => 'Morning at the Pier',
                'description' => 'Printed for the 1990 salon.',
                'author' => 'Ana Ruiz',
                'technique' => 'Silver gelatin print',
                'dimensions' => '50 × 70 cm',
            ]),
        ], $headers);
    }

    private function op(string $key, string $method, array $body): TestResponse
    {
        return $this->postJson("/h/{$this->vault->hash}/w/{$method}", $body, ['X-Vault-Key' => $key]);
    }

    private function resourceFor(string $hash): Resource
    {
        return Resource::withTrashed()->findOrFail(VaultLink::where('hash', $hash)->value('resource_id'));
    }

    // =========================================================================
    // ingest
    // =========================================================================

    public function test_ingest_maps_the_metadata_document_onto_the_resource(): void
    {
        $res = $this->ingest($this->writeKey())
            ->assertStatus(200)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('result.name', 'Morning at the Pier');

        $resource = $this->resourceFor($res->json('result.hash'));

        // Columns lifted out, the rest stored as given — no copy of the title.
        // (assertEquals: MySQL's JSON column reorders object keys.)
        $this->assertSame('Morning at the Pier', $resource->name);
        $this->assertSame('Printed for the 1990 salon.', $resource->description);
        $this->assertEquals([
            'author' => 'Ana Ruiz',
            'technique' => 'Silver gelatin print',
            'dimensions' => '50 × 70 cm',
        ], $resource->metadata);
        $this->assertSame(ResourceState::LIVE, $resource->state);
        $this->assertSame($this->collection->id, $resource->collection_id);
        $this->assertDatabaseHas('dam_resource_workspace', [
            'resource_id' => $resource->id,
            'workspace_id' => $this->submissions->id,
        ]);
        $this->assertDatabaseHas('vault_writes', ['vault_id' => $this->vault->id, 'method' => 'ingest']);
    }

    public function test_stored_filename_comes_from_the_title_not_the_upload(): void
    {
        $resource = $this->resourceFor($this->ingest($this->writeKey())->json('result.hash'));

        $this->assertDatabaseHas('files', [
            'resource_id' => $resource->id,
            'role' => 'canonical',
            'filename' => 'morning-at-the-pier.jpg',
        ]);
        $this->assertDatabaseMissing('files', ['filename' => '199008_Jane_Doe_K23.jpg']);
    }

    public function test_gallery_ingest_requires_a_title(): void
    {
        $this->ingest($this->writeKey(), ['description' => 'No title'])->assertStatus(400);
        $this->assertSame(0, Resource::count());
    }

    public function test_metadata_must_be_a_flat_object_with_plain_keys(): void
    {
        $key = $this->writeKey();
        $this->ingest($key, ['name' => 'x', 'nested' => ['a' => 1]])->assertStatus(400);
        $this->ingest($key, ['name' => 'x', 'Bad Key' => 'y'])->assertStatus(400);
        $this->assertSame(0, Resource::count());
    }

    public function test_ingest_rejects_types_the_collection_does_not_accept(): void
    {
        $this->post("/h/{$this->vault->hash}/w/ingest", [
            'image' => UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf'),
            'metadata' => json_encode(['name' => 'Notes']),
        ], ['X-Vault-Key' => $this->writeKey()])->assertStatus(400);

        $this->assertSame(0, Resource::count());
    }

    public function test_ingest_needs_its_own_ability(): void
    {
        $this->ingest($this->writeKey(['w:activate', 'w:open', 'w:close']))->assertStatus(403);
        [, $read] = VaultKey::mint($this->vault, 'reader', ['read']);
        $this->ingest($read)->assertStatus(403);
    }

    public function test_ingest_without_a_target_is_a_400(): void
    {
        $this->vault->update(['exposure_policy' => null]);

        $this->ingest($this->writeKey())->assertStatus(400)->assertJsonPath('ok', false);
    }

    public function test_ingest_is_refused_when_the_target_workspace_is_not_projected(): void
    {
        DB::table('workspace_vault')->where('vault_id', $this->vault->id)->delete();

        $this->ingest($this->writeKey())->assertStatus(400);
        $this->assertSame(0, Resource::count());
    }

    // =========================================================================
    // update
    // =========================================================================

    public function test_update_merges_per_key(): void
    {
        $key = $this->writeKey();
        $hash = $this->ingest($key)->json('result.hash');

        $this->op($key, 'update', [
            'resource' => $hash,
            'metadata' => ['name' => 'Evening at the Pier', 'dimensions' => null, 'year' => 1990],
        ])->assertStatus(200)->assertJsonPath('result.updated', $hash);

        $resource = $this->resourceFor($hash);
        $this->assertSame('Evening at the Pier', $resource->name);
        $this->assertSame('Printed for the 1990 salon.', $resource->description); // untouched
        $this->assertEquals([
            'author' => 'Ana Ruiz',
            'technique' => 'Silver gelatin print',
            'year' => 1990,
        ], $resource->metadata);
    }

    public function test_update_cannot_remove_the_title(): void
    {
        $key = $this->writeKey();
        $hash = $this->ingest($key)->json('result.hash');

        $this->op($key, 'update', ['resource' => $hash, 'metadata' => ['name' => null]])->assertStatus(400);
        $this->assertSame('Morning at the Pier', $this->resourceFor($hash)->name);
    }

    public function test_update_refuses_resources_this_vault_did_not_ingest(): void
    {
        $resource = Resource::factory()->create([
            'organization_id' => $this->org->id,
            'state' => ResourceState::LIVE->value,
            'name' => 'Arrived another way',
        ]);
        DB::table('dam_resource_workspace')->insert(['resource_id' => $resource->id, 'workspace_id' => $this->submissions->id]);
        $link = app(VaultLinkService::class)->getOrCreateLink($this->vault, null, $resource->id, null);

        $this->op($this->writeKey(), 'update', [
            'resource' => $link->hash,
            'metadata' => ['name' => 'Renamed'],
        ])->assertStatus(400);
        $this->assertSame('Arrived another way', $resource->fresh()->name);
    }

    // =========================================================================
    // withdraw
    // =========================================================================

    public function test_withdraw_trashes_an_ingested_resource(): void
    {
        $key = $this->writeKey();
        $hash = $this->ingest($key)->json('result.hash');
        $resourceId = $this->resourceFor($hash)->id;

        $this->op($key, 'withdraw', ['resource' => $hash])
            ->assertStatus(200)
            ->assertJsonPath('result.withdrawn', $hash);

        $this->assertSoftDeleted('resources', ['id' => $resourceId]);
    }

    public function test_withdraw_is_refused_while_a_selection_is_active(): void
    {
        $key = $this->writeKey();
        $hash = $this->ingest($key)->json('result.hash');
        $this->vault->update(['selection_snapshot' => ['workspace_ids' => [$this->submissions->id], 'has_public_workspace' => false]]);

        $this->op($key, 'withdraw', ['resource' => $hash])->assertStatus(400);
    }

    public function test_update_and_withdraw_need_their_own_abilities(): void
    {
        $hash = $this->ingest($this->writeKey())->json('result.hash');
        $ingestOnly = $this->writeKey(['w:ingest']);

        $this->op($ingestOnly, 'update', ['resource' => $hash, 'metadata' => ['name' => 'x']])->assertStatus(403);
        $this->op($ingestOnly, 'withdraw', ['resource' => $hash])->assertStatus(403);
    }

    // =========================================================================
    // probe
    // =========================================================================

    public function test_probe_lists_what_the_key_may_update_or_withdraw(): void
    {
        $key = $this->writeKey();
        $kept = $this->ingest($key)->json('result.hash');
        $gone = $this->ingest($key, ['name' => 'Second'])->json('result.hash');
        $this->op($key, 'withdraw', ['resource' => $gone])->assertStatus(200);

        $this->getJson("/h/{$this->vault->hash}/w", ['X-Vault-Key' => $key])
            ->assertStatus(200)
            ->assertJsonPath('ingested', [$kept]);

        // A key that can neither update nor withdraw isn't told.
        $this->getJson("/h/{$this->vault->hash}/w", ['X-Vault-Key' => $this->writeKey(['w:activate'])])
            ->assertStatus(200)
            ->assertJsonMissingPath('ingested');
    }

    // =========================================================================
    // size limit
    // =========================================================================

    public function test_ingest_refuses_a_file_over_the_limit_with_its_size(): void
    {
        config(['media-library.max_file_size' => 1024 * 1024]); // 1 MB

        $this->ingest($this->writeKey(), null, UploadedFile::fake()->image('big.jpg')->size(2048))
            ->assertStatus(400)
            ->assertJsonPath('error', 'The file is 2.0 MB; this vault accepts up to 1.0 MB.');
        $this->assertSame(0, Resource::count());
    }

    public function test_probe_reports_the_upload_limit_to_keys_that_can_ingest(): void
    {
        config(['media-library.max_file_size' => 1024 * 1024]);

        $this->getJson("/h/{$this->vault->hash}/w", ['X-Vault-Key' => $this->writeKey()])
            ->assertJsonPath('max_upload_bytes', 1024 * 1024);
        $this->getJson("/h/{$this->vault->hash}/w", ['X-Vault-Key' => $this->writeKey(['w:activate'])])
            ->assertJsonMissingPath('max_upload_bytes');
    }

    // =========================================================================
    // AITY suggestions
    // =========================================================================

    private function suggestions(string $key, string $resources): TestResponse
    {
        return $this->getJson(
            "/h/{$this->vault->hash}/w/suggestions?resources={$resources}",
            ['X-Vault-Key' => $key],
        );
    }

    private function canonicalFile(string $hash): File
    {
        return File::where('resource_id', $this->resourceFor($hash)->id)->firstOrFail();
    }

    private function suggest(File $file, SystemFilePurpose $purpose, string $value): void
    {
        SystemFile::create([
            'resource_id' => $file->resource_id,
            'source_file_id' => $file->id,
            'purpose' => $purpose->value,
            'filename' => 'suggestion.json',
            'mime_type' => 'application/json',
            'size' => 1,
            'path' => 'suggestion.json',
            'disk' => 'public',
            'metadata' => ['value' => $value],
            'is_active' => true,
        ]);
    }

    public function test_ingest_starts_aity_only_when_asked(): void
    {
        $key = $this->writeKey();
        $plain = $this->ingest($key)->json('result.hash');

        // Without `suggest` the photograph never reaches an AI service.
        Queue::assertNothingPushed();
        $this->assertNull($this->canonicalFile($plain)->processing_status);

        $asked = $this->ingest($key, null, null, true)->json('result.hash');

        Queue::assertPushed(ExtractFileText::class, 1);
        $this->assertSame('queued', $this->canonicalFile($asked)->processing_status['stage']);
        // AITY only proposes: what the consumer sent stays.
        $this->assertSame('Morning at the Pier', $this->resourceFor($asked)->name);
    }

    public function test_suggestions_report_pending_then_the_proposals(): void
    {
        $key = $this->writeKey();
        $hash = $this->ingest($key, null, null, true)->json('result.hash');

        $this->suggestions($key, $hash)
            ->assertStatus(200)
            ->assertJsonPath("suggestions.{$hash}", ['status' => 'pending', 'name' => null, 'description' => null]);

        $file = $this->canonicalFile($hash);
        $this->suggest($file, SystemFilePurpose::AI_SUGGESTED_NAME, 'Pier at dawn');
        $this->suggest($file, SystemFilePurpose::AI_SUGGESTED_DESCRIPTION, 'A wooden pier stretches into calm water.');
        $file->updateProcessingStage('done');

        $this->suggestions($key, $hash)
            ->assertStatus(200)
            ->assertJsonPath("suggestions.{$hash}", [
                'status' => 'done',
                'name' => 'Pier at dawn',
                'description' => 'A wooden pier stretches into calm water.',
            ]);
    }

    public function test_reading_suggestions_never_starts_aity(): void
    {
        $key = $this->writeKey();
        $hash = $this->ingest($key)->json('result.hash');

        $this->suggestions($key, $hash)
            ->assertStatus(200)
            ->assertJsonPath("suggestions.{$hash}", ['status' => 'off', 'name' => null, 'description' => null]);

        Queue::assertNothingPushed();
        $this->assertNull($this->canonicalFile($hash)->processing_status);
    }

    public function test_suggestions_only_cover_what_this_vault_ingested_for_a_key_that_may_update(): void
    {
        $key = $this->writeKey();
        $hash = $this->ingest($key)->json('result.hash');

        // Unknown hashes are left out, not refused.
        $this->suggestions($key, "{$hash},nope")
            ->assertStatus(200)
            ->assertJsonCount(1, 'suggestions')
            ->assertJsonMissingPath('suggestions.nope');

        $this->suggestions($this->writeKey(['w:ingest']), $hash)->assertStatus(403);
        $this->getJson("/h/{$this->vault->hash}/w/suggestions?resources={$hash}")->assertStatus(403);
        $this->suggestions($key, '')->assertStatus(400);

        // The human form reaches the same read.
        $this->getJson(
            "/v/{$this->org->slug}/show/w/suggestions?resources={$hash}",
            ['X-Vault-Key' => $key],
        )->assertStatus(200)->assertJsonPath("suggestions.{$hash}.status", 'off');
    }
}
