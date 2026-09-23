<?php

namespace Tests\Feature;

use App\Enums\FileRole;
use App\Enums\ResourceState;
use App\Models\Collection;
use App\Models\CollectionScheme;
use App\Models\File;
use App\Models\Organization;
use App\Models\Resource;
use App\Models\SearchIndex;
use App\Models\User;
use App\Services\ElasticsearchService;
use App\Services\Interfaces\ResourceServiceInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Epic 1.1 (§8) — resource-level mean embedding: the canonical semantic
 * vector = mean of the contributing files' chunk vectors. Canonical is the
 * sole contributor; components contribute equally; supporting never does.
 */
class ResourceEmbeddingTest extends TestCase
{
    use RefreshDatabase;

    private Resource $resource;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $org = Organization::factory()->create();

        $searchIndex = SearchIndex::create([
            'index_name' => 'tydal_test',
            'display_name' => 'Test Index',
            'is_active' => true,
        ]);

        $scheme = CollectionScheme::create([
            'name' => 'embed-scheme',
            'display_name' => 'Embed Scheme',
            'accepted_mimetypes' => [],
            'is_system' => false,
            'fields' => [],
        ]);

        $collection = Collection::factory()->create([
            'organization_id' => $org->id,
            'user_owner_id' => $user->id,
            'scheme_id' => $scheme->id,
            'index_id' => $searchIndex->id,
        ]);

        $this->resource = Resource::factory()->create([
            'organization_id' => $org->id,
            'collection_id' => $collection->id,
            'user_owner_id' => $user->id,
            'state' => ResourceState::LIVE->value,
        ]);
    }

    private function mockEs(array $expectedFileIds, array $vectors, ?array $metaVector = null): void
    {
        $mock = Mockery::mock(ElasticsearchService::class);
        $mock->shouldReceive('buildChunksIndexName')->with('tydal_test')->andReturn('tydal_test_chunks');
        // The embedding update re-triggers ES indexing via the model hook (sync queue in tests)
        $mock->shouldReceive('indexResource');
        $mock->shouldReceive('indexResourceIntoVaults');
        $mock->shouldReceive('fetchChunkVectors')
            ->once()
            ->withArgs(function (string $index, string $resourceId, array $fileIds) use ($expectedFileIds) {
                return $index === 'tydal_test_chunks'
                    && $resourceId === $this->resource->id
                    && $fileIds === $expectedFileIds;
            })
            ->andReturn($vectors);
        // Consulted only when no content chunk vectors exist (chunkless fallback)
        $mock->shouldReceive('fetchMetaChunkVector')
            ->with('tydal_test_chunks', $this->resource->id)
            ->andReturn($metaVector);
        $this->instance(ElasticsearchService::class, $mock);
    }

    public function test_mean_of_component_chunk_vectors_is_stored(): void
    {
        $first = File::factory()->for($this->resource)->create(['role' => FileRole::COMPONENT, 'position' => 1]);
        $second = File::factory()->for($this->resource)->create(['role' => FileRole::COMPONENT, 'position' => 2]);
        File::factory()->for($this->resource)->create(['role' => FileRole::SUPPORTING]); // excluded

        $this->mockEs([$first->id, $second->id], [[1.0, 2.0], [3.0, 6.0]]);

        app(ResourceServiceInterface::class)->recalculateResourceEmbedding($this->resource);

        $fresh = $this->resource->fresh();
        $this->assertEquals([2.0, 4.0], $fresh->embedding);
        $this->assertNotNull($fresh->embedding_updated_at);
    }

    public function test_canonical_is_the_sole_embedding_contributor(): void
    {
        $canonical = File::factory()->for($this->resource)->create(['role' => FileRole::CANONICAL]);
        File::factory()->for($this->resource)->create(['role' => FileRole::SUPPORTING]);

        $this->mockEs([$canonical->id], [[0.5, 0.5]]);

        app(ResourceServiceInterface::class)->recalculateResourceEmbedding($this->resource);

        $this->assertEquals([0.5, 0.5], $this->resource->fresh()->embedding);
    }

    public function test_no_vectors_clears_the_embedding(): void
    {
        $canonical = File::factory()->for($this->resource)->create(['role' => FileRole::CANONICAL]);
        $this->resource->update(['embedding' => [9.0, 9.0], 'embedding_updated_at' => now()]);

        $this->mockEs([$canonical->id], []);

        app(ResourceServiceInterface::class)->recalculateResourceEmbedding($this->resource);

        $fresh = $this->resource->fresh();
        $this->assertNull($fresh->embedding);
        $this->assertNull($fresh->embedding_updated_at);
    }

    public function test_unchanged_embedding_does_not_touch_the_resource(): void
    {
        $canonical = File::factory()->for($this->resource)->create(['role' => FileRole::CANONICAL]);
        $this->resource->update(['embedding' => [1.0, 2.0]]);
        $updatedAt = $this->resource->fresh()->updated_at;

        $this->mockEs([$canonical->id], [[1.0, 2.0]]);

        $this->travel(1)->minutes();
        app(ResourceServiceInterface::class)->recalculateResourceEmbedding($this->resource->fresh());

        $this->assertEquals($updatedAt, $this->resource->fresh()->updated_at);
    }

    public function test_chunkless_resource_falls_back_to_the_meta_chunk_vector(): void
    {
        // An image: canonical file exists but produced no content chunks —
        // the synthetic metadata chunk becomes the resource embedding.
        $canonical = File::factory()->for($this->resource)->create(['role' => FileRole::CANONICAL]);

        $this->mockEs([$canonical->id], [], metaVector: [0.25, 0.75]);

        app(ResourceServiceInterface::class)->recalculateResourceEmbedding($this->resource);

        $this->assertEquals([0.25, 0.75], $this->resource->fresh()->embedding);
    }
}
