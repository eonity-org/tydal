<?php

namespace Tests\Feature;

use App\Enums\ResourceState;
use App\Models\Collection;
use App\Models\CollectionScheme;
use App\Models\Organization;
use App\Models\Resource;
use App\Models\SearchIndex;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ElasticsearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Feature tests for the Elasticsearch path in WorkspaceController::catalogue().
 *
 * ElasticsearchService is bound into the container with a mock via $this->instance()
 * so that the controller's app(ElasticsearchService::class) resolves the mock.
 *
 * Covers:
 *  - ES is called when a search index exists and a search term is present
 *  - Facets from ES are returned in the response
 *  - DB fallback when ES throws
 *  - Default workspace always uses DB (never calls ES)
 *  - Workspace with no indexed collections always uses DB
 */
class WorkspaceEsCatalogueTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Organization $organization;

    private Workspace $workspace;

    private Resource $resource;

    private SearchIndex $searchIndex;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create();
        $this->organization = Organization::factory()->create();
        $this->organization->users()->attach($this->admin->id, ['role' => 'admin']);
        $this->admin->update(['last_organization_id' => $this->organization->id]);

        $scheme = CollectionScheme::create([
            'name' => 'test-scheme',
            'display_name' => 'Test Scheme',
            'accepted_mimetypes' => ['image/jpeg'],
            'is_system' => false,
            'fields' => [
                [
                    'name' => 'language',
                    'display_name' => 'Language',
                    'type' => 'text',
                    'is_facet' => true,
                    'es_type' => 'keyword',
                    'storage' => 'metadata',
                ],
            ],
        ]);

        $this->searchIndex = SearchIndex::create([
            'index_name' => 'tydal_test',
            'display_name' => 'Test Index',
            'is_active' => true,
        ]);

        $collection = Collection::factory()->create([
            'organization_id' => $this->organization->id,
            'user_owner_id' => $this->admin->id,
            'scheme_id' => $scheme->id,
            'index_id' => $this->searchIndex->id,
        ]);

        $this->workspace = Workspace::factory()->create([
            'organization_id' => $this->organization->id,
            'user_owner_id' => $this->admin->id,
            'is_default' => false,
        ]);

        $this->resource = Resource::factory()->create([
            'organization_id' => $this->organization->id,
            'collection_id' => $collection->id,
            'user_owner_id' => $this->admin->id,
            'state' => ResourceState::LIVE->value,
            'name' => 'Design System',
        ]);
        $this->workspace->resources()->attach($this->resource->id);
    }

    // =========================================================================
    // ES path is taken when a search index exists
    // =========================================================================

    public function test_catalogue_calls_es_when_index_exists_and_search_is_active(): void
    {
        $esCalled = false;

        $mockEs = Mockery::mock(ElasticsearchService::class);
        $mockEs->shouldReceive('searchByWorkspace')
            ->once()
            ->withArgs(function (array $indexNames, int $wsId) use (&$esCalled): bool {
                $esCalled = true;

                return in_array('tydal_test', $indexNames) && $wsId === $this->workspace->id;
            })
            ->andReturn(['ids' => [$this->resource->id], 'total' => 1, 'facets' => []]);

        $this->instance(ElasticsearchService::class, $mockEs);

        $token = $this->admin->createToken('t')->plainTextToken;

        $this->withToken($token)
            ->getJson("/api/v1/workspaces/{$this->workspace->id}/catalogue?search=design")
            ->assertStatus(200);

        $this->assertTrue($esCalled, 'ElasticsearchService::searchByWorkspace should have been called');
    }

    public function test_catalogue_returns_facets_from_elasticsearch(): void
    {
        $fakeFacets = [
            ['key' => 'language', 'label' => 'Language', 'values' => ['en' => ['count' => 5], 'fr' => ['count' => 2]]],
            ['key' => 'semantic_tags', 'label' => 'Tags', 'values' => []],
        ];

        $mockEs = Mockery::mock(ElasticsearchService::class);
        $mockEs->shouldReceive('searchByWorkspace')
            ->andReturn(['ids' => [], 'total' => 0, 'facets' => $fakeFacets]);

        $this->instance(ElasticsearchService::class, $mockEs);

        $token = $this->admin->createToken('t')->plainTextToken;

        $response = $this->withToken($token)
            ->getJson("/api/v1/workspaces/{$this->workspace->id}/catalogue?search=anything");

        $response->assertStatus(200)
            ->assertJsonPath('facets.0.key', 'language')
            ->assertJsonPath('facets.0.values.en.count', 5)
            ->assertJsonPath('facets.0.values.fr.count', 2);
    }

    // =========================================================================
    // DB fallback
    // =========================================================================

    public function test_catalogue_falls_back_to_db_when_es_throws(): void
    {
        $mockEs = Mockery::mock(ElasticsearchService::class);
        $mockEs->shouldReceive('searchByWorkspace')
            ->andThrow(new \RuntimeException('ES connection refused'));

        $this->instance(ElasticsearchService::class, $mockEs);

        $token = $this->admin->createToken('t')->plainTextToken;

        // Should return 200 with empty facets from DB fallback
        $this->withToken($token)
            ->getJson("/api/v1/workspaces/{$this->workspace->id}/catalogue?search=design")
            ->assertStatus(200)
            ->assertJsonPath('facets', []);
    }

    // =========================================================================
    // Default workspace always uses DB
    // =========================================================================

    public function test_default_workspace_never_calls_elasticsearch(): void
    {
        $defaultWorkspace = Workspace::factory()->create([
            'organization_id' => $this->organization->id,
            'user_owner_id' => $this->admin->id,
            'is_default' => true,
        ]);

        $mockEs = Mockery::mock(ElasticsearchService::class);
        $mockEs->shouldNotReceive('searchByWorkspace');
        $this->instance(ElasticsearchService::class, $mockEs);

        $token = $this->admin->createToken('t')->plainTextToken;

        $this->withToken($token)
            ->getJson("/api/v1/workspaces/{$defaultWorkspace->id}/catalogue?search=test")
            ->assertStatus(200)
            ->assertJsonPath('facets', []);
    }

    // =========================================================================
    // Workspace with no indexed collections
    // =========================================================================

    public function test_workspace_with_no_indexed_collections_uses_db(): void
    {
        $collectionNoIndex = Collection::factory()->create([
            'organization_id' => $this->organization->id,
            'user_owner_id' => $this->admin->id,
            'index_id' => null,
        ]);

        $wsNoIndex = Workspace::factory()->create([
            'organization_id' => $this->organization->id,
            'user_owner_id' => $this->admin->id,
            'is_default' => false,
        ]);

        $resource = Resource::factory()->create([
            'organization_id' => $this->organization->id,
            'collection_id' => $collectionNoIndex->id,
            'user_owner_id' => $this->admin->id,
            'name' => 'DB Only Resource',
            'state' => ResourceState::LIVE->value,
        ]);
        $wsNoIndex->resources()->attach($resource->id);

        $mockEs = Mockery::mock(ElasticsearchService::class);
        $mockEs->shouldNotReceive('searchByWorkspace');
        $this->instance(ElasticsearchService::class, $mockEs);

        $token = $this->admin->createToken('t')->plainTextToken;

        $this->withToken($token)
            ->getJson("/api/v1/workspaces/{$wsNoIndex->id}/catalogue?search=DB")
            ->assertStatus(200)
            ->assertJsonPath('total', 1)
            ->assertJsonPath('facets', []);
    }

    // =========================================================================
    // Facet filter pass-through
    // =========================================================================

    public function test_catalogue_passes_facet_filters_to_elasticsearch(): void
    {
        $capturedFacets = null;

        $mockEs = Mockery::mock(ElasticsearchService::class);
        $mockEs->shouldReceive('searchByWorkspace')
            ->once()
            ->withArgs(function (array $indexNames, int $wsId, string $query, array $facetFilters) use (&$capturedFacets): bool {
                $capturedFacets = $facetFilters;

                return true;
            })
            ->andReturn(['ids' => [], 'total' => 0, 'facets' => []]);

        $this->instance(ElasticsearchService::class, $mockEs);

        $token = $this->admin->createToken('t')->plainTextToken;

        $this->withToken($token)
            ->getJson("/api/v1/workspaces/{$this->workspace->id}/catalogue?search=x&facets[language][]=en&facets[language][]=fr")
            ->assertStatus(200);

        $this->assertNotNull($capturedFacets);
        $this->assertArrayHasKey('language', $capturedFacets);
        $this->assertContains('en', $capturedFacets['language']);
        $this->assertContains('fr', $capturedFacets['language']);
    }
}
