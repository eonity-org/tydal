<?php

namespace Tests\Feature;

use App\Enums\FileRole;
use App\Enums\ResourceState;
use App\Enums\VaultPurpose;
use App\Enums\VaultState;
use App\Models\File;
use App\Models\Organization;
use App\Models\Resource;
use App\Models\Vault;
use App\Models\Workspace;
use App\Services\ElasticsearchService;
use App\Services\Processing\Contracts\EmbeddingServiceInterface;
use App\Services\VaultOperationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Epic 4.1 — the semantic surface on the vault boundary: /embed (query
 * embedding as a compute tool) and mode=semantic on /search (k-NN over the
 * vectors indexed since M1/M3). TYDAL exposes data and compute, never
 * reasoning — both degrade cleanly when the embedder or index is missing.
 */
class VaultSemanticSearchTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Workspace $ws;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organization::factory()->create(['slug' => 'acme']);
        $this->ws = Workspace::factory()->create(['organization_id' => $this->org->id]);
    }

    private function makeVault(VaultPurpose $purpose = VaultPurpose::GALLERY, array $overrides = []): Vault
    {
        $vault = Vault::factory()->purpose($purpose)->published()->create(
            array_merge(['organization_id' => $this->org->id, 'slug' => 'my-vault'], $overrides)
        );

        DB::table('workspace_vault')->insert(['workspace_id' => $this->ws->id, 'vault_id' => $vault->id]);

        return $vault;
    }

    private function addResource(string $name): Resource
    {
        $resource = Resource::factory()->create([
            'organization_id' => $this->org->id,
            'name' => $name,
            'state' => ResourceState::LIVE->value,
        ]);

        DB::table('dam_resource_workspace')->insert(['resource_id' => $resource->id, 'workspace_id' => $this->ws->id]);

        return $resource;
    }

    /** Feature tests never talk to real ES — model hooks hit this mock. */
    private function mockEs(): MockInterface
    {
        $mock = Mockery::mock(ElasticsearchService::class);
        $mock->shouldReceive('indexResource')->zeroOrMoreTimes();
        $mock->shouldReceive('indexResourceIntoVaults')->zeroOrMoreTimes();
        $mock->shouldReceive('deleteResource')->zeroOrMoreTimes();
        $mock->shouldReceive('removeResourceFromVaultIndexes')->zeroOrMoreTimes();
        $this->instance(ElasticsearchService::class, $mock);

        return $mock;
    }

    private function mockEmbedder(?array $vector): void
    {
        $mock = Mockery::mock(EmbeddingServiceInterface::class);

        if ($vector === null) {
            $mock->shouldReceive('embed')->andThrow(new \RuntimeException('embedder down'));
        } else {
            $mock->shouldReceive('embed')->andReturn($vector);
        }

        $this->instance(EmbeddingServiceInterface::class, $mock);
    }

    // =========================================================================
    // /embed — query embedding compute
    // =========================================================================

    public function test_embed_returns_the_query_vector(): void
    {
        $this->makeVault();
        $this->mockEmbedder([0.1, 0.2, 0.3]);

        $this->getJson('/v/acme/my-vault/embed?q=winter+landscapes')
            ->assertStatus(200)
            ->assertJsonPath('type', 'embedding')
            ->assertJsonPath('dimensions', 3)
            ->assertJsonPath('vector', [0.1, 0.2, 0.3]);
    }

    public function test_embed_works_on_the_hash_form_too(): void
    {
        $vault = $this->makeVault();
        $this->mockEmbedder([0.5, 0.5]);

        $this->getJson("/h/{$vault->hash}/embed?q=hello")
            ->assertStatus(200)
            ->assertJsonPath('dimensions', 2);
    }

    public function test_embed_without_query_is_rejected(): void
    {
        $this->makeVault();

        $this->getJson('/v/acme/my-vault/embed')->assertStatus(422);
    }

    public function test_embed_answers_503_when_the_embedder_is_down(): void
    {
        $this->makeVault();
        $this->mockEmbedder(null);

        $this->getJson('/v/acme/my-vault/embed?q=hello')->assertStatus(503);
    }

    public function test_embed_still_requires_vault_resolution(): void
    {
        Vault::factory()->purpose(VaultPurpose::AI)->create([
            'organization_id' => $this->org->id,
            'slug' => 'my-vault',
            'state' => VaultState::PRIVATE->value,
        ]);
        $this->mockEmbedder([0.1]);

        // Private vault, no key — hidden, exactly like every other operation
        $this->getJson('/v/acme/my-vault/embed?q=hello')->assertStatus(404);
    }

    public function test_meta_advertises_embed_and_search_modes(): void
    {
        $this->makeVault();

        $this->getJson('/v/acme/my-vault/meta')
            ->assertStatus(200)
            ->assertJsonPath('search_modes', ['keyword', 'semantic'])
            ->assertJson(fn ($json) => $json->where('operations.vault', fn ($ops) => collect($ops)->contains('embed'))->etc());
    }

    // =========================================================================
    // mode=semantic on /search — resources (k-NN over the per-vault index)
    // =========================================================================

    public function test_semantic_search_rides_the_vault_index(): void
    {
        $vault = $this->makeVault(VaultPurpose::GALLERY, ['indexed_at' => now()]);
        $this->mockEmbedder([0.1, 0.2]);

        $es = $this->mockEs();
        $es->shouldReceive('vaultFacetFields')->andReturn([]);
        $es->shouldReceive('knnSearchVaultIndex')
            ->once()
            ->withArgs(fn (Vault $v, array $vector) => $v->id === $vault->id && $vector === [0.1, 0.2])
            ->andReturn([
                'hits' => [['id' => 'r1', 'name' => 'Sunset', 'slug' => 'sunset', 'score' => 0.93]],
                'total' => 1,
                'facets' => [],
            ]);
        $es->shouldNotReceive('searchVaultIndex');

        $this->getJson('/v/acme/my-vault/search?q=warm+evening&mode=semantic')
            ->assertStatus(200)
            ->assertJsonPath('mode', 'semantic')
            ->assertJsonPath('results.0.slug', 'sunset')
            ->assertJsonPath('results.0.score', 0.93);
    }

    public function test_semantic_search_degrades_to_keyword_when_the_embedder_is_down(): void
    {
        $this->makeVault(VaultPurpose::GALLERY, ['indexed_at' => now()]);
        $this->mockEmbedder(null);

        $es = $this->mockEs();
        $es->shouldReceive('vaultFacetFields')->andReturn([]);
        $es->shouldReceive('searchVaultIndex')
            ->once()
            ->andReturn(['hits' => [], 'total' => 0, 'facets' => []]);

        $this->getJson('/v/acme/my-vault/search?q=warm+evening&mode=semantic')
            ->assertStatus(200)
            ->assertJsonPath('mode', 'keyword');
    }

    public function test_semantic_search_on_an_unindexed_vault_falls_back_to_db_keyword(): void
    {
        $this->mockEs();
        $this->makeVault(VaultPurpose::GALLERY); // indexed_at NULL
        $this->addResource('Winter Landscape');

        // The embedder must not even be consulted — bind a throwing one
        $this->mockEmbedder(null);

        $this->getJson('/v/acme/my-vault/search?q=Winter&mode=semantic')
            ->assertStatus(200)
            ->assertJsonPath('mode', 'keyword')
            ->assertJsonPath('results.0.name', 'Winter Landscape');
    }

    // =========================================================================
    // facet[field]=value filters (Epic 5.1)
    // =========================================================================

    public function test_facet_filters_reach_es_whitelisted_to_aggregating_slots(): void
    {
        $vault = $this->makeVault(VaultPurpose::GALLERY, ['indexed_at' => now()]);

        $es = $this->mockEs();
        $es->shouldReceive('vaultFacetFields')->andReturn(['technique' => 'metadata.technique']);
        $es->shouldReceive('searchVaultIndex')
            ->once()
            ->withArgs(function (Vault $v, string $q, int $page, int $perPage, array $facetFields, array $facetFilters) {
                // `bogus` is not an aggregating slot — silently dropped
                return $facetFilters === ['technique' => ['oil', 'acrylic']];
            })
            ->andReturn(['hits' => [], 'total' => 0, 'facets' => []]);

        $this->getJson('/v/acme/my-vault/search?q=x&facet[technique][]=oil&facet[technique][]=acrylic&facet[bogus]=y')
            ->assertStatus(200);
    }

    public function test_facet_filter_narrows_the_db_fallback(): void
    {
        $this->mockEs();
        $this->makeVault(VaultPurpose::GALLERY); // unindexed → DB path

        foreach ([['Oil Piece', 'oil'], ['Acrylic Piece', 'acrylic']] as [$name, $technique]) {
            $r = Resource::factory()->create([
                'organization_id' => $this->org->id, 'name' => $name, 'state' => ResourceState::LIVE->value,
                'metadata' => ['technique' => $technique],
            ]);
            DB::table('dam_resource_workspace')->insert(['resource_id' => $r->id, 'workspace_id' => $this->ws->id]);
        }

        $this->getJson('/v/acme/my-vault/search?q=Piece&facet[technique]=oil')
            ->assertStatus(200)
            ->assertJsonCount(1, 'results')
            ->assertJsonPath('results.0.name', 'Oil Piece');
    }

    // =========================================================================
    // mode=semantic on /search?scope=chunks — k-NN over chunk vectors
    // =========================================================================

    public function test_semantic_chunk_search_is_role_filtered(): void
    {
        $es = $this->mockEs();

        $vault = $this->makeVault(VaultPurpose::AI, [
            'indexed_at' => now(),
            'exposure_policy' => ['chunk_roles' => [FileRole::CANONICAL->value]],
        ]);
        $resource = $this->addResource('Report');
        $canonical = File::factory()->for($resource)->create(['role' => FileRole::CANONICAL]);
        $supporting = File::factory()->for($resource)->create(['role' => FileRole::SUPPORTING]);

        $this->mockEmbedder([0.3, 0.7]);

        $es->shouldReceive('buildChunksIndexName')->zeroOrMoreTimes()->andReturnUsing(fn (string $n) => "{$n}_chunks");
        $es->shouldReceive('knnSearchChunksForWorkspace')
            ->once()
            ->andReturn([
                ['resource_id' => $resource->id, 'file_id' => $canonical->id, 'sequence' => 0, 'page_number' => 1, 'content' => 'canonical passage', 'score' => 0.9],
                ['resource_id' => $resource->id, 'file_id' => $supporting->id, 'sequence' => 1, 'page_number' => 2, 'content' => 'supporting passage', 'score' => 0.8],
            ]);
        $es->shouldNotReceive('searchChunksKeyword');

        $response = $this->getJson('/v/acme/my-vault/search?q=findings&scope=chunks&mode=semantic')
            ->assertStatus(200)
            ->assertJsonPath('mode', 'semantic')
            ->assertJsonCount(1, 'results')
            ->assertJsonPath('results.0.content', 'canonical passage')
            ->assertJsonPath('results.0.score', 0.9);
    }

    public function test_query_embedding_is_cached_across_repeated_semantic_searches(): void
    {
        $es = $this->mockEs();
        $vault = $this->makeVault(VaultPurpose::AI, ['indexed_at' => now()]);
        $this->addResource('Report');

        // Strict once(): the SECOND semantic search of the same query must hit
        // the embedding cache, not the provider. This is what halves the ask
        // pipeline's embedding cost (chunk search + card search, one question).
        $embedder = Mockery::mock(EmbeddingServiceInterface::class);
        $embedder->shouldReceive('embed')->once()->with('same question')->andReturn([0.1, 0.2]);
        $this->instance(EmbeddingServiceInterface::class, $embedder);

        $es->shouldReceive('buildChunksIndexName')->zeroOrMoreTimes()->andReturnUsing(fn (string $n) => "{$n}_chunks");
        $es->shouldReceive('knnSearchChunksForWorkspace')->zeroOrMoreTimes()->andReturn([]);
        $es->shouldReceive('vaultFacetFields')->andReturn([]);
        $es->shouldReceive('knnSearchVaultIndex')->andReturn(['hits' => [], 'total' => 0, 'facets' => []]);

        $ops = app(VaultOperationService::class);
        $ops->vaultSearchChunks($vault, 'same question', 5, 'semantic');
        $ops->vaultSearch($vault, 'same question', 1, 5, 'semantic'); // cached — no second embed
    }

    public function test_meta_chunks_pass_the_role_filter(): void
    {
        $es = $this->mockEs();

        $vault = $this->makeVault(VaultPurpose::AI, [
            'indexed_at' => now(),
            'exposure_policy' => ['chunk_roles' => [FileRole::CANONICAL->value]],
        ]);
        $resource = $this->addResource('Report');

        $this->mockEmbedder([0.3, 0.7]);

        $es->shouldReceive('buildChunksIndexName')->zeroOrMoreTimes()->andReturnUsing(fn (string $n) => "{$n}_chunks");
        $es->shouldReceive('knnSearchChunksForWorkspace')
            ->once()
            ->andReturn([
                // Synthetic meta chunk: no source file, Tier-0 material — passes
                ['chunk_id' => "meta-{$resource->id}", 'resource_id' => $resource->id, 'file_id' => '', 'sequence' => 0, 'page_number' => null, 'content' => 'Resource: Report', 'score' => 0.9],
                // Fileless chunk that is NOT a meta chunk — still excluded
                ['chunk_id' => 'orphan-chunk', 'resource_id' => $resource->id, 'file_id' => '', 'sequence' => 1, 'page_number' => null, 'content' => 'orphan passage', 'score' => 0.8],
            ]);

        $this->getJson('/v/acme/my-vault/search?q=findings&scope=chunks&mode=semantic')
            ->assertStatus(200)
            ->assertJsonCount(1, 'results')
            ->assertJsonPath('results.0.content', 'Resource: Report');
    }

    public function test_chunk_search_serves_raw_scored_hits_without_relevance_cutoff(): void
    {
        $es = $this->mockEs();

        $vault = $this->makeVault(VaultPurpose::AI, ['indexed_at' => now()]);
        $resource = $this->addResource('Report');
        $file = File::factory()->for($resource)->create(['role' => FileRole::CANONICAL]);

        $this->mockEmbedder([0.3, 0.7]);

        $es->shouldReceive('buildChunksIndexName')->zeroOrMoreTimes()->andReturnUsing(fn (string $n) => "{$n}_chunks");
        // The boundary is a retrieval surface for external reasoners: the k-NN
        // call must skip the RAG relevance cutoff (applyRagFilters: false) and
        // every scored hit — however weak — must reach the response.
        $es->shouldReceive('knnSearchChunksForWorkspace')
            ->once()
            ->withArgs(fn (...$args) => ($args[4] ?? true) === false)
            ->andReturn([
                ['chunk_id' => 'c1', 'resource_id' => $resource->id, 'file_id' => $file->id, 'sequence' => 0, 'page_number' => 1, 'content' => 'strong match', 'score' => 0.9],
                ['chunk_id' => 'c2', 'resource_id' => $resource->id, 'file_id' => $file->id, 'sequence' => 7, 'page_number' => 4, 'content' => 'weak match', 'score' => 0.1],
            ]);

        $this->getJson('/v/acme/my-vault/search?q=findings&scope=chunks&mode=semantic')
            ->assertStatus(200)
            ->assertJsonCount(2, 'results')
            ->assertJsonPath('results.1.content', 'weak match')
            ->assertJsonPath('results.1.score', 0.1);
    }

    public function test_chunk_search_default_mode_stays_keyword(): void
    {
        $es = $this->mockEs();

        $vault = $this->makeVault(VaultPurpose::AI, ['indexed_at' => now()]);
        $resource = $this->addResource('Report');
        $file = File::factory()->for($resource)->create(['role' => FileRole::CANONICAL]);

        $es->shouldReceive('buildChunksIndexName')->zeroOrMoreTimes()->andReturnUsing(fn (string $n) => "{$n}_chunks");
        $es->shouldReceive('searchChunksKeyword')
            ->once()
            ->andReturn([
                ['resource_id' => $resource->id, 'file_id' => $file->id, 'sequence' => 0, 'page_number' => 1, 'content' => 'match', 'score' => 1.2],
            ]);

        $this->getJson('/v/acme/my-vault/search?q=match&scope=chunks')
            ->assertStatus(200)
            ->assertJsonPath('mode', 'keyword')
            ->assertJsonCount(1, 'results');
    }
}
