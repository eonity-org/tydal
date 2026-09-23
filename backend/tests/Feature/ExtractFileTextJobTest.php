<?php

namespace Tests\Feature;

use App\Enums\ResourceState;
use App\Enums\SystemFilePurpose;
use App\Jobs\AutoTagResource;
use App\Jobs\EmbedFileChunks;
use App\Jobs\ExtractFileText;
use App\Jobs\IndexResourceToElasticsearch;
use App\Jobs\UpsertResourceMetadataChunk;
use App\Models\Collection;
use App\Models\File;
use App\Models\FileChunk;
use App\Models\Organization;
use App\Models\Resource;
use App\Models\SearchIndex;
use App\Models\SystemFile;
use App\Models\User;
use App\Services\ElasticsearchService;
use App\Services\FileStorageService;
use App\Services\Interfaces\ResourceServiceInterface;
use App\Services\Processing\ChunkingService;
use App\Services\Processing\TikaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

/**
 * Integration tests for the ExtractFileText job.
 *
 * Tika HTTP calls are intercepted with Http::fake().
 * File I/O uses Storage::fake('local').
 * Queue::fake() prevents downstream ES jobs from executing.
 * No real Tika server, Elasticsearch, or S3 required.
 */
class ExtractFileTextJobTest extends TestCase
{
    use RefreshDatabase;

    private Resource $resource;

    private const PDF_XHTML = <<<'HTML'
        <html><body>
            <div class="page"><p>The quick brown fox jumps over the lazy dog near the river bank.</p></div>
            <div class="page"><p>Lorem ipsum dolor sit amet consectetur adipiscing elit sed do eiusmod.</p></div>
        </body></html>
        HTML;

    private const TIKA_META = ['Content-Type' => 'application/pdf', 'Author' => 'Test', 'dc:title' => 'Doc'];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Queue::fake();

        $user = User::factory()->create();
        $organization = Organization::factory()->create();
        $collection = Collection::factory()->create([
            'organization_id' => $organization->id,
            'user_owner_id' => $user->id,
        ]);

