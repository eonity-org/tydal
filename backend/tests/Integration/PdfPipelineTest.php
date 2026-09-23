<?php

namespace Tests\Integration;

use App\Enums\ResourceState;
use App\Enums\SystemFilePurpose;
use App\Jobs\EmbedFileChunks;
use App\Jobs\ExtractFileText;
use App\Jobs\IndexResourceToElasticsearch;
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
use App\Services\Interfaces\ResourceServiceInterface;
use App\Services\Processing\ChunkingService;
use App\Services\Processing\Contracts\EmbeddingServiceInterface;
use App\Services\Processing\TikaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Full pipeline integration tests using a real PDF fixture.
 *
 * Prerequisites (all must be running):
 *   - Apache Tika on port 9998
 *   - A configured embedding provider when running embedding tests
 *   - Elasticsearch on port 9200 (with tydal_multimedia_chunks index)
 *
 * Run with:
 *   php artisan test --group=integration
 *   composer test -- --group integration
 *
 * The fixture is a 21-page Spanish PDF (travel guide for Ireland, 130 KB).
 * Created by ChatGPT Deep Research, produced by WeasyPrint 65.1.
 */
#[Group('integration')]
class PdfPipelineTest extends TestCase
{
    use RefreshDatabase;

    private const FIXTURE = __DIR__.'/../fixtures/sample.pdf';

    private Resource $resource;

    private File $file;

    private Collection $collection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->requireIntegrationServices();

        // Prevent ES resource indexing from polluting the real tydal_multimedia index
        Queue::fake([IndexResourceToElasticsearch::class]);

        // Use real local storage so Tika can read the file by path
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $searchIndex = SearchIndex::updateOrCreate(
            ['index_name' => 'tydal_multimedia'],
            ['display_name' => 'Integration Test Index', 'is_active' => true]
        );

        $scheme = CollectionScheme::create([
            'name' => 'integration-scheme',
            'display_name' => 'Integration Scheme',
            'accepted_mimetypes' => ['application/pdf'],
            'is_system' => false,
            'fields' => [],
        ]);

        $this->collection = Collection::factory()->create([
            'organization_id' => $org->id,
            'user_owner_id' => $user->id,
            'scheme_id' => $scheme->id,
            'index_id' => $searchIndex->id,
        ]);

        $this->resource = Resource::factory()->create([
            'collection_id' => $this->collection->id,
            'organization_id' => $org->id,
            'user_owner_id' => $user->id,
            'name' => 'Guía Completa de Viaje Familiar por Irlanda',
            'state' => ResourceState::LIVE->value,
        ]);

        // Copy fixture into local storage so Storage::disk('local')->path() resolves correctly
        $storagePath = 'test-fixtures/sample.pdf';
        Storage::disk('local')->put($storagePath, file_get_contents(self::FIXTURE));

