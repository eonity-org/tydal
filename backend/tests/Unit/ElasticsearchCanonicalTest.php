<?php

namespace Tests\Unit;

use App\Enums\ResourceState;
use App\Enums\SystemFilePurpose;
use App\Models\Collection;
use App\Models\File;
use App\Models\Organization;
use App\Models\Resource;
use App\Models\SearchIndex;
use App\Models\SystemFile;
use App\Models\User;
use App\Services\ElasticsearchService;
use Elastic\Elasticsearch\ClientInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery;
use ReflectionMethod;
use ReflectionProperty;
use Tests\TestCase;

/**
 * Unit tests for ElasticsearchService with the canonical file model.
 *
 * These tests cover:
 *  - buildDocument() — tika_metadata comes from Resource.promoted_file_metadata
 *  - buildExtractionData() — extracted_text is sourced from the canonical file only;
 *                            non-canonical file text is excluded
 */
class ElasticsearchCanonicalTest extends TestCase
{
    use RefreshDatabase;

    private ElasticsearchService $service;

    private mixed $mockClient;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->service = new ElasticsearchService;
        $this->mockClient = Mockery::mock(ClientInterface::class);

        $prop = new ReflectionProperty(ElasticsearchService::class, 'client');
        $prop->setAccessible(true);
        $prop->setValue($this->service, $this->mockClient);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function callPrivate(string $method, mixed ...$args): mixed
    {
        $m = new ReflectionMethod(ElasticsearchService::class, $method);
        $m->setAccessible(true);

        return $m->invoke($this->service, ...$args);
    }

    private function makeResourceWithIndex(): array
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();

        $collection = Collection::factory()->create([
            'organization_id' => $org->id,
            'user_owner_id' => $user->id,
            'index_id' => null,
        ]);

        $resource = Resource::factory()->create([
            'collection_id' => $collection->id,
            'organization_id' => $org->id,
            'user_owner_id' => $user->id,
            'state' => ResourceState::LIVE->value,
        ]);

        $index = SearchIndex::create([
            'index_name' => 'tydal_test',
            'display_name' => 'Test Index',
            'is_active' => true,
        ]);
        $collection->update(['index_id' => $index->id]);

