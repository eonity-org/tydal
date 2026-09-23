<?php

namespace Tests\Feature;

use App\Enums\ResourceState;
use App\Jobs\EmbedFileChunks;
use App\Jobs\UpsertResourceMetadataChunk;
use App\Models\Collection;
use App\Models\CollectionScheme;
use App\Models\File;
use App\Models\FileChunk;
use App\Models\Organization;
use App\Models\Resource;
use App\Models\SearchIndex;
use App\Models\User;
use App\Services\ElasticsearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

/**
 * Epic 1.3 — search:reconcile drift detection and repair.
 * ElasticsearchService is mocked (codebase convention for feature tests).
 */
class SearchReconcileTest extends TestCase
{
    use RefreshDatabase;

    private Resource $resource;

    private SearchIndex $searchIndex;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $org = Organization::factory()->create();

        $this->searchIndex = SearchIndex::create([
            'index_name' => 'tydal_test',
            'display_name' => 'Test Index',
            'is_active' => true,
        ]);

        $scheme = CollectionScheme::create([
            'name' => 'test-scheme',
            'display_name' => 'Test Scheme',
            'accepted_mimetypes' => [],
            'is_system' => false,
            'fields' => [],
        ]);

        $collection = Collection::factory()->create([
            'organization_id' => $org->id,
            'user_owner_id' => $user->id,
            'scheme_id' => $scheme->id,
            'index_id' => $this->searchIndex->id,
        ]);