        $this->file = File::factory()->forResource($this->resource->id)->create([
            'disk' => 'local',
            'path' => $storagePath,
            'mime_type' => 'application/pdf',
            'size' => filesize(self::FIXTURE),
        ]);
    }

    // =========================================================================
    // Stage 1 — Tika text extraction
    // =========================================================================

    public function test_tika_extracts_text_from_real_pdf(): void
    {
        $tika = new TikaService;
        $result = $tika->extractText(self::FIXTURE);

        $this->assertArrayHasKey('xhtml', $result);
        $this->assertArrayHasKey('plain_text', $result);
        $this->assertArrayHasKey('char_count', $result);

        $this->assertNotEmpty($result['xhtml']);
        $this->assertNotEmpty($result['plain_text']);
        $this->assertGreaterThan(1000, $result['char_count']);

        // The PDF is about Ireland — verify some content is present
        $this->assertStringContainsStringIgnoringCase('Irlanda', $result['plain_text']);
    }

    public function test_tika_extracts_metadata_from_real_pdf(): void
    {
        $tika = new TikaService;
        $meta = $tika->extractMetadata(self::FIXTURE);

        $this->assertArrayHasKey('Content-Type', $meta);
        $this->assertSame('application/pdf', $meta['Content-Type']);

        // 21-page PDF
        $pages = $meta['xmpTPg:NPages'] ?? $meta['pdf:charsPerPage'] ?? null;
        if (is_array($pages)) {
            $this->assertCount(21, $pages);
        } else {
            $this->assertSame('21', (string) $pages);
        }
    }

    // =========================================================================
    // Stage 2 — Chunking
    // =========================================================================

    public function test_chunking_service_produces_chunks_for_real_pdf(): void
    {
        $tika = new TikaService;
        $chunker = new ChunkingService;

        $extracted = $tika->extractText(self::FIXTURE);
        $chunks = $chunker->chunk($extracted['xhtml'], 'application/pdf', 400, 50);

        // 21 pages × content → should produce many chunks
        $this->assertNotEmpty($chunks);
        $this->assertGreaterThan(20, count($chunks));

        // Verify chunk structure
        $first = $chunks[0];
        $this->assertArrayHasKey('sequence', $first);
        $this->assertArrayHasKey('page_number', $first);
        $this->assertArrayHasKey('content', $first);
        $this->assertArrayHasKey('word_count', $first);
        $this->assertArrayHasKey('char_start', $first);
        $this->assertArrayHasKey('char_end', $first);

        // PDF is structural — each chunk must stay within a single page
        $pageNumbers = array_column($chunks, 'page_number');
        $this->assertNotContains(null, $pageNumbers, 'PDF chunks must have page numbers');

        // Sequences must be consecutive starting at 0
        $sequences = array_column($chunks, 'sequence');
        $this->assertSame(range(0, count($chunks) - 1), $sequences);

        echo "\n  [INFO] Chunks produced: ".count($chunks).PHP_EOL;
    }

    public function test_chunks_never_cross_page_boundaries(): void
    {
        $tika = new TikaService;
        $chunker = new ChunkingService;

        $extracted = $tika->extractText(self::FIXTURE);
        $chunks = $chunker->chunk($extracted['xhtml'], 'application/pdf');

        // Group chunks by page_number — each page should have its own set
        $byPage = [];
        foreach ($chunks as $chunk) {
            $byPage[$chunk['page_number']][] = $chunk['sequence'];
        }

        // Sequences within each page must be contiguous
        foreach ($byPage as $page => $seqs) {
            sort($seqs);
            $this->assertSame(
                range($seqs[0], $seqs[count($seqs) - 1]),
                $seqs,
                "Page {$page} has non-contiguous chunk sequences"
            );
        }
    }

    // =========================================================================
    // Stage 3 — Full ExtractFileText job
    // =========================================================================

    public function test_extract_file_text_job_runs_end_to_end(): void
    {
        // Isolate extraction: fake all downstream jobs (embedding, auto-tagging,
        // image analysis) so the test doesn't reach Ollama/other AI services.
        Queue::fake();

        $job = new ExtractFileText($this->file->id);
        $job->handle(new TikaService, new ChunkingService, app(ResourceServiceInterface::class));

        // SystemFile created and active
        $systemFile = SystemFile::where('source_file_id', $this->file->id)
            ->where('purpose', SystemFilePurpose::EXTRACTED_TEXT->value)
            ->where('is_active', true)
            ->first();

        $this->assertNotNull($systemFile, 'No active SystemFile created');
        $this->assertGreaterThan(0, $systemFile->size);
        $this->assertArrayHasKey('chunk_count', $systemFile->metadata);
        $this->assertArrayHasKey('tika_metadata', $systemFile->metadata);
        $this->assertGreaterThan(0, $systemFile->metadata['chunk_count']);

        // FileChunk manifest created
        $chunkCount = FileChunk::where('source_file_id', $this->file->id)->count();
        $this->assertSame($systemFile->metadata['chunk_count'], $chunkCount);

        // Gzip archive is readable
        $chunks = FileStorageService::readExtractedText($systemFile->path, $systemFile->disk);
        $this->assertNotEmpty($chunks);
        $this->assertSame($chunkCount, count($chunks));

        echo "\n  [INFO] Chunks: {$chunkCount}, Archive size: {$systemFile->size} bytes".PHP_EOL;
    }

    public function test_gzip_archive_chunks_have_valid_content(): void
    {
        // Isolate extraction from downstream AI jobs (see test above).
        Queue::fake();

        (new ExtractFileText($this->file->id))->handle(new TikaService, new ChunkingService, app(ResourceServiceInterface::class));

        $systemFile = SystemFile::where('source_file_id', $this->file->id)
            ->where('is_active', true)->first();

        $chunks = FileStorageService::readExtractedText($systemFile->path, $systemFile->disk);

        foreach ($chunks as $i => $chunk) {
            $this->assertNotEmpty($chunk['content'], "Chunk {$i} has empty content");
            $this->assertGreaterThan(0, $chunk['word_count'], "Chunk {$i} has zero word_count");
        }

        // Content should be in Spanish (the PDF language)
        $allText = implode(' ', array_column($chunks, 'content'));
        $this->assertStringContainsStringIgnoringCase('Irlanda', $allText);
    }

    // =========================================================================
    // Stage 4 — configured embedding provider
    // =========================================================================

    public function test_configured_embedding_service_embeds_chunk_content(): void
    {
        $this->requireConfiguredEmbeddingService();

        $embedder = app(EmbeddingServiceInterface::class);
        $vector = $embedder->embed('Irlanda ofrece una fascinante combinación de historia');

        $this->assertIsArray($vector);
        $this->assertNotEmpty($vector);
        $this->assertGreaterThan(100, count($vector));

        // Values should be floats in a reasonable range
        foreach (array_slice($vector, 0, 5) as $val) {
            $this->assertIsFloat($val);
        }

        echo "\n  [INFO] Embedding dimensions: ".count($vector).PHP_EOL;
    }

    public function test_embed_file_chunks_job_inserts_vectors_into_es(): void
    {
        $this->requireConfiguredEmbeddingService();
        $this->requireElasticsearch();

        Queue::fake([EmbedFileChunks::class]);

        // Run extraction first
        (new ExtractFileText($this->file->id))->handle(new TikaService, new ChunkingService, app(ResourceServiceInterface::class));

        // Run embedding directly — Queue::fake only prevents dispatch, not manual handle() calls
        $embedder = app(EmbeddingServiceInterface::class);
        $es = new ElasticsearchService;

        $job = new EmbedFileChunks($this->file->id);
        $job->handle($embedder, $es);

        // Verify vectors landed in ES
        $chunksIndex = $es->buildChunksIndexName('tydal_multimedia');
        $chunkCount = FileChunk::where('source_file_id', $this->file->id)->count();

        // Give ES a moment to refresh
        sleep(1);

        $response = Http::post(
            config('elasticsearch.host')."/{$chunksIndex}/_count",
            ['query' => ['term' => ['file_id' => $this->file->id]]]
        );

        $esCount = $response->json('count', 0);

        $this->assertSame($chunkCount, $esCount, "ES chunk count ({$esCount}) does not match DB chunk count ({$chunkCount})");

        echo "\n  [INFO] Vectors in ES: {$esCount}".PHP_EOL;
    }

    public function test_list_chunks_for_resource_returns_real_text_in_reading_order(): void
    {
        $this->requireConfiguredEmbeddingService();
        $this->requireElasticsearch();

        Queue::fake([EmbedFileChunks::class]);

        (new ExtractFileText($this->file->id))->handle(new TikaService, new ChunkingService, app(ResourceServiceInterface::class));

        $embedder = app(EmbeddingServiceInterface::class);
        $es = new ElasticsearchService;
        (new EmbedFileChunks($this->file->id))->handle($embedder, $es);

        sleep(1); // give ES a moment to refresh

        $chunksIndex = $es->buildChunksIndexName('tydal_multimedia');
        $chunks = $es->listChunksForResource($chunksIndex, $this->resource->id);

        $dbChunkCount = FileChunk::where('source_file_id', $this->file->id)->count();

        // The resource-metadata chunk (id "meta-{resourceId}") is real in this
        // index too — EmbedFileChunks dispatches UpsertResourceMetadataChunk,
        // and the sync queue driver runs it inline. Confirms the filter works
        // against a genuine meta chunk, not just a mocked one.
        $this->assertNotEmpty($chunks);
        $this->assertCount($dbChunkCount, $chunks, 'listChunksForResource should return exactly the real per-file chunks, excluding the meta chunk');

        foreach ($chunks as $chunk) {
            $this->assertArrayNotHasKey('vector', $chunk, 'raw chunk vectors must never be exposed');
            $this->assertFalse(str_starts_with($chunk['chunk_id'], 'meta-'));
        }

        $sequences = array_column($chunks, 'sequence');
        $this->assertSame($sequences, collect($sequences)->sort()->values()->all(), 'chunks must come back in reading order');

        echo "\n  [INFO] listChunksForResource returned ".count($chunks).' real chunks'.PHP_EOL;
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Skip the test if required services are not reachable.
     */
    private function requireIntegrationServices(): void
    {
        // Check Tika
        try {
            $tika = Http::timeout(3)->get(config('tika.url').'/tika');
            if (! $tika->successful()) {
                $this->markTestSkipped('Tika server not available at '.config('tika.url'));
            }
        } catch (\Throwable) {
            $this->markTestSkipped('Tika server not reachable.');
        }

        // Embedding tests validate the configured provider separately. Here we
        // just ensure the fixture file exists so Tika-only tests can still run.
        if (! file_exists(self::FIXTURE)) {
            $this->markTestSkipped('PDF fixture not found at '.self::FIXTURE);
        }
    }

    private function requireConfiguredEmbeddingService(): void
    {
        $driver = (string) config('embedding.driver', 'ollama');

        match ($driver) {
            'jina' => $this->requireConfigValue('embedding.jina.api_key', 'Jina API key is not configured.'),
            'voyage' => $this->requireConfigValue('embedding.voyage.api_key', 'Voyage API key is not configured.'),
            default => $this->requireOllama(),
        };
    }

    private function requireConfigValue(string $key, string $message): void
    {
        if (trim((string) config($key, '')) === '') {
            $this->markTestSkipped($message);
        }
    }

    private function requireElasticsearch(): void
    {
        $host = rtrim((string) config('elasticsearch.host'), '/');

        if ($host === '') {
            $this->markTestSkipped('Elasticsearch host is not configured.');
        }

        try {
            $response = Http::timeout(3)->get($host);
            if (! $response->successful()) {
                $this->markTestSkipped('Elasticsearch server not available at '.$host);
            }
        } catch (\Throwable) {
            $this->markTestSkipped('Elasticsearch server not reachable at '.$host);
        }
    }

    private function requireOllama(): void
    {
        $host = rtrim((string) config('embedding.ollama.host'), '/');

        if ($host === '') {
            $this->markTestSkipped('Ollama host is not configured.');
        }

        try {
            $response = Http::timeout(3)->get($host.'/api/tags');
            if (! $response->successful()) {
                $this->markTestSkipped('Ollama server not available at '.$host);
            }
        } catch (\Throwable) {
            $this->markTestSkipped('Ollama server not reachable at '.$host);
        }
    }

    protected function tearDown(): void
    {
        try {
            $es = new ElasticsearchService;
            $chunksIndex = $es->buildChunksIndexName('tydal_multimedia');
            // Clean up chunk vectors written during embedding test
            $es->invalidateChunksByFileId($chunksIndex, $this->file->id ?? '');
            // Clean up resource document if IndexResourceToElasticsearch ran
            $es->deleteResource($this->resource->id ?? '');
        } catch (\Throwable) {
            // best-effort
        }

        // Remove fixture copy from local storage
        Storage::disk('local')->delete('test-fixtures/sample.pdf');

        parent::tearDown();
    }
}