        return [$resource->fresh(), 'tydal_test'];
    }

    private function makeFile(Resource $resource, string $role = 'supporting', bool $active = true): File
    {
        return File::factory()->create([
            'resource_id' => $resource->id,
            'role' => $role,
            'is_active' => $active,
            'disk' => 'local',
        ]);
    }

    private function fakeExtractedText(string $path, string ...$chunks): void
    {
        $payload = array_map(fn ($c) => ['content' => $c, 'sequence' => 0], $chunks);
        Storage::disk('local')->put($path, gzencode(json_encode($payload)));
    }

    private function makeExtractedTextSystemFile(Resource $resource, File $file, string $path): SystemFile
    {
        return SystemFile::create([
            'resource_id' => $resource->id,
            'source_file_id' => $file->id,
            'purpose' => SystemFilePurpose::EXTRACTED_TEXT->value,
            'filename' => basename($path),
            'mime_type' => 'application/octet-stream',
            'size' => 100,
            'path' => $path,
            'disk' => 'local',
            'is_active' => true,
            'metadata' => [],
        ]);
    }

    // =========================================================================
    // buildDocument — tika_metadata from promoted_file_metadata
    // =========================================================================

    public function test_build_document_uses_promoted_file_metadata_for_tika(): void
    {
        [$resource] = $this->makeResourceWithIndex();

        $promotedMeta = ['Author' => 'Promoted Author', 'Pages' => '42'];
        $resource->update(['promoted_file_metadata' => $promotedMeta]);

        // Non-canonical file with different tika data — must NOT appear in the ES doc
        $file = $this->makeFile($resource, 'supporting');
        SystemFile::create([
            'resource_id' => $resource->id,
            'source_file_id' => $file->id,
            'purpose' => SystemFilePurpose::TIKA_METADATA->value,
            'filename' => 'meta.json',
            'mime_type' => 'application/json',
            'size' => 50,
            'path' => 'meta/ignored.json',
            'disk' => 'local',
            'is_active' => true,
            'metadata' => ['tika_metadata' => ['Author' => 'Should Be Ignored']],
        ]);

        $resource->load(['collection', 'canonicalFile', 'systemFiles', 'workspaces', 'semanticTags']);

        $doc = $this->callPrivate('buildDocument', $resource);

        $this->assertSame($promotedMeta, $doc['tika_metadata']);
        $this->assertNotSame('Should Be Ignored', $doc['tika_metadata']['Author'] ?? null);
    }

    public function test_build_document_tika_metadata_is_null_when_no_promoted_metadata(): void
    {
        [$resource] = $this->makeResourceWithIndex();

        $resource->load(['collection', 'canonicalFile', 'systemFiles', 'workspaces', 'semanticTags']);

        $doc = $this->callPrivate('buildDocument', $resource);

        $this->assertNull($doc['tika_metadata']);
    }

    // =========================================================================
    // buildExtractionData — extracted_text from canonical file only
    // =========================================================================

    public function test_extraction_data_is_null_when_no_canonical_file(): void
    {
        [$resource] = $this->makeResourceWithIndex();

        $file = $this->makeFile($resource, 'supporting');
        $this->fakeExtractedText('text/supporting.gz', 'should not appear');
        $this->makeExtractedTextSystemFile($resource, $file, 'text/supporting.gz');

        $resource->load(['canonicalFile', 'systemFiles']);

        $result = $this->callPrivate('buildExtractionData', $resource);

        $this->assertNull($result);
    }

    public function test_extraction_data_includes_text_from_canonical_file(): void
    {
        [$resource] = $this->makeResourceWithIndex();

        $file = $this->makeFile($resource, 'canonical');
        $this->fakeExtractedText('text/canonical.gz', 'hello world');
        $this->makeExtractedTextSystemFile($resource, $file, 'text/canonical.gz');

        $resource->load(['canonicalFile', 'systemFiles']);

        $result = $this->callPrivate('buildExtractionData', $resource);

        $this->assertSame('hello world', $result);
    }

    public function test_extraction_data_excludes_non_canonical_file_text(): void
    {
        [$resource] = $this->makeResourceWithIndex();

        $canonical = $this->makeFile($resource, 'canonical');
        $supporting = $this->makeFile($resource, 'supporting');

        $this->fakeExtractedText('text/good.gz', 'included text');
        $this->fakeExtractedText('text/bad.gz', 'excluded text');

        $this->makeExtractedTextSystemFile($resource, $canonical, 'text/good.gz');
        $this->makeExtractedTextSystemFile($resource, $supporting, 'text/bad.gz');

        $resource->load(['canonicalFile', 'systemFiles']);

        $result = $this->callPrivate('buildExtractionData', $resource);

        $this->assertStringContainsString('included text', $result);
        $this->assertStringNotContainsString('excluded text', $result);
    }

    public function test_extraction_data_returns_null_when_storage_file_missing(): void
    {
        [$resource] = $this->makeResourceWithIndex();

        $file = $this->makeFile($resource, 'canonical');
        $this->makeExtractedTextSystemFile($resource, $file, 'text/missing.gz');

        $resource->load(['canonicalFile', 'systemFiles']);

        $result = $this->callPrivate('buildExtractionData', $resource);

        $this->assertNull($result);
    }

    // =========================================================================
    // indexResource — full document body uses promoted_file_metadata
    // =========================================================================

    public function test_index_resource_sends_promoted_metadata_as_tika_metadata(): void
    {
        [$resource, $indexName] = $this->makeResourceWithIndex();

        $promoted = ['Author' => 'ES Test Author'];
        $resource->update(['promoted_file_metadata' => $promoted]);

        $capturedBody = null;

        $this->mockClient
            ->shouldReceive('index')
            ->twice()
            ->withArgs(function (array $params) use (&$capturedBody, $resource): bool {
                if (($params['id'] ?? '') === $resource->id) {
                    $capturedBody = $params['body'];
                }

                return true;
            });

        $this->service->indexResource($resource);

        $this->assertNotNull($capturedBody, 'indexResource must call client->index() for the parent doc');
        $this->assertSame($promoted, $capturedBody['tika_metadata']);
    }
}