        $this->resource = Resource::factory()->create([
            'organization_id' => $org->id,
            'collection_id' => $collection->id,
            'user_owner_id' => $user->id,
            'state' => ResourceState::LIVE->value,
        ]);
    }

    private function mockEs(array $listReturn, ?array $chunkDocs = null): Mockery\MockInterface
    {
        $mock = Mockery::mock(ElasticsearchService::class);
        $mock->shouldReceive('listIndexedResources')
            ->with('tydal_test')
            ->andReturn($listReturn);
        $mock->shouldReceive('buildChunksIndexName')->with('tydal_test')->andReturn('tydal_test_chunks');
        // null = no chunks index (chunk reconciliation no-ops); array = its docs
        $mock->shouldReceive('chunksIndexExists')->with('tydal_test_chunks')->andReturn($chunkDocs !== null);
        if ($chunkDocs !== null) {
            $mock->shouldReceive('listIndexedChunks')->with('tydal_test_chunks')->andReturn($chunkDocs);
        }
        $this->instance(ElasticsearchService::class, $mock);

        return $mock;
    }

    public function test_reports_no_drift_when_in_sync(): void
    {
        $this->mockEs([
            $this->resource->id => $this->resource->updated_at->toIso8601String(),
        ]);

        $this->artisan('search:reconcile')
            ->expectsOutputToContain('No drift detected.')
            ->assertExitCode(0);
    }

    public function test_detects_missing_stale_and_orphaned(): void
    {
        Resource::factory()->create([
            'organization_id' => $this->resource->organization_id,
            'collection_id' => $this->resource->collection_id,
            'user_owner_id' => $this->resource->user_owner_id,
            'state' => ResourceState::LIVE->value,
        ]); // missing from ES

        $this->mockEs([
            $this->resource->id => '2001-01-01T00:00:00+00:00', // stale
            'ghost-doc-id' => '2001-01-01T00:00:00+00:00',      // orphaned
        ]);

        $this->artisan('search:reconcile')
            ->expectsOutputToContain('missing: 1 · stale: 1 · orphaned: 1')
            ->assertExitCode(1);
    }

    public function test_fix_reindexes_and_purges(): void
    {
        $mock = $this->mockEs([
            $this->resource->id => '2001-01-01T00:00:00+00:00', // stale
            'ghost-doc-id' => null,                              // orphaned
        ]);

        $mock->shouldReceive('indexResource')
            ->once()
            ->withArgs(fn (Resource $r) => $r->id === $this->resource->id);
        $mock->shouldReceive('purgeDocument')
            ->once()
            ->with('tydal_test', 'ghost-doc-id');

        $this->artisan('search:reconcile --fix')
            ->expectsOutputToContain('fixed: reindexed 1, purged 1')
            ->assertExitCode(0);
    }

    public function test_inactive_resources_count_as_orphans(): void
    {
        $this->resource->update(['state' => ResourceState::ARCHIVED->value]);

        $this->mockEs([
            $this->resource->id => $this->resource->updated_at->toIso8601String(),
        ]);

        $this->artisan('search:reconcile')
            ->expectsOutputToContain('missing: 0 · stale: 0 · orphaned: 1')
            ->assertExitCode(1);
    }

    // =========================================================================
    // Chunk drift (…_chunks index vs the file_chunks manifest)
    // =========================================================================

    /** Insert a manifest row + its source file; returns [fileId, chunkId]. */
    private function manifestChunk(): array
    {
        $file = File::factory()->forResource($this->resource->id)->create();
        $chunkId = (string) Str::orderedUuid();
        FileChunk::insert([
            'id' => $chunkId,
            'resource_id' => $this->resource->id,
            'source_file_id' => $file->id,
            'sequence' => 0,
            'page_number' => 1,
            'word_count' => 10,
            'char_start' => 0,
            'char_end' => 100,
            'created_at' => now(),
        ]);

        return [$file->id, $chunkId];
    }

    public function test_chunks_in_sync_report_no_drift(): void
    {
        [$fileId, $chunkId] = $this->manifestChunk();

        $this->mockEs(
            [$this->resource->id => $this->resource->updated_at->toIso8601String()],
            [
                $chunkId => ['file_id' => $fileId, 'resource_id' => $this->resource->id],
                'meta-'.$this->resource->id => ['file_id' => '', 'resource_id' => $this->resource->id],
            ],
        );

        $this->artisan('search:reconcile')
            ->expectsOutputToContain('chunks — missing: 0 · orphaned: 0 · meta missing: 0 · meta orphaned: 0')
            ->assertExitCode(0);
    }

    public function test_detects_stale_chunk_generation_and_missing_meta(): void
    {
        [$fileId, $chunkId] = $this->manifestChunk();

        $this->mockEs(
            [$this->resource->id => $this->resource->updated_at->toIso8601String()],
            [
                $chunkId => ['file_id' => $fileId, 'resource_id' => $this->resource->id],
                // Stale generation: a doc from a previous extraction whose
                // manifest row was replaced (fresh UUIDs each run)
                'old-generation-chunk' => ['file_id' => $fileId, 'resource_id' => $this->resource->id],
                // No meta chunk for the resource
            ],
        );

        $this->artisan('search:reconcile')
            ->expectsOutputToContain('chunks — missing: 0 · orphaned: 1 · meta missing: 1 · meta orphaned: 0')
            ->assertExitCode(1);
    }

    public function test_fix_purges_stale_chunks_and_dispatches_embed_jobs(): void
    {
        Queue::fake();

        [$fileId, $chunkId] = $this->manifestChunk();
        $goneResourceId = (string) Str::uuid();

        $mock = $this->mockEs(
            [$this->resource->id => $this->resource->updated_at->toIso8601String()],
            [
                // $chunkId itself is NOT in ES → missing (unembedded) → embed job
                'old-generation-chunk' => ['file_id' => $fileId, 'resource_id' => $this->resource->id],
                'meta-'.$goneResourceId => ['file_id' => '', 'resource_id' => $goneResourceId],
            ],
        );

        $mock->shouldReceive('purgeDocument')->once()->with('tydal_test_chunks', 'old-generation-chunk');
        $mock->shouldReceive('purgeDocument')->once()->with('tydal_test_chunks', 'meta-'.$goneResourceId);

        $this->artisan('search:reconcile --fix')
            ->expectsOutputToContain('purged 2 chunk doc(s), dispatched 1 embed + 1 meta job(s)')
            ->assertExitCode(0);

        Queue::assertPushed(EmbedFileChunks::class, fn ($job) => $job->getFileId() === $fileId);
        Queue::assertPushed(UpsertResourceMetadataChunk::class, fn ($job) => $job->getResourceId() === $this->resource->id);
    }
}
