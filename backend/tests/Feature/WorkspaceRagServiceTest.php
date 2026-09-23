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
use App\Services\Processing\Contracts\EmbeddingServiceInterface;
use App\Services\RagService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Tests for RagService::askForWorkspace().
 *
 * Uses the same pattern as RagServiceTest — ES, embedder, and LLM are mocked;
 * the DB (SQLite in-memory) is used for real resource/workspace relations.
 */
class WorkspaceRagServiceTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private Resource $resource;

    private SearchIndex $searchIndex;

    private const FAKE_VECTOR = [0.1, 0.2, 0.3];

    private const FAKE_ANSWER = 'The workspace contains marketing materials.';

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $org = Organization::factory()->create();

        $scheme = CollectionScheme::create([
            'name' => 'test-scheme',
            'display_name' => 'Test Scheme',
            'accepted_mimetypes' => ['application/pdf'],
            'is_system' => false,
            'fields' => [],
        ]);

        $this->searchIndex = SearchIndex::create([
            'index_name' => 'tydal_test',
            'display_name' => 'Test Index',
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
            'name' => 'Brand Guidelines',
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
    // Happy path
    // =========================================================================

    public function test_ask_for_workspace_embeds_question_and_calls_knn_with_workspace_resource_ids(): void
    {
        $resourceId = $this->resource->id;

        $mockEmbedder = Mockery::mock(EmbeddingServiceInterface::class);
        $mockEmbedder->shouldReceive('embed')
            ->once()
            ->with('What is the brand color?')
            ->andReturn(self::FAKE_VECTOR);

        $mockEs = Mockery::mock(ElasticsearchService::class);
        $mockEs->shouldReceive('buildChunksIndexName')->andReturn('tydal_test_chunks');
        $mockEs->shouldReceive('chunksIndexExists')->andReturn(true);
        $mockEs->shouldReceive('knnSearchChunksForWorkspace')
            ->once()
            ->withArgs(function (array $indices, array $ids, array $vector, int $k) use ($resourceId): bool {
                return $indices === ['tydal_test_chunks']
                    && in_array($resourceId, $ids, true)
                    && $vector === self::FAKE_VECTOR
                    && $k === 5;
            })
            ->andReturn([]);

        $mockLlm = Mockery::mock(LlmServiceInterface::class);

        $rag = new RagService($mockEs, $mockEmbedder, $mockLlm);
        $rag->askForWorkspace($this->workspace, 'What is the brand color?');
    }

    public function test_ask_for_workspace_returns_answer_and_sources(): void
    {
        $hits = $this->fakeHits(2);

        $mockEs = $this->mockEs($hits);
        $mockLlm = $this->mockLlm(self::FAKE_ANSWER);

        $rag = new RagService($mockEs, $this->mockEmbedder(), $mockLlm);
        $result = $rag->askForWorkspace($this->workspace, 'What are the brand guidelines?');

        $this->assertArrayHasKey('answer', $result);
        $this->assertArrayHasKey('sources', $result);
        $this->assertSame(self::FAKE_ANSWER, $result['answer']);
        // Two hits for the same resource collapse into one source entry
        $this->assertCount(1, $result['sources']);
    }

    public function test_ask_for_workspace_sources_include_resource_name(): void
    {
        $mockEs = $this->mockEs($this->fakeHits(1));
        $mockLlm = $this->mockLlm('answer');

        $rag = new RagService($mockEs, $this->mockEmbedder(), $mockLlm);
        $result = $rag->askForWorkspace($this->workspace, 'question');

        $source = $result['sources'][0];
        $this->assertSame('Brand Guidelines', $source['resource_name']);
        $this->assertArrayHasKey('resource_id', $source);
        $this->assertArrayHasKey('file_id', $source);
        $this->assertArrayHasKey('pages', $source);
    }

    // =========================================================================
    // Empty workspace
    // =========================================================================

    public function test_ask_for_workspace_returns_early_when_workspace_has_no_resources(): void
    {
        $this->workspace->resources()->detach();

        $mockEs = Mockery::mock(ElasticsearchService::class);
        $mockEmbedder = Mockery::mock(EmbeddingServiceInterface::class);
        $mockLlm = Mockery::mock(LlmServiceInterface::class);

        $mockEs->shouldNotReceive('knnSearchChunksForWorkspace');
        $mockEmbedder->shouldNotReceive('embed');
        $mockLlm->shouldNotReceive('chat');

        $rag = new RagService($mockEs, $mockEmbedder, $mockLlm);
        $result = $rag->askForWorkspace($this->workspace, 'Any question?');

        $this->assertStringContainsStringIgnoringCase('no resources', $result['answer']);
        $this->assertEmpty($result['sources']);
    }

    // =========================================================================
    // No chunks index
    // =========================================================================

    public function test_ask_for_workspace_throws_runtime_exception_when_no_chunks_index_exists(): void
    {
        $mockEs = Mockery::mock(ElasticsearchService::class);
        $mockEs->shouldReceive('buildChunksIndexName')->andReturn('tydal_test_chunks');
        $mockEs->shouldReceive('chunksIndexExists')->andReturn(false);

        $rag = new RagService($mockEs, Mockery::mock(EmbeddingServiceInterface::class), Mockery::mock(LlmServiceInterface::class));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/No vector search index/');

        $rag->askForWorkspace($this->workspace, 'What is in this workspace?');
    }

    // =========================================================================
    // Metadata chunks
    // =========================================================================

    public function test_metadata_chunks_are_excluded_from_sources(): void
    {
        // A hit whose chunk_id starts with "meta-" should appear in the LLM
        // context (to inform the answer) but NOT in the returned sources array.
        $metaHit = [
            'chunk_id' => 'meta-'.$this->resource->id,
            'resource_id' => $this->resource->id,
            'file_id' => '',
            'page_number' => null,
            'content' => 'Resource: Brand Guidelines\nDescription: Corporate brand standards.',
        ];
        $textHit = [
            'chunk_id' => 'chunk-uuid-1',
            'resource_id' => $this->resource->id,
            'file_id' => 'file-uuid-1',
            'page_number' => 3,
            'content' => 'The primary color is deep red #911A2C.',
        ];

        $mockEs = $this->mockEs([$metaHit, $textHit]);
        $mockLlm = $this->mockLlm('The primary color is deep red.');

        $rag = new RagService($mockEs, $this->mockEmbedder(), $mockLlm);
        $result = $rag->askForWorkspace($this->workspace, 'What is the brand color?');

        // Only the text hit should appear in sources (meta chunk excluded from citations)
        $this->assertCount(1, $result['sources']);
        $this->assertSame($this->resource->id, $result['sources'][0]['resource_id']);
        $this->assertSame([3], $result['sources'][0]['pages']);
    }

    public function test_strict_mode_excludes_metadata_chunk_from_context(): void
    {
        // In strict mode the metadata chunk must NOT reach the LLM even if k-NN returns it.
        // We verify by checking that chat() receives no [Resource overview:] block —
        // we capture the messages argument and assert it contains no metadata heading.
        $metaHit = [
            'chunk_id' => 'meta-'.$this->resource->id,
            'resource_id' => $this->resource->id,
            'file_id' => '',
            'page_number' => null,
            'content' => 'Resource: Brand Guidelines',
        ];
        $textHit = [
            'chunk_id' => 'chunk-uuid-1',
            'resource_id' => $this->resource->id,
            'file_id' => 'file-uuid-1',
            'page_number' => 2,
            'content' => 'The primary color is deep red.',
        ];

        $capturedMessages = null;
        $mockEs = $this->mockEs([$metaHit, $textHit]);
        $mockLlm = Mockery::mock(LlmServiceInterface::class);
        $mockLlm->shouldReceive('getModel')->andReturn('test-model');
        $mockLlm->shouldReceive('chat')
            ->once()
            ->withArgs(function (array $messages) use (&$capturedMessages): bool {
                $capturedMessages = $messages;

                return true;
            })
            ->andReturn('The primary color is deep red.');

        $rag = new RagService($mockEs, $this->mockEmbedder(), $mockLlm);
        $result = $rag->askForWorkspace($this->workspace, 'What is the brand color?', 5, strict: true);

        $userContent = $capturedMessages[1]['content'] ?? '';
        $this->assertStringNotContainsString('[Resource overview:', $userContent);
        $this->assertStringContainsString('[Source:', $userContent);
        $this->assertSame('The primary color is deep red.', $result['answer']);
    }

    public function test_metadata_chunk_content_reaches_llm_context(): void
    {
        // When only a metadata chunk is returned (e.g. name matches the query but
        // no text passage does), the LLM should still receive context and produce an answer.
        $metaHit = [
            'chunk_id' => 'meta-'.$this->resource->id,
            'resource_id' => $this->resource->id,
            'file_id' => '',
            'page_number' => null,
            'content' => 'Resource: Brand Guidelines\nDescription: Contains primary color #911A2C.',
        ];

        $mockEs = $this->mockEs([$metaHit]);
        $mockLlm = $this->mockLlm('The brand color is #911A2C.');

        $rag = new RagService($mockEs, $this->mockEmbedder(), $mockLlm);
        $result = $rag->askForWorkspace($this->workspace, 'What is the brand color?');

        $this->assertSame('The brand color is #911A2C.', $result['answer']);
        $this->assertEmpty($result['sources']); // no page citations
    }

    // =========================================================================
    // Guaranteed meta-chunk coverage
    // =========================================================================

    public function test_meta_chunk_absent_from_knn_results_is_still_injected_into_context(): void
    {
        // Simulates the "dogs" bug: a resource whose only signal is a tag is not
        // ranked by k-NN, but its meta chunk must still reach the LLM context so
        // identification questions like "which one is about dogs?" can be answered.
        $org = Organization::factory()->create();
        $user = User::factory()->create();

        $scheme = CollectionScheme::create([
            'name' => 'ts2', 'display_name' => 'TS2',
            'accepted_mimetypes' => [], 'is_system' => false, 'fields' => [],
        ]);
        $collection2 = Collection::factory()->create([
            'organization_id' => $org->id,
            'user_owner_id' => $user->id,
            'scheme_id' => $scheme->id,
            'index_id' => $this->searchIndex->id,
        ]);
        $dogResource = Resource::factory()->create([
            'collection_id' => $collection2->id,
            'organization_id' => $org->id,
            'user_owner_id' => $user->id,
            'name' => 'Puppy Photo',
            'state' => ResourceState::LIVE->value,
        ]);
        $this->workspace->resources()->attach($dogResource->id);

        // k-NN returns only chunks from the non-dog resource
        $knnHits = $this->fakeHits(2);

        // The dog resource's meta chunk exists in ES but wasn't ranked in top-k
        $dogMetaChunk = [
            'chunk_id' => 'meta-'.$dogResource->id,
            'resource_id' => $dogResource->id,
            'file_id' => '',
            'page_number' => null,
            'content' => "Resource: Puppy Photo\nTags: dogs",
        ];

        $capturedMessages = null;
        $mockEs = $this->mockEs($knnHits, [$dogMetaChunk]);
        $mockLlm = Mockery::mock(LlmServiceInterface::class);
        $mockLlm->shouldReceive('getModel')->andReturn('test-model');
        $mockLlm->shouldReceive('chat')
            ->once()
            ->withArgs(function (array $messages) use (&$capturedMessages): bool {
                $capturedMessages = $messages;

                return true;
            })
            ->andReturn('Puppy Photo is about dogs.');

        $rag = new RagService($mockEs, $this->mockEmbedder(), $mockLlm);
        $result = $rag->askForWorkspace($this->workspace, 'Which one is about dogs?');

        $userContent = $capturedMessages[1]['content'] ?? '';
        $this->assertStringContainsString('Tags: dogs', $userContent);
        $this->assertStringContainsString('[Resource overview: Puppy Photo]', $userContent);
        $this->assertSame('Puppy Photo is about dogs.', $result['answer']);

        // Answer names "Puppy Photo" → answer-confirmed citation; Brand Guidelines
        // had text chunks in k-NN but the LLM answer did not mention it, so it is
        // correctly excluded from sources.
        $this->assertCount(1, $result['sources']);
        $this->assertSame($dogResource->id, $result['sources'][0]['resource_id']);
        $this->assertSame('Puppy Photo', $result['sources'][0]['resource_name']);
        $this->assertSame([], $result['sources'][0]['pages']);
    }

    // =========================================================================
    // No k-NN hits
    // =========================================================================

    public function test_ask_for_workspace_returns_no_content_message_when_knn_returns_empty(): void
    {
        $mockEs = $this->mockEs([]);
        $mockLlm = Mockery::mock(LlmServiceInterface::class);
        $mockLlm->shouldNotReceive('chat');

        $rag = new RagService($mockEs, $this->mockEmbedder(), $mockLlm);
        $result = $rag->askForWorkspace($this->workspace, 'Where is the style guide?');

        $this->assertStringContainsString('No relevant content', $result['answer']);
        $this->assertEmpty($result['sources']);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function fakeHits(int $count): array
    {
        $hits = [];
        for ($i = 1; $i <= $count; $i++) {
            $hits[] = [
                'chunk_id' => "chunk-uuid-{$i}",
                'resource_id' => $this->resource->id,
                'file_id' => 'file-uuid-1',
                'page_number' => $i,
                'content' => "content passage {$i}",
            ];
        }

        return $hits;
    }

    private function mockEs(array $hits, array $metaChunks = []): ElasticsearchService
    {
        $mock = Mockery::mock(ElasticsearchService::class);
        $mock->shouldReceive('buildChunksIndexName')->andReturn('tydal_test_chunks');
        $mock->shouldReceive('chunksIndexExists')->andReturn(true);
        $mock->shouldReceive('knnSearchChunksForWorkspace')->andReturn($hits);
        $mock->shouldReceive('fetchMetaChunksForResources')->andReturn($metaChunks);

        return $mock;
    }

    private function mockEmbedder(): EmbeddingServiceInterface
    {
        $mock = Mockery::mock(EmbeddingServiceInterface::class);
        $mock->shouldReceive('embed')->andReturn(self::FAKE_VECTOR);

        return $mock;
    }

    private function mockLlm(string $answer = self::FAKE_ANSWER): LlmServiceInterface
    {
        $mock = Mockery::mock(LlmServiceInterface::class);
        $mock->shouldReceive('chat')->andReturn($answer);
        $mock->shouldReceive('getModel')->andReturn('test-model');

        return $mock;
    }
}