        $this->resource = Resource::factory()->create([
            'collection_id' => $collection->id,
            'organization_id' => $organization->id,
            'user_owner_id' => $user->id,
            'state' => ResourceState::LIVE->value,
        ]);
    }

    // =========================================================================
    // Text extraction (PDF / DOCX / etc.)
    // =========================================================================

    public function test_text_extractable_file_creates_system_file_and_chunks(): void
    {
        $this->fakeTika(self::PDF_XHTML, self::TIKA_META);
        $file = $this->fakePdfFile();

        $this->runJob($file);

        // One active SystemFile created
        $this->assertDatabaseHas('system_files', [
            'resource_id' => $this->resource->id,
            'source_file_id' => $file->id,
            'purpose' => SystemFilePurpose::EXTRACTED_TEXT->value,
            'is_active' => true,
        ]);

        // Chunk manifest rows created (one per page in the XHTML)
        $this->assertSame(2, FileChunk::where('resource_id', $this->resource->id)->count());
    }

    public function test_system_file_metadata_contains_chunk_count_and_tika_metadata(): void
    {
        $this->fakeTika(self::PDF_XHTML, self::TIKA_META);
        $file = $this->fakePdfFile();

        $this->runJob($file);

        $systemFile = SystemFile::where('source_file_id', $file->id)->first();
        $meta = $systemFile->metadata;

        $this->assertSame(2, $meta['chunk_count']);
        $this->assertArrayHasKey('extracted_at', $meta);
        $this->assertSame('application/pdf', $meta['tika_metadata']['Content-Type']);
    }

    public function test_chunk_rows_have_correct_sequence_and_page_numbers(): void
    {
        $this->fakeTika(self::PDF_XHTML, self::TIKA_META);
        $file = $this->fakePdfFile();

        $this->runJob($file);

        $chunks = FileChunk::where('resource_id', $this->resource->id)
            ->orderBy('sequence')
            ->get();

        $this->assertSame(0, $chunks[0]->sequence);
        $this->assertSame(1, $chunks[0]->page_number);
        $this->assertSame(1, $chunks[1]->sequence);
        $this->assertSame(2, $chunks[1]->page_number);
    }

    public function test_gzip_archive_is_written_to_storage(): void
    {
        $this->fakeTika(self::PDF_XHTML, self::TIKA_META);
        $file = $this->fakePdfFile();

        $this->runJob($file);

        $systemFile = SystemFile::where('source_file_id', $file->id)->first();
        Storage::disk('local')->assertExists($systemFile->path);
    }

    public function test_chunk_content_is_readable_from_gzip_archive(): void
    {
        $this->fakeTika(self::PDF_XHTML, self::TIKA_META);
        $file = $this->fakePdfFile();

        $this->runJob($file);

        $systemFile = SystemFile::where('source_file_id', $file->id)->first();
        $chunks = FileStorageService::readExtractedText($systemFile->path, $systemFile->disk);

        $this->assertCount(2, $chunks);
        $this->assertStringContainsString('fox', $chunks[0]['content']);
        $this->assertStringContainsString('Lorem', $chunks[1]['content']);
    }

    public function test_index_resource_job_is_dispatched_after_extraction(): void
    {
        $this->fakeTika(self::PDF_XHTML, self::TIKA_META);
        $file = $this->fakePdfFile();

        $this->runJob($file);

        Queue::assertPushed(IndexResourceToElasticsearch::class, function ($job) {
            return $job->getResourceId() === $this->resource->id;
        });
    }

    public function test_embed_file_chunks_job_is_dispatched_after_text_extraction(): void
    {
        $this->fakeTika(self::PDF_XHTML, self::TIKA_META);
        $file = $this->fakePdfFile();

        $this->runJob($file);

        Queue::assertPushed(EmbedFileChunks::class, function ($job) use ($file) {
            return $job->getFileId() === $file->id;
        });
    }

    public function test_autotag_job_is_dispatched_when_enabled(): void
    {
        Config::set('autotagging.enabled', true);
        $this->fakeTika(self::PDF_XHTML, self::TIKA_META);
        $file = $this->fakePdfFile();

        $this->runJob($file);

        Queue::assertPushed(AutoTagResource::class, function ($job) use ($file) {
            return $job->getResourceId() === $file->resource_id
                && $job->getSourceFileId() === $file->id;
        });
    }

    public function test_autotag_job_not_dispatched_when_disabled(): void
    {
        Config::set('autotagging.enabled', false);
        $this->fakeTika(self::PDF_XHTML, self::TIKA_META);
        $file = $this->fakePdfFile();

        $this->runJob($file);

        Queue::assertNotPushed(AutoTagResource::class);
    }

    public function test_autotag_job_not_dispatched_for_image(): void
    {
        Config::set('autotagging.enabled', true);
        Http::fake(['*/meta' => Http::response(json_encode(['Content-Type' => 'image/jpeg']), 200)]);

        $file = File::factory()->forResource($this->resource->id)->canonical()->create([
            'disk' => 'local',
            'path' => 'test/photo2.jpg',
            'mime_type' => 'image/jpeg',
        ]);
        Storage::disk('local')->put('test/photo2.jpg', 'fake jpeg');

        $this->runJob($file);

        Queue::assertNotPushed(AutoTagResource::class);
    }

    public function test_embed_file_chunks_job_not_dispatched_for_image(): void
    {
        Http::fake(['*/meta' => Http::response(json_encode(['Content-Type' => 'image/jpeg']), 200)]);

        $file = File::factory()->forResource($this->resource->id)->canonical()->create([
            'disk' => 'local',
            'path' => 'test/photo.jpg',
            'mime_type' => 'image/jpeg',
        ]);
        Storage::disk('local')->put('test/photo.jpg', 'fake jpeg');

        $this->runJob($file);

        Queue::assertNotPushed(EmbedFileChunks::class);
    }

    // =========================================================================
    // Re-extraction (idempotency)
    // =========================================================================

    public function test_re_extraction_deactivates_previous_system_file(): void
    {
        $this->fakeTika(self::PDF_XHTML, self::TIKA_META);
        $file = $this->fakePdfFile();

        // First extraction
        $this->runJob($file);
        $firstId = SystemFile::where('source_file_id', $file->id)->first()->id;

        // Second extraction (e.g. re-trigger)
        Http::fake([
            '*/tika' => Http::response('<html><body><div class="page"><p>New content.</p></div></body></html>', 200),
            '*/meta' => Http::response(json_encode(self::TIKA_META), 200),
        ]);
        $this->runJob($file);

        // Old record deactivated
        $this->assertDatabaseHas('system_files', ['id' => $firstId, 'is_active' => false]);

        // New record active
        $active = SystemFile::where('source_file_id', $file->id)->where('is_active', true)->get();
        $this->assertCount(1, $active);
    }

    public function test_re_extraction_replaces_chunk_rows(): void
    {
        $singlePageXhtml = '<html><body><div class="page"><p>Single page.</p></div></body></html>';

        // Pre-configure both Tika calls in sequence: first run gets 2-page, second gets 1-page
        Http::fake([
            '*/tika' => Http::sequence()
                ->push(self::PDF_XHTML, 200)
                ->push($singlePageXhtml, 200),
            '*/meta' => Http::response(json_encode(self::TIKA_META), 200),
        ]);

        $file = $this->fakePdfFile();

        $this->runJob($file);
        $this->assertSame(2, FileChunk::where('source_file_id', $file->id)->count());

        $this->runJob($file);
        $this->assertSame(1, FileChunk::where('source_file_id', $file->id)->count());
    }

    public function test_extraction_purges_stale_chunk_vectors_from_es(): void
    {
        $searchIndex = SearchIndex::create([
            'index_name' => 'tydal_test',
            'display_name' => 'Test Index',
            'is_active' => true,
        ]);
        $this->resource->collection->update(['index_id' => $searchIndex->id]);

        $es = Mockery::mock(ElasticsearchService::class)->shouldIgnoreMissing();
        $es->shouldReceive('buildChunksIndexName')->with('tydal_test')->andReturn('tydal_test_chunks');
        $this->app->instance(ElasticsearchService::class, $es);

        $this->fakeTika(self::PDF_XHTML, self::TIKA_META);
        $file = $this->fakePdfFile();

        // Purged on every extraction pass: replaced manifest rows get fresh
        // UUIDs, so EmbedFileChunks' upserts can never overwrite the previous
        // generation's ES documents.
        $es->shouldReceive('invalidateChunksByFileId')
            ->twice()
            ->with('tydal_test_chunks', $file->id);

        $this->runJob($file);
        $this->runJob($file);
    }

    // =========================================================================
    // Metadata-only extraction (images, audio, video)
    // =========================================================================

    public function test_image_file_creates_tika_metadata_system_file(): void
    {
        Http::fake(['*/meta' => Http::response(json_encode([
            'Content-Type' => 'image/jpeg',
            'Image Width' => '3000',
            'Image Height' => '2000',
        ]), 200)]);

        $file = File::factory()->forResource($this->resource->id)->canonical()->create([
            'disk' => 'local',
            'path' => 'test/image.jpg',
            'mime_type' => 'image/jpeg',
        ]);
        Storage::disk('local')->put('test/image.jpg', 'fake jpeg');

        $this->runJob($file);

        $this->assertDatabaseHas('system_files', [
            'resource_id' => $this->resource->id,
            'source_file_id' => $file->id,
            'purpose' => SystemFilePurpose::TIKA_METADATA->value,
            'is_active' => true,
        ]);
        $this->assertSame(0, FileChunk::where('resource_id', $this->resource->id)->count());

        // Metadata-only files never reach EmbedFileChunks, so the metadata
        // chunk (the resource's only presence in vector space) is dispatched
        // from the extraction job itself.
        Queue::assertPushed(UpsertResourceMetadataChunk::class, function ($job) {
            return $job->getResourceId() === $this->resource->id;
        });
    }

    public function test_image_tika_metadata_contains_exif_data(): void
    {
        $exif = ['Content-Type' => 'image/jpeg', 'Image Width' => '3000', 'GPS:Latitude' => '40.7128'];
        Http::fake(['*/meta' => Http::response(json_encode($exif), 200)]);

        $file = File::factory()->forResource($this->resource->id)->canonical()->create([
            'disk' => 'local',
            'path' => 'test/photo.jpg',
            'mime_type' => 'image/jpeg',
        ]);
        Storage::disk('local')->put('test/photo.jpg', 'fake jpeg');

        $this->runJob($file);

        $systemFile = SystemFile::where('source_file_id', $file->id)->first();
        $this->assertSame('40.7128', $systemFile->metadata['tika_metadata']['GPS:Latitude']);
    }

    // =========================================================================
    // Unsupported MIME type
    // =========================================================================

    public function test_unsupported_mime_type_creates_no_records(): void
    {
        $file = File::factory()->forResource($this->resource->id)->canonical()->create([
            'disk' => 'local',
            'path' => 'test/archive.zip',
            'mime_type' => 'application/zip',
        ]);
        Storage::disk('local')->put('test/archive.zip', 'fake zip');

        $this->runJob($file);

        $this->assertSame(0, SystemFile::where('resource_id', $this->resource->id)->count());
        $this->assertSame(0, FileChunk::where('resource_id', $this->resource->id)->count());
    }

    // =========================================================================
    // Failure handling
    // =========================================================================

    public function test_failed_hook_creates_failure_system_file(): void
    {
        $file = $this->fakePdfFile();

        $job = new ExtractFileText($file->id);
        $job->failed(new \RuntimeException('Tika connection refused'));

        $this->assertDatabaseHas('system_files', [
            'resource_id' => $this->resource->id,
            'source_file_id' => $file->id,
            'purpose' => SystemFilePurpose::EXTRACTED_TEXT->value,
            'is_active' => false,
        ]);

        $record = SystemFile::where('source_file_id', $file->id)->first();
        $this->assertSame('Tika connection refused', $record->metadata['error_message']);
        $this->assertArrayHasKey('failed_at', $record->metadata);
    }

    public function test_failed_hook_for_non_text_file_creates_no_record(): void
    {
        $file = File::factory()->forResource($this->resource->id)->create([
            'disk' => 'local',
            'path' => 'test/photo.jpg',
            'mime_type' => 'image/jpeg',
        ]);

        $job = new ExtractFileText($file->id);
        $job->failed(new \RuntimeException('Connection refused'));

        $this->assertSame(0, SystemFile::where('source_file_id', $file->id)->count());
    }

    public function test_missing_file_record_is_handled_gracefully(): void
    {
        $this->fakeTika(self::PDF_XHTML, self::TIKA_META);

        $mockResourceService = Mockery::mock(ResourceServiceInterface::class);
        $mockResourceService->shouldReceive('recalculatePromotedMetadata')->never();

        $job = new ExtractFileText('non-existent-file-id');
        $job->handle(new TikaService, new ChunkingService, $mockResourceService);

        // No exception, no records
        $this->assertSame(0, SystemFile::count());
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function fakePdfFile(): File
    {
        // ExtractFileText only recalculates promoted metadata for canonical files
        // (see ExtractFileText::handle), and runJob() asserts the call. Tests in
        // this file exercise a single file per resource, so it should be canonical.
        $file = File::factory()->forResource($this->resource->id)->canonical()->create([
            'disk' => 'local',
            'path' => 'test/document.pdf',
            'mime_type' => 'application/pdf',
        ]);
        Storage::disk('local')->put('test/document.pdf', '%PDF-1.4 fake content');

        return $file;
    }

    private function fakeTika(string $xhtml, array $meta): void
    {
        Http::fake([
            '*/tika' => Http::response($xhtml, 200),
            '*/meta' => Http::response(json_encode($meta), 200),
        ]);
    }

    private function runJob(File $file): void
    {
        $mockResourceService = Mockery::mock(ResourceServiceInterface::class);
        $mockResourceService->shouldReceive('recalculatePromotedMetadata')->once();

        (new ExtractFileText($file->id))->handle(new TikaService, new ChunkingService, $mockResourceService);
    }
}
