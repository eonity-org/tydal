<?php

namespace Tests\Feature;

use App\Enums\ResourceState;
use App\Jobs\UpsertResourceMetadataChunk;
use App\Models\Collection;
use App\Models\CollectionScheme;
use App\Models\Organization;
use App\Models\Resource;
use App\Models\SearchIndex;
use App\Models\SemanticTag;
use App\Models\User;
use App\Services\ElasticsearchService;
use App\Services\Interfaces\ResourceServiceInterface;
use App\Services\Processing\Contracts\EmbeddingServiceInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Tests for UpsertResourceMetadataChunk job.
 *
 * Verifies that name, description, tags, and Tika metadata are all
 * correctly included in (or excluded from) the embedded content string.
 * ElasticsearchService and EmbeddingService are mocked — no real ES or Ollama required.
 */
class UpsertResourceMetadataChunkJobTest extends TestCase
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
            'accepted_mimetypes' => ['image/jpeg'],
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
            'promoted_file_metadata' => null,
        ]);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Run the job and capture the content string passed to the embedder.
     */
    private function runAndCapture(): string
    {
        $captured = '';

        $mockEs = Mockery::mock(ElasticsearchService::class);
        $mockEs->shouldReceive('buildChunksIndexName')->andReturn('tydal_test_chunks');
        $mockEs->shouldReceive('chunksIndexExists')->andReturn(true);
        $mockEs->shouldReceive('upsertChunkVector')
            ->once()
            ->withArgs(function ($index, $doc) use (&$captured) {
                $captured = $doc['content'];

                return true;
            });

        $mockEmbedder = Mockery::mock(EmbeddingServiceInterface::class);
        $mockEmbedder->shouldReceive('embed')
            ->once()
            ->andReturn([0.1, 0.2, 0.3]);

        // A fresh meta vector triggers a resource-mean recalculation
        $mockResourceService = Mockery::mock(ResourceServiceInterface::class);
        $mockResourceService->shouldReceive('recalculateResourceEmbedding')->once();

        (new UpsertResourceMetadataChunk($this->resource->id))->handle($mockEmbedder, $mockEs, $mockResourceService);

        return $captured;
    }

    // =========================================================================
    // Name / description / tags
    // =========================================================================

    public function test_content_includes_name_and_description(): void
    {
        $this->resource->update(['name' => 'Sunset over Barcelona', 'description' => 'Golden hour shot.']);

        $content = $this->runAndCapture();

        $this->assertStringContainsString('Resource: Sunset over Barcelona', $content);
        $this->assertStringContainsString('Description: Golden hour shot.', $content);
    }

    public function test_content_includes_active_semantic_tags(): void
    {
        $this->resource->update(['name' => 'Tagged Resource']);

        $tag = SemanticTag::factory()->create([
            'organization_id' => $this->resource->organization_id,
            'label' => 'Architecture',
            'is_active' => true,
        ]);
        $this->resource->semanticTags()->attach($tag->id);

        $content = $this->runAndCapture();

        $this->assertStringContainsString('Tags: Architecture', $content);
    }

    // =========================================================================
    // Tika metadata inclusion
    // =========================================================================

    public function test_tika_metadata_included_when_present(): void
    {
        $this->resource->update([
            'name' => 'Photo',
            'promoted_file_metadata' => [
                'tiff:Make' => 'Canon',
                'tiff:Model' => 'EOS R5',
                'exif:DateTimeOriginal' => '2023:08:15 14:30:00',
                'GPS Latitude' => '48.8566 N',
                'GPS Longitude' => '2.3522 E',
            ],
        ]);

        $content = $this->runAndCapture();

        $this->assertStringContainsString('File properties:', $content);
        $this->assertStringContainsString('Camera Make: Canon', $content);
        $this->assertStringContainsString('EOS R5', $content);
        $this->assertStringContainsString('48.8566 N', $content);
    }

    public function test_tika_x_headers_are_excluded(): void
    {
        $this->resource->update([
            'name' => 'Photo',
            'promoted_file_metadata' => [
                'tiff:Make' => 'Canon',
                'X-Parsed-By' => 'org.apache.tika.parser.image.ImageParser',
                'X-TIKA:content' => 'some internal value',
            ],
        ]);

        $content = $this->runAndCapture();

        $this->assertStringNotContainsString('X-Parsed-By', $content);
        $this->assertStringNotContainsString('X-TIKA', $content);
        $this->assertStringContainsString('Camera Make: Canon', $content);
    }

    public function test_tika_long_values_are_excluded(): void
    {
        $this->resource->update([
            'name' => 'Photo',
            'promoted_file_metadata' => [
                'tiff:Make' => 'Canon',
                'ThumbnailData' => str_repeat('A', 400), // base64 blob — must be dropped
            ],
        ]);

        $content = $this->runAndCapture();

        $this->assertStringNotContainsString('ThumbnailData', $content);
        $this->assertStringContainsString('Camera Make: Canon', $content);
    }

    public function test_no_file_properties_line_when_tika_metadata_empty(): void
    {
        $this->resource->update([
            'name' => 'Clean Resource',
            'promoted_file_metadata' => null,
        ]);

        $content = $this->runAndCapture();

        $this->assertStringNotContainsString('File properties:', $content);
    }

    public function test_tika_only_internal_keys_produces_no_file_properties_line(): void
    {
        $this->resource->update([
            'name' => 'Photo',
            'promoted_file_metadata' => [
                'X-Parsed-By' => 'org.apache.tika.parser.image.ImageParser',
                'Content-Type' => 'image/jpeg',
                'Content-Length' => '204800',
            ],
        ]);

        $content = $this->runAndCapture();

        $this->assertStringNotContainsString('File properties:', $content);
    }
}
