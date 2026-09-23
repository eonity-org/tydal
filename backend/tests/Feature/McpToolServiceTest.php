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
use App\Services\LLM\Contracts\LlmServiceInterface;
use App\Services\McpToolService;
use App\Services\Processing\Contracts\EmbeddingServiceInterface;
use App\Services\RagService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Tests for McpToolService — the business logic layer behind the MCP stdio server.
 *
 * ES, embedder, and LLM are mocked; DB (SQLite in-memory) is used for real
 * workspace / resource relations so we test the actual query paths.
 */
class McpToolServiceTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private Resource $resource;

    private SearchIndex $searchIndex;

    private const FAKE_VECTOR = [0.1, 0.2, 0.3];

    private const FAKE_ANSWER = 'The primary color is deep red.';

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $org = Organization::factory()->create();

        $scheme = CollectionScheme::create([
            'name' => 'mcp-scheme',
            'display_name' => 'MCP Test Scheme',
            'accepted_mimetypes' => ['application/pdf'],
            'is_system' => false,
            'fields' => [],
        ]);

        $this->searchIndex = SearchIndex::create([
            'index_name' => 'tydal_mcp_test',
            'display_name' => 'MCP Test Index',
            'is_active' => true,
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
            'name' => 'Brand Colors Guide',
            'description' => 'Official brand color palette.',
            'state' => ResourceState::LIVE->value,
        ]);

        $this->workspace = Workspace::factory()->create([
            'organization_id' => $org->id,
            'user_owner_id' => $user->id,
            'is_default' => false,
        ]);
        $this->workspace->resources()->attach($this->resource->id);
    }

    // =========================================================================
    // search_workspace
    // =========================================================================

    public function test_search_workspace_returns_resources_for_valid_workspace(): void
    {
        $svc = new McpToolService($this->dummyRag());
        $result = $svc->searchWorkspace($this->workspace->id);

        $this->assertArrayHasKey('resources', $result);
        $this->assertCount(1, $result['resources']);
        $this->assertSame('Brand Colors Guide', $result['resources'][0]['name']);
        $this->assertSame($this->workspace->name, $result['workspace']);
    }

    public function test_search_workspace_filters_by_query(): void
    {
        $svc = new McpToolService($this->dummyRag());

        $hit = $svc->searchWorkspace($this->workspace->id, query: 'Brand');
        $miss = $svc->searchWorkspace($this->workspace->id, query: 'ZZZ_NOMATCH');

        $this->assertCount(1, $hit['resources']);
        $this->assertCount(0, $miss['resources']);
    }

    public function test_search_workspace_returns_error_for_unknown_workspace(): void
    {
        $svc = new McpToolService($this->dummyRag());
        $result = $svc->searchWorkspace(99999);

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('99999', $result['error']);
    }

    public function test_search_workspace_limit_is_clamped(): void
    {
        // Create 5 extra resources
        $collection = $this->resource->collection;
        for ($i = 0; $i < 5; $i++) {
            $r = Resource::factory()->create([
                'collection_id' => $collection->id,
                'organization_id' => $collection->organization_id,
                'user_owner_id' => $collection->user_owner_id,
                'state' => ResourceState::LIVE->value,
            ]);
            $this->workspace->resources()->attach($r->id);
        }

        $svc = new McpToolService($this->dummyRag());
        $result = $svc->searchWorkspace($this->workspace->id, limit: 3);

        $this->assertCount(3, $result['resources']);
    }

    // =========================================================================
    // ask_workspace
    // =========================================================================

    public function test_ask_workspace_returns_answer_and_sources(): void
    {
        $hits = [
            [
                'chunk_id' => 'chunk-1',
                'resource_id' => $this->resource->id,
                'file_id' => 'file-1',
                'page_number' => 1,
                'content' => 'The primary color is deep red #911A2C.',
            ],
        ];

        $rag = $this->mockRag($hits, self::FAKE_ANSWER);
        $svc = new McpToolService($rag);
        $result = $svc->askWorkspace($this->workspace->id, 'What is the brand color?');

        $this->assertSame(self::FAKE_ANSWER, $result['answer']);
        $this->assertCount(1, $result['sources']);
    }

    public function test_ask_workspace_returns_error_for_unknown_workspace(): void
    {
        $svc = new McpToolService($this->dummyRag());
        $result = $svc->askWorkspace(99999, 'Any question?');

        $this->assertArrayHasKey('error', $result);
    }

    public function test_ask_workspace_returns_error_for_empty_question(): void
    {
        $svc = new McpToolService($this->dummyRag());
        $result = $svc->askWorkspace($this->workspace->id, '');

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('question', $result['error']);
    }

    public function test_ask_workspace_wraps_runtime_exception_as_error(): void
    {
        $ragMock = Mockery::mock(RagService::class);
        $ragMock->shouldReceive('askForWorkspace')
            ->andThrow(new \RuntimeException('No vector search index is available for this workspace.'));

        $svc = new McpToolService($ragMock);
        $result = $svc->askWorkspace($this->workspace->id, 'What is the brand color?');

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('No vector search index', $result['error']);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function dummyRag(): RagService
    {
        $mock = Mockery::mock(RagService::class);

        // No expectations — should not be called by search_workspace
        return $mock;
    }

    private function mockRag(array $hits, string $answer): RagService
    {
        $mockEmbedder = Mockery::mock(EmbeddingServiceInterface::class);
        $mockEmbedder->shouldReceive('embed')->andReturn(self::FAKE_VECTOR);

        $mockEs = Mockery::mock(ElasticsearchService::class);
        $mockEs->shouldReceive('buildChunksIndexName')->andReturn('tydal_mcp_test_chunks');
        $mockEs->shouldReceive('chunksIndexExists')->andReturn(true);
        $mockEs->shouldReceive('knnSearchChunksForWorkspace')->andReturn($hits);
        $mockEs->shouldReceive('fetchMetaChunksForResources')->andReturn([]);

        $mockLlm = Mockery::mock(LlmServiceInterface::class);
        $mockLlm->shouldReceive('chat')->andReturn($answer);
        $mockLlm->shouldReceive('getModel')->andReturn('test-model');

        return new RagService($mockEs, $mockEmbedder, $mockLlm);
    }
}
