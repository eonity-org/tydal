<?php

namespace Tests\Feature;

use App\Enums\ResourceState;
use App\Enums\SystemFilePurpose;
use App\Jobs\EmbedFileChunks;
use App\Models\Collection;
use App\Models\CollectionScheme;
use App\Models\File;
use App\Models\FileChunk;
use App\Models\Organization;
use App\Models\Resource;
use App\Models\SearchIndex;
use App\Models\SystemFile;
use App\Models\User;
use App\Services\ElasticsearchService;
use App\Services\FileStorageService;
use App\Services\Processing\Contracts\EmbeddingServiceInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

/**
 * Integration tests for the EmbedFileChunks job.
 *
 * ElasticsearchService is replaced with a Mockery mock — no real ES required.
 * Embedding is done via a Mockery mock — no real Ollama/Voyage required.
 * Storage::fake('local') is used for gzip archives.
 * RefreshDatabase ensures isolation.
 */
class EmbedFileChunksJobTest extends TestCase
{
    use RefreshDatabase;

    private Resource $resource;

    private File $file;

    private SearchIndex $searchIndex;

    private const FAKE_CHUNKS = [
        ['sequence' => 0, 'page_number' => 1, 'content' => 'The quick brown fox.', 'word_count' => 4, 'char_start' => 0,  'char_end' => 20],
        ['sequence' => 1, 'page_number' => 2, 'content' => 'Lorem ipsum dolor.',   'word_count' => 3, 'char_start' => 21, 'char_end' => 39],
    ];

