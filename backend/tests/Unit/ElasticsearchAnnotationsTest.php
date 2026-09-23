<?php

namespace Tests\Unit;

use App\Enums\ResourceState;
use App\Models\Collection;
use App\Models\Organization;
use App\Models\Resource;
use App\Models\SearchIndex;
use App\Models\SemanticTag;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ElasticsearchService;
use Elastic\Elasticsearch\ClientInterface;
use Elastic\Elasticsearch\Exception\ClientResponseException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use ReflectionMethod;
use ReflectionProperty;
use Tests\TestCase;

/**
 * Unit tests for the ES parent-child annotation document feature.
 *
 * These tests cover:
 *  - buildAnnotationDocument()  — correct child-doc shape from loaded relations
 *  - parseFieldScopedQuery()    — field-scoped token splitting
 *  - buildMappings()            — join field + annotation fields present
 *  - indexAnnotations()         — correct ES client params (id, routing, body)
 *  - deleteResource()           — explicit child-doc deletion, 404 silently ignored
 *  - reindexAll()               — annotationsFailed key in result
 *
 * The Elastic\Elasticsearch\Client class is `final`, so we cannot Mockery-mock it
 * directly. Instead we inject an anonymous mock via ReflectionProperty — PHP's
 * reflection layer bypasses typed-property enforcement at the C level.
 *
 * Resources are created with index_id = null so that boot-hook
 * IndexResourceToElasticsearch::dispatchSync() returns early (no real ES call).
 * The SearchIndex is wired in after creation, only for the tests that need it.
 */
class ElasticsearchAnnotationsTest extends TestCase
{
    use RefreshDatabase;

    private ElasticsearchService $service;

    private mixed $mockClient;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new ElasticsearchService;
        $this->mockClient = Mockery::mock(ClientInterface::class);

        // Inject the mock — property is typed as ClientInterface, so assignment is valid
        $prop = new ReflectionProperty(ElasticsearchService::class, 'client');
        $prop->setAccessible(true);
        $prop->setValue($this->service, $this->mockClient);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Call a private method on $this->service via reflection.
     */
    private function callPrivate(string $method, mixed ...$args): mixed
    {
        $m = new ReflectionMethod(ElasticsearchService::class, $method);
        $m->setAccessible(true);

        return $m->invoke($this->service, ...$args);
    }

    /**
     * Create a resource whose collection has a SearchIndex attached AFTER
     * creation to keep boot-hook ES calls safe (resolveIndexName returns null
     * during creation, real index available for the actual test call).
     *
     * @return array{0: resource, 1: Organization, 2: SearchIndex}
     */
    private function makeResourceWithIndex(): array
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();

        $collection = Collection::factory()->create([
            'organization_id' => $org->id,
            'user_owner_id' => $user->id,
            'index_id' => null,   // safe: no ES call on resource creation
        ]);

        $resource = Resource::factory()->create([
            'collection_id' => $collection->id,
            'organization_id' => $org->id,
            'user_owner_id' => $user->id,
            'state' => ResourceState::LIVE->value,
        ]);

        // Wire in the SearchIndex after the resource exists
        $index = SearchIndex::create([
            'index_name' => 'tydal_test',
            'display_name' => 'Test Index',
            'is_active' => true,
        ]);
        $collection->update(['index_id' => $index->id]);

