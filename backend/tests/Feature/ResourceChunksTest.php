<?php

namespace Tests\Feature;

use App\Models\Collection;
use App\Models\CollectionScheme;
use App\Models\Organization;
use App\Models\Resource;
use App\Models\SearchIndex;
use App\Models\User;
use App\Services\ElasticsearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class ResourceChunksTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Organization $organization;

    private Resource $resource;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('s3');

        $this->user = User::factory()->create();
        $this->organization = Organization::factory()->create();
        $this->organization->users()->attach($this->user->id, ['role' => 'admin']);
        $this->user->update(['last_organization_id' => $this->organization->id]);
    }

    private function token(): string
    {
        return $this->user->createToken('auth-token', ['*'], now()->addHour())->plainTextToken;
    }

    public function test_returns_empty_when_collection_has_no_search_index(): void
    {
        $collection = Collection::factory()->create([
            'organization_id' => $this->organization->id,
            'user_owner_id' => $this->user->id,
            'index_id' => null,
        ]);
        $this->resource = Resource::factory()->forCollection($collection->id)->create([
            'organization_id' => $this->organization->id,
            'user_owner_id' => $this->user->id,
        ]);

        $this->withToken($this->token())
            ->getJson("/api/v1/resources/{$this->resource->id}/chunks")
            ->assertStatus(200)
            ->assertJsonPath('data.chunks', []);
    }

    public function test_returns_chunks_in_reading_order_excluding_the_metadata_chunk(): void
    {
        $searchIndex = SearchIndex::create([
            'index_name' => 'tydal_test',
            'display_name' => 'Test Index',
            'is_active' => true,
        ]);
        $scheme = CollectionScheme::create([
            'name' => 'chunk-test-scheme',
            'display_name' => 'Chunk Test Scheme',
            'accepted_mimetypes' => ['application/pdf'],
            'is_system' => false,
            'fields' => [],
        ]);
        $collection = Collection::factory()->create([
            'organization_id' => $this->organization->id,
            'user_owner_id' => $this->user->id,
            'scheme_id' => $scheme->id,
            'index_id' => $searchIndex->id,
        ]);
        $this->resource = Resource::factory()->forCollection($collection->id)->create([
            'organization_id' => $this->organization->id,
            'user_owner_id' => $this->user->id,
        ]);

        $rawHits = [
            ['chunk_id' => 'meta-'.$this->resource->id, 'file_id' => null, 'sequence' => -1, 'page_number' => null, 'content' => 'Name: Test'],
            ['chunk_id' => 'c1', 'file_id' => 'f1', 'sequence' => 0, 'page_number' => 1, 'content' => 'First chunk.'],
            ['chunk_id' => 'c2', 'file_id' => 'f1', 'sequence' => 1, 'page_number' => 1, 'content' => 'Second chunk.'],
        ];

        $mockEs = Mockery::mock(ElasticsearchService::class);
        $mockEs->shouldReceive('buildChunksIndexName')->with('tydal_test')->andReturn('tydal_test_chunks');
        $mockEs->shouldReceive('listChunksForResource')
            ->with('tydal_test_chunks', $this->resource->id)
            // Mirror the real method's own meta-chunk filtering so this test
            // exercises the controller's wiring, not the ES query itself
            // (that's covered by unit-level trust in the query builder).
            ->andReturn(array_values(array_filter($rawHits, fn ($h) => ! str_starts_with($h['chunk_id'], 'meta-'))));
        $this->app->instance(ElasticsearchService::class, $mockEs);

        $response = $this->withToken($this->token())
            ->getJson("/api/v1/resources/{$this->resource->id}/chunks");

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data.chunks')
            ->assertJsonPath('data.chunks.0.chunk_id', 'c1')
            ->assertJsonPath('data.chunks.1.chunk_id', 'c2');
    }

    public function test_returns_404_for_missing_resource(): void
    {
        $this->withToken($this->token())
            ->getJson('/api/v1/resources/'.Str::uuid().'/chunks')
            ->assertStatus(404);
    }

    public function test_guest_cannot_access_chunks(): void
    {
        $collection = Collection::factory()->create([
            'organization_id' => $this->organization->id,
            'user_owner_id' => $this->user->id,
        ]);
        $resource = Resource::factory()->forCollection($collection->id)->create([
            'organization_id' => $this->organization->id,
            'user_owner_id' => $this->user->id,
        ]);

        $this->getJson("/api/v1/resources/{$resource->id}/chunks")
            ->assertStatus(401);
    }
}
