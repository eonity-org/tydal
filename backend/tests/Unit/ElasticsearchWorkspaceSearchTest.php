<?php

namespace Tests\Unit;

use App\Enums\ResourceState;
use App\Services\ElasticsearchService;
use Elastic\Elasticsearch\ClientInterface;
use Mockery;
use ReflectionProperty;
use Tests\TestCase;

/**
 * Unit tests for workspace-scoped ES search methods added in Phase 9.
 *
 * Covers:
 *  - searchByWorkspace()             — query structure, multi-index, aggregations
 *  - knnSearchChunksForWorkspace()   — filter by resource IDs, multi-index, empty guards
 */
class ElasticsearchWorkspaceSearchTest extends TestCase
{
    private ElasticsearchService $service;

    private mixed $mockClient;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new ElasticsearchService;
        $this->mockClient = Mockery::mock(ClientInterface::class);

        $prop = new ReflectionProperty(ElasticsearchService::class, 'client');
        $prop->setAccessible(true);
        $prop->setValue($this->service, $this->mockClient);
    }

    // =========================================================================
    // searchByWorkspace — query structure
    // =========================================================================

    public function test_search_by_workspace_filters_by_workspace_id_not_collection_id(): void
    {
        $capturedParams = null;
        $this->mockClient
            ->shouldReceive('search')
            ->once()
            ->withArgs(function (array $params) use (&$capturedParams): bool {
                $capturedParams = $params;

                return true;
            })
            ->andReturn($this->fakeEsResponse());

        $this->service->searchByWorkspace(['tydal_test'], 42, '', [], [], 1, 20);

        $must = $capturedParams['body']['query']['bool']['must'];

        $this->assertContains(
            ['term' => ['workspace_ids' => 42]],
            $must,
            'searchByWorkspace must filter by workspace_ids'
        );

        $hasCollectionFilter = collect($must)->contains(
            fn ($clause) => isset($clause['term']['collection_id'])
        );
        $this->assertFalse($hasCollectionFilter, 'searchByWorkspace must NOT filter by collection_id');
    }

    public function test_search_by_workspace_includes_relation_resource_filter(): void
    {
        $capturedParams = null;
        $this->mockClient
            ->shouldReceive('search')
            ->once()
            ->withArgs(function (array $params) use (&$capturedParams): bool {
                $capturedParams = $params;

                return true;
            })
            ->andReturn($this->fakeEsResponse());

        $this->service->searchByWorkspace(['tydal_test'], 42, '', [], [], 1, 20);

        $must = $capturedParams['body']['query']['bool']['must'];
        $this->assertContains(
            ['term' => ['relation' => 'resource']],
            $must,
            'parent-only filter must be present to exclude annotation child docs'
        );
    }

    public function test_search_by_workspace_joins_multiple_index_names_with_comma(): void
    {
        $capturedParams = null;
        $this->mockClient
            ->shouldReceive('search')
            ->once()
            ->withArgs(function (array $params) use (&$capturedParams): bool {
                $capturedParams = $params;

                return true;
            })
            ->andReturn($this->fakeEsResponse());

        $this->service->searchByWorkspace(['tydal_multimedia', 'tydal_documents'], 5, '', [], [], 1, 20);

        $this->assertSame('tydal_multimedia,tydal_documents', $capturedParams['index']);
    }

    public function test_search_by_workspace_omits_workspaces_aggregation(): void
    {
        $capturedParams = null;
        $this->mockClient
            ->shouldReceive('search')
            ->once()
            ->withArgs(function (array $params) use (&$capturedParams): bool {
                $capturedParams = $params;

                return true;
            })
            ->andReturn($this->fakeEsResponse());

        $this->service->searchByWorkspace(['tydal_test'], 42, '', [], [], 1, 20);

        $aggs = $capturedParams['body']['aggs'];
        $this->assertArrayNotHasKey(
            'workspaces',
            $aggs,
            'workspace aggregation is meaningless when already scoped to a workspace'
        );
        $this->assertArrayHasKey('semantic_tags', $aggs);
    }

    public function test_search_by_workspace_returns_ids_total_and_facets(): void
    {
        $this->mockClient
            ->shouldReceive('search')
            ->andReturn($this->fakeEsResponse([
                'hits' => [['_id' => 'uuid-1'], ['_id' => 'uuid-2']],
                'total' => 2,
            ]));

        $result = $this->service->searchByWorkspace(['tydal_test'], 42, '', [], [], 1, 20);

        $this->assertArrayHasKey('ids', $result);
        $this->assertArrayHasKey('total', $result);
        $this->assertArrayHasKey('facets', $result);
        $this->assertSame(['uuid-1', 'uuid-2'], $result['ids']);
        $this->assertSame(2, $result['total']);
    }

    public function test_search_by_workspace_active_filter_is_present(): void
    {
        $capturedParams = null;
        $this->mockClient
            ->shouldReceive('search')
            ->once()
            ->withArgs(function (array $params) use (&$capturedParams): bool {
                $capturedParams = $params;

                return true;
            })
            ->andReturn($this->fakeEsResponse());

        $this->service->searchByWorkspace(['tydal_test'], 42, '', [], [], 1, 20);

        $must = $capturedParams['body']['query']['bool']['must'];
        $this->assertContains(['term' => ['state' => ResourceState::LIVE->value]], $must);
    }

    // =========================================================================
    // knnSearchChunksForWorkspace
    // =========================================================================

    public function test_knn_chunks_for_workspace_filters_by_resource_id_terms(): void
    {
        $capturedParams = null;
        $this->mockClient
            ->shouldReceive('search')
            ->once()
            ->withArgs(function (array $params) use (&$capturedParams): bool {
                $capturedParams = $params;

                return true;
            })
            ->andReturn($this->fakeChunksResponse());

        $resourceIds = ['uuid-1', 'uuid-2', 'uuid-3'];
        $this->service->knnSearchChunksForWorkspace(['tydal_test_chunks'], $resourceIds, [0.1, 0.2], 5);

        $filter = $capturedParams['body']['knn']['filter'];
        $this->assertSame(['terms' => ['resource_id' => $resourceIds]], $filter);
    }

    public function test_knn_chunks_for_workspace_queries_multiple_indices(): void
    {
        $capturedParams = null;
        $this->mockClient
            ->shouldReceive('search')
            ->once()
            ->withArgs(function (array $params) use (&$capturedParams): bool {
                $capturedParams = $params;

                return true;
            })
            ->andReturn($this->fakeChunksResponse());

        $this->service->knnSearchChunksForWorkspace(
            ['tydal_multimedia_chunks', 'tydal_documents_chunks'],
            ['uuid-1'],
            [0.1, 0.2],
            5
        );

        $this->assertSame('tydal_multimedia_chunks,tydal_documents_chunks', $capturedParams['index']);
    }

    public function test_knn_chunks_for_workspace_returns_empty_for_empty_resource_ids(): void
    {
        $this->mockClient->shouldNotReceive('search');

        $result = $this->service->knnSearchChunksForWorkspace(['tydal_test_chunks'], [], [0.1], 5);

        $this->assertSame([], $result);
    }

    public function test_knn_chunks_for_workspace_returns_empty_for_empty_index_names(): void
    {
        $this->mockClient->shouldNotReceive('search');

        $result = $this->service->knnSearchChunksForWorkspace([], ['uuid-1'], [0.1], 5);

        $this->assertSame([], $result);
    }

    public function test_knn_chunks_for_workspace_returns_chunk_content_array(): void
    {
        $chunks = [
            ['chunk_id' => 'c1', 'resource_id' => 'r1', 'file_id' => 'f1', 'page_number' => 1, 'content' => 'hello world'],
            ['chunk_id' => 'c2', 'resource_id' => 'r2', 'file_id' => 'f2', 'page_number' => 3, 'content' => 'foo bar'],
        ];

        $this->mockClient
            ->shouldReceive('search')
            ->andReturn($this->fakeChunksResponse($chunks));

        $result = $this->service->knnSearchChunksForWorkspace(['idx_chunks'], ['r1', 'r2'], [0.1], 5);

        $this->assertCount(2, $result);
        $this->assertSame('hello world', $result[0]['content']);
        $this->assertSame(3, $result[1]['page_number']);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function fakeEsResponse(array $overrides = []): object
    {
        $hits = $overrides['hits'] ?? [];
        $total = $overrides['total'] ?? 0;

        return new class($hits, $total)
        {
            public function __construct(private array $hits, private int $total) {}

            public function asArray(): array
            {
                return [
                    'hits' => ['hits' => $this->hits, 'total' => ['value' => $this->total]],
                    'aggregations' => [],
                ];
            }
        };
    }

    private function fakeChunksResponse(array $chunks = []): object
    {
        return new class($chunks)
        {
            public function __construct(private array $chunks) {}

            public function asArray(): array
            {
                $hits = array_map(
                    fn ($c) => ['_id' => $c['chunk_id'] ?? 'x', '_score' => 1.0, '_source' => $c],
                    $this->chunks
                );

                return ['hits' => ['hits' => $hits, 'total' => ['value' => count($hits)]]];
            }
        };
    }
}