        return [$resource->fresh(), $org, $index];
    }

    // =========================================================================
    // buildAnnotationDocument
    // =========================================================================

    public function test_annotation_doc_includes_active_tag_labels_and_types(): void
    {
        [$resource, $org] = $this->makeResourceWithIndex();

        $tag = SemanticTag::create([
            'organization_id' => $org->id,
            'label' => 'Paris',
            'slug' => 'paris',
            'entity_type' => 'place',
            'is_active' => true,
        ]);
        $resource->semanticTags()->attach($tag->id);
        $resource->load(['semanticTags', 'workspaces']);

        $doc = $this->callPrivate('buildAnnotationDocument', $resource);

        $this->assertSame(['Paris'], $doc['tag_labels']);
        $this->assertSame(['place'], $doc['tag_types']);
        $this->assertSame(['name' => 'annotations', 'parent' => $resource->id], $doc['relation']);
        $this->assertSame('', $doc['ai_description']);
    }

    public function test_annotation_doc_excludes_inactive_tags(): void
    {
        [$resource, $org] = $this->makeResourceWithIndex();

        $tag = SemanticTag::create([
            'organization_id' => $org->id,
            'label' => 'Archived',
            'slug' => 'archived-tag',
            'is_active' => false,
        ]);
        $resource->semanticTags()->attach($tag->id);
        $resource->load(['semanticTags', 'workspaces']);

        $doc = $this->callPrivate('buildAnnotationDocument', $resource);

        $this->assertSame([], $doc['tag_labels']);
        $this->assertSame([], $doc['tag_types']);
    }

    public function test_annotation_doc_excludes_default_workspaces(): void
    {
        [$resource, $org] = $this->makeResourceWithIndex();
        $user = User::first();

        $default = Workspace::factory()->create([
            'organization_id' => $org->id,
            'user_owner_id' => $user->id,
            'is_default' => true,
            'name' => 'All Assets',
        ]);
        $curated = Workspace::factory()->create([
            'organization_id' => $org->id,
            'user_owner_id' => $user->id,
            'is_default' => false,
            'name' => 'Design Team',
        ]);
        $resource->workspaces()->attach([$default->id, $curated->id]);
        $resource->load(['semanticTags', 'workspaces']);

        $doc = $this->callPrivate('buildAnnotationDocument', $resource);

        $this->assertSame(['Design Team'], $doc['workspace_labels']);
    }

    public function test_annotation_doc_returns_empty_arrays_when_relations_not_loaded(): void
    {
        [$resource] = $this->makeResourceWithIndex();

        // Relations deliberately NOT loaded — guard against lazy-load surprises
        $doc = $this->callPrivate('buildAnnotationDocument', $resource);

        $this->assertSame([], $doc['tag_labels']);
        $this->assertSame([], $doc['tag_types']);
        $this->assertSame([], $doc['workspace_labels']);
    }

    // =========================================================================
    // parseFieldScopedQuery
    // =========================================================================

    public function test_parse_scoped_query_extracts_simple_tag_token(): void
    {
        $result = $this->callPrivate('parseFieldScopedQuery', 'tag:paris');

        $this->assertCount(1, $result['scoped']);
        $this->assertSame('tag', $result['scoped'][0]['field']);
        $this->assertSame('paris', $result['scoped'][0]['value']);
        $this->assertSame('', $result['remainder']);
    }

    public function test_parse_scoped_query_handles_quoted_multi_word_value(): void
    {
        $result = $this->callPrivate('parseFieldScopedQuery', 'tag:"marie curie"');

        $this->assertCount(1, $result['scoped']);
        $this->assertSame('tag', $result['scoped'][0]['field']);
        $this->assertSame('marie curie', $result['scoped'][0]['value']);
        $this->assertSame('', $result['remainder']);
    }

    public function test_parse_scoped_query_extracts_multiple_scoped_tokens(): void
    {
        $result = $this->callPrivate('parseFieldScopedQuery', 'tag:paris workspace:design');

        $this->assertCount(2, $result['scoped']);
        $this->assertSame('tag', $result['scoped'][0]['field']);
        $this->assertSame('workspace', $result['scoped'][1]['field']);
    }

    public function test_parse_scoped_query_preserves_unscoped_remainder(): void
    {
        $result = $this->callPrivate('parseFieldScopedQuery', 'tag:paris eiffel tower');

        $this->assertCount(1, $result['scoped']);
        $this->assertSame('eiffel tower', $result['remainder']);
    }

    public function test_parse_scoped_query_returns_plain_text_as_full_remainder(): void
    {
        $result = $this->callPrivate('parseFieldScopedQuery', 'paris architecture');

        $this->assertCount(0, $result['scoped']);
        $this->assertSame('paris architecture', $result['remainder']);
    }

    public function test_parse_scoped_query_empty_string_yields_empty_result(): void
    {
        $result = $this->callPrivate('parseFieldScopedQuery', '');

        $this->assertCount(0, $result['scoped']);
        $this->assertSame('', $result['remainder']);
    }

    // =========================================================================
    // buildMappings — join field + annotation text fields
    // =========================================================================

    public function test_build_mappings_includes_parent_child_join_field(): void
    {
        $mappings = $this->service->buildMappings(null);
        $props = $mappings['properties'];

        $this->assertArrayHasKey('relation', $props);
        $this->assertSame('join', $props['relation']['type']);
        $this->assertSame(['resource' => 'annotations'], $props['relation']['relations']);
    }

    public function test_build_mappings_includes_all_annotation_fields(): void
    {
        $mappings = $this->service->buildMappings(null);
        $props = $mappings['properties'];

        $this->assertArrayHasKey('tag_labels', $props);
        $this->assertArrayHasKey('tag_types', $props);
        $this->assertArrayHasKey('workspace_labels', $props);
        $this->assertArrayHasKey('ai_description', $props);

        $this->assertSame('text', $props['tag_labels']['type']);
        $this->assertSame('keyword', $props['tag_types']['type']);
        $this->assertSame('text', $props['workspace_labels']['type']);
        $this->assertSame('text', $props['ai_description']['type']);
    }

    public function test_build_document_declares_resource_join_side(): void
    {
        [$resource] = $this->makeResourceWithIndex();
        $resource->load([
            'collection',
            'systemFiles' => fn ($q) => $q->where('is_active', true),
            'workspaces',
            'semanticTags',
        ]);

        $doc = $this->callPrivate('buildDocument', $resource);

        $this->assertSame(['name' => 'resource'], $doc['relation']);
    }

    // =========================================================================
    // searchResources — parent-only filter
    // =========================================================================

    public function test_search_resources_must_clause_contains_parent_only_relation_filter(): void
    {
        // Capture the params passed to client->search() and inspect the must clauses
        $capturedParams = null;
        $this->mockClient
            ->shouldReceive('search')
            ->once()
            ->withArgs(function (array $params) use (&$capturedParams): bool {
                $capturedParams = $params;

                return true;
            })
            ->andReturn(new class
            {
                public function asArray(): array
                {
                    return ['hits' => ['hits' => [], 'total' => ['value' => 0]], 'aggregations' => []];
                }
            });

        $this->service->searchResources('tydal_test', 1, '', [], [], 1, 20);

        $mustClauses = $capturedParams['body']['query']['bool']['must'];
        $relationFilter = ['term' => ['relation' => 'resource']];

        $this->assertContains(
            $relationFilter,
            $mustClauses,
            'searchResources must include a relation:resource filter to exclude annotation child docs'
        );
    }

    // =========================================================================
    // indexAnnotations — ES client params
    // =========================================================================

    public function test_index_annotations_calls_client_with_child_id_and_routing(): void
    {
        [$resource] = $this->makeResourceWithIndex();

        $this->mockClient->shouldReceive('index')
            ->once()
            ->withArgs(function (array $params) use ($resource): bool {
                return $params['id'] === $resource->id.'#annotations'
                    && $params['routing'] === $resource->id
                    && ($params['body']['relation']['name'] ?? '') === 'annotations'
                    && ($params['body']['relation']['parent'] ?? '') === $resource->id;
            });

        $this->service->indexAnnotations($resource);
    }

    public function test_index_annotations_skips_when_no_search_index_configured(): void
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
        ]);

        $this->mockClient->shouldNotReceive('index');

        $this->service->indexAnnotations($resource);
    }

    // =========================================================================
    // deleteResource — child doc must be deleted explicitly
    // =========================================================================

    public function test_delete_resource_issues_delete_for_parent_and_child(): void
    {
        [$resource] = $this->makeResourceWithIndex();
        $id = $resource->id;

        $this->mockClient
            ->shouldReceive('delete')
            ->once()
            ->withArgs(fn (array $p): bool => ($p['id'] ?? '') === $id);

        $this->mockClient
            ->shouldReceive('delete')
            ->once()
            ->withArgs(fn (array $p): bool => ($p['id'] ?? '') === "{$id}#annotations"
                                           && ($p['routing'] ?? '') === $id);

        $this->service->deleteResource($id);
    }

    public function test_delete_resource_silently_ignores_404_on_child(): void
    {
        [$resource] = $this->makeResourceWithIndex();
        $id = $resource->id;

        $this->mockClient
            ->shouldReceive('delete')
            ->withArgs(fn (array $p): bool => ($p['id'] ?? '') === $id)
            ->once()
            ->andReturn(null);

        // Simulate child doc not yet indexed — ES returns 404
        $notFound = new ClientResponseException('Not Found', 404);
        $this->mockClient
            ->shouldReceive('delete')
            ->withArgs(fn (array $p): bool => str_ends_with($p['id'] ?? '', '#annotations'))
            ->once()
            ->andThrow($notFound);

        // Must NOT re-throw — 404 on the child doc is expected behaviour
        $this->service->deleteResource($id);

        $this->assertTrue(true);
    }

    // =========================================================================
    // reindexAll — annotationsFailed in result
    // =========================================================================

    public function test_reindex_all_result_contains_annotations_failed_key(): void
    {
        $result = $this->service->reindexAll();

        $this->assertArrayHasKey('annotationsFailed', $result);
        $this->assertSame(0, $result['annotationsFailed']);
    }

    public function test_reindex_all_increments_annotations_failed_independently(): void
    {
        [$resource] = $this->makeResourceWithIndex();

        // Parent write succeeds
        $this->mockClient
            ->shouldReceive('index')
            ->once()
            ->withArgs(fn (array $p): bool => ($p['id'] ?? '') === $resource->id)
            ->andReturn(null);

        // Annotation child write fails
        $this->mockClient
            ->shouldReceive('index')
            ->once()
            ->withArgs(fn (array $p): bool => str_ends_with($p['id'] ?? '', '#annotations'))
            ->andThrow(new \RuntimeException('ES timeout'));

        $result = $this->service->reindexAll();

        $this->assertSame(1, $result['indexed']);           // parent succeeded
        $this->assertSame(0, $result['failed']);            // parent did NOT fail
        $this->assertSame(1, $result['annotationsFailed']); // child tracked separately
    }
}