    private const FAKE_VECTORS = [[0.1, 0.2, 0.3], [0.4, 0.5, 0.6]];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        // FAKE_VECTORS are 3-dimensional; align the expected dimension so the job's
        // dimension guard passes without a live embedding provider (CI has none).
        config(['embedding.dimensions' => 3]);

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
            'accepted_mimetypes' => ['application/pdf'],
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
            'collection_id' => $collection->id,
            'organization_id' => $org->id,
            'user_owner_id' => $user->id,
            'state' => ResourceState::LIVE->value,
        ]);

        $this->file = File::factory()->forResource($this->resource->id)->create([
            'disk' => 'local',
            'path' => 'test/document.pdf',
            'mime_type' => 'application/pdf',
        ]);
    }

    // =========================================================================
    // Core embedding
    // =========================================================================

    public function test_job_embeds_chunks_and_upserts_to_es(): void
    {
        $this->seedArchiveAndChunks();

        $capturedDocs = [];

        $mockEs = Mockery::mock(ElasticsearchService::class);
        $mockEs->shouldReceive('buildChunksIndexName')
            ->with('tydal_test')
            ->andReturn('tydal_test_chunks');
        $mockEs->shouldReceive('upsertChunkVector')
            ->twice()
            ->withArgs(function ($index, $doc) use (&$capturedDocs) {
                $capturedDocs[] = $doc;

                return $index === 'tydal_test_chunks';
            });

        $mockEmbedder = Mockery::mock(EmbeddingServiceInterface::class);
        $mockEmbedder->shouldReceive('embedBatch')
            ->once()
            ->andReturn(self::FAKE_VECTORS);

        (new EmbedFileChunks($this->file->id))->handle($mockEmbedder, $mockEs);

        $this->assertCount(2, $capturedDocs);
    }

    public function test_chunk_doc_has_correct_required_fields(): void
    {
        $this->seedArchiveAndChunks();

        $capturedDocs = [];

        $mockEs = Mockery::mock(ElasticsearchService::class);
        $mockEs->shouldReceive('buildChunksIndexName')->andReturn('tydal_test_chunks');
        $mockEs->shouldReceive('upsertChunkVector')
            ->withArgs(function ($index, $doc) use (&$capturedDocs) {
                $capturedDocs[] = $doc;

                return true;
            });

        $mockEmbedder = Mockery::mock(EmbeddingServiceInterface::class);
        $mockEmbedder->shouldReceive('embedBatch')->andReturn(self::FAKE_VECTORS);

        (new EmbedFileChunks($this->file->id))->handle($mockEmbedder, $mockEs);

        $doc = $capturedDocs[0];
        $this->assertArrayHasKey('chunk_id', $doc);
        $this->assertArrayHasKey('resource_id', $doc);
        $this->assertArrayHasKey('file_id', $doc);
        $this->assertArrayHasKey('collection_id', $doc);
        $this->assertArrayHasKey('sequence', $doc);
        $this->assertArrayHasKey('page_number', $doc);
        $this->assertArrayHasKey('content', $doc);
        $this->assertArrayHasKey('vector', $doc);

        $this->assertSame($this->resource->id, $doc['resource_id']);
        $this->assertSame($this->file->id, $doc['file_id']);
        $this->assertSame([0.1, 0.2, 0.3], $doc['vector']);
    }

    public function test_chunk_doc_ids_match_file_chunk_uuids(): void
    {
        $this->seedArchiveAndChunks();

        $chunkIds = FileChunk::where('source_file_id', $this->file->id)
            ->orderBy('sequence')
            ->pluck('id')
            ->all();

        $capturedIds = [];

        $mockEs = Mockery::mock(ElasticsearchService::class);
        $mockEs->shouldReceive('buildChunksIndexName')->andReturn('tydal_test_chunks');
        $mockEs->shouldReceive('upsertChunkVector')
            ->withArgs(function ($index, $doc) use (&$capturedIds) {
                $capturedIds[] = $doc['chunk_id'];

                return true;
            });

        $mockEmbedder = Mockery::mock(EmbeddingServiceInterface::class);
        $mockEmbedder->shouldReceive('embedBatch')->andReturn(self::FAKE_VECTORS);

        (new EmbedFileChunks($this->file->id))->handle($mockEmbedder, $mockEs);

        $this->assertSame($chunkIds, $capturedIds);
    }

    // =========================================================================
    // Skip conditions
    // =========================================================================

    public function test_job_records_embedded_stage_on_success(): void
    {
        $this->seedArchiveAndChunks();

        $mockEs = Mockery::mock(ElasticsearchService::class);
        $mockEs->shouldReceive('buildChunksIndexName')->andReturn('tydal_test_chunks');
        $mockEs->shouldReceive('upsertChunkVector');

        $mockEmbedder = Mockery::mock(EmbeddingServiceInterface::class);
        $mockEmbedder->shouldReceive('embedBatch')->andReturn(self::FAKE_VECTORS);

        (new EmbedFileChunks($this->file->id))->handle($mockEmbedder, $mockEs);

        $stage = $this->file->fresh()->processing_status['stage'] ?? null;
        $this->assertSame('embedded', $stage);
        $this->assertSame(2, $this->file->fresh()->processing_status['chunk_count'] ?? null);
    }

    public function test_job_skips_when_collection_has_no_search_index(): void
    {
        // Remove the index from the collection
        $this->resource->collection->update(['index_id' => null]);

        $mockEs = Mockery::mock(ElasticsearchService::class);
        $mockEmbedder = Mockery::mock(EmbeddingServiceInterface::class);

        $mockEs->shouldNotReceive('upsertChunkVector');
        $mockEmbedder->shouldNotReceive('embedBatch');

        (new EmbedFileChunks($this->file->id))->handle($mockEmbedder, $mockEs);
    }

    public function test_job_skips_when_no_active_extracted_text_system_file(): void
    {
        // No SystemFile created — extraction hasn't run

        $mockEs = Mockery::mock(ElasticsearchService::class);
        $mockEmbedder = Mockery::mock(EmbeddingServiceInterface::class);

        $mockEs->shouldReceive('buildChunksIndexName')->andReturn('tydal_test_chunks');
        $mockEs->shouldNotReceive('upsertChunkVector');
        $mockEmbedder->shouldNotReceive('embedBatch');

        (new EmbedFileChunks($this->file->id))->handle($mockEmbedder, $mockEs);
    }

    public function test_job_skips_when_archive_has_no_chunks(): void
    {
        // Store an empty chunks array
        FileStorageService::storeExtractedText($this->resource->id, $this->file->id, json_encode([]), 'local');

        SystemFile::create([
            'resource_id' => $this->resource->id,
            'source_file_id' => $this->file->id,
            'purpose' => SystemFilePurpose::EXTRACTED_TEXT,
            'filename' => 'extracted_text.json.gz',
            'mime_type' => 'application/gzip',
            'size' => 10,
            'path' => "{$this->resource->id}/archives/{$this->file->id}.json.gz",
            'disk' => 'local',
            'is_active' => true,
            'metadata' => ['chunk_count' => 0],
        ]);

        $mockEs = Mockery::mock(ElasticsearchService::class);
        $mockEmbedder = Mockery::mock(EmbeddingServiceInterface::class);

        $mockEs->shouldReceive('buildChunksIndexName')->andReturn('tydal_test_chunks');
        $mockEs->shouldNotReceive('upsertChunkVector');
        $mockEmbedder->shouldNotReceive('embedBatch');

        (new EmbedFileChunks($this->file->id))->handle($mockEmbedder, $mockEs);
    }

    // =========================================================================
    // Failure handling
    // =========================================================================

    public function test_failed_hook_records_error_in_system_file_metadata(): void
    {
        $this->seedArchiveAndChunks();

        $job = new EmbedFileChunks($this->file->id);
        $job->failed(new \RuntimeException('Ollama connection refused'));

        $systemFile = SystemFile::where('source_file_id', $this->file->id)
            ->where('purpose', SystemFilePurpose::EXTRACTED_TEXT->value)
            ->where('is_active', true)
            ->first();

        $this->assertNotNull($systemFile);
        $this->assertArrayHasKey('embedding_error', $systemFile->fresh()->metadata);
        $this->assertSame('Ollama connection refused', $systemFile->fresh()->metadata['embedding_error']);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Create the gzip archive + SystemFile + FileChunk rows that ExtractFileText would produce.
     */
    private function seedArchiveAndChunks(): void
    {
        $stored = FileStorageService::storeExtractedText(
            $this->resource->id,
            $this->file->id,
            json_encode(self::FAKE_CHUNKS),
            'local'
        );

        $systemFile = SystemFile::create([
            'resource_id' => $this->resource->id,
            'source_file_id' => $this->file->id,
            'purpose' => SystemFilePurpose::EXTRACTED_TEXT,
            'filename' => $stored['filename'],
            'mime_type' => $stored['mime_type'],
            'size' => $stored['size'],
            'path' => $stored['path'],
            'disk' => $stored['disk'],
            'is_active' => true,
            'metadata' => ['chunk_count' => count(self::FAKE_CHUNKS)],
        ]);

        $rows = array_map(fn ($chunk) => [
            'id' => (string) Str::orderedUuid(),
            'resource_id' => $this->resource->id,
            'source_file_id' => $this->file->id,
            'archive_file_id' => $systemFile->id,
            'sequence' => $chunk['sequence'],
            'page_number' => $chunk['page_number'],
            'word_count' => $chunk['word_count'],
            'char_start' => $chunk['char_start'],
            'char_end' => $chunk['char_end'],
            'created_at' => now(),
        ], self::FAKE_CHUNKS);

        FileChunk::insert($rows);
    }
}
