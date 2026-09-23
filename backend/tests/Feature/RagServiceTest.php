<?php

namespace Tests\Feature;

use App\Enums\ResourceState;
use App\Models\Collection;
use App\Models\CollectionScheme;
use App\Models\Organization;
use App\Models\Resource;
use App\Models\SearchIndex;
use App\Models\User;
use App\Services\ElasticsearchService;
use App\Services\LLM\Contracts\LlmServiceInterface;
use App\Services\Processing\Contracts\EmbeddingServiceInterface;
use App\Services\RagService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Integration tests for RagService.
 *
 * ElasticsearchService, EmbeddingServiceInterface, and LlmServiceInterface
 * are replaced with Mockery mocks — no real ES, Ollama, or LLM API required.
 */
class RagServiceTest extends TestCase
{
    use RefreshDatabase;

    private Collection $collection;

    private Resource $resource;

    private const FAKE_VECTOR = [0.1, 0.2, 0.3];

    private const FAKE_ANSWER = 'The safety requirements are described in section 3.';

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
            'name' => 'test-scheme',
            'display_name' => 'Test Scheme',
            'accepted_mimetypes' => ['application/pdf'],
            'is_system' => false,
            'fields' => [],
        ]);

        $this->collection = Collection::factory()->create([
            'organization_id' => $org->id,
            'user_owner_id' => $user->id,
            'scheme_id' => $scheme->id,
            'index_id' => $searchIndex->id,
        ]);

        $this->resource = Resource::factory()->create([
            'collection_id' => $this->collection->id,
            'organization_id' => $org->id,
            'user_owner_id' => $user->id,
            'name' => 'Safety Manual v2',
            'state' => ResourceState::LIVE->value,
        ]);
    }

    // =========================================================================
    // Core RAG flow
    // =========================================================================

    public function test_ask_embeds_question_and_searches_chunks(): void
    {
        $collectionId = $this->collection->id;

        $mockEmbedder = Mockery::mock(EmbeddingServiceInterface::class);
        $mockEmbedder->shouldReceive('embed')
            ->once()
            ->with('What are the safety requirements?')
            ->andReturn(self::FAKE_VECTOR);

        $mockEs = Mockery::mock(ElasticsearchService::class);
        $mockEs->shouldReceive('buildChunksIndexName')->andReturn('tydal_test_chunks');
        $mockEs->shouldReceive('knnSearchChunksForRag')
            ->once()
            ->with('tydal_test_chunks', $collectionId, self::FAKE_VECTOR, 5, null)
            ->andReturn([]);

        $mockLlm = Mockery::mock(LlmServiceInterface::class);

        $rag = new RagService($mockEs, $mockEmbedder, $mockLlm);
        $rag->ask($this->collection->load('searchIndex'), 'What are the safety requirements?');
    }

    public function test_org_rag_min_score_override_reaches_retrieval(): void
    {
        $this->collection->organization->update([
            'settings' => ['aity' => ['rag_min_score' => 0.66]],
        ]);

        $mockEmbedder = Mockery::mock(EmbeddingServiceInterface::class);
        $mockEmbedder->shouldReceive('embed')->andReturn(self::FAKE_VECTOR);

        $mockEs = Mockery::mock(ElasticsearchService::class);
        $mockEs->shouldReceive('buildChunksIndexName')->andReturn('tydal_test_chunks');
        $mockEs->shouldReceive('knnSearchChunksForRag')
            ->once()
            ->withArgs(fn (...$args) => ($args[4] ?? null) === 0.66)
            ->andReturn([]);

        $mockLlm = Mockery::mock(LlmServiceInterface::class);

        $rag = new RagService($mockEs, $mockEmbedder, $mockLlm);
        $rag->ask($this->collection->fresh()->load('searchIndex', 'organization'), 'anything?');
    }

    public function test_ask_returns_answer_and_sources(): void
    {
        $hits = $this->fakeHits(2);

        $mockEs = $this->mockEs($hits);
        $mockEmbedder = $this->mockEmbedder();
        $mockLlm = $this->mockLlm(self::FAKE_ANSWER);

        $rag = new RagService($mockEs, $mockEmbedder, $mockLlm);
        $result = $rag->ask($this->collection->load('searchIndex'), 'What are the requirements?');

        $this->assertArrayHasKey('answer', $result);
        $this->assertArrayHasKey('sources', $result);
        $this->assertSame(self::FAKE_ANSWER, $result['answer']);
        // Two hits for the same resource collapse into one source entry
        $this->assertCount(1, $result['sources']);
    }

    public function test_sources_contain_correct_fields(): void
    {
        $hits = $this->fakeHits(1);

        $mockEs = $this->mockEs($hits);
        $mockLlm = $this->mockLlm('answer');

        $rag = new RagService($mockEs, $this->mockEmbedder(), $mockLlm);
        $result = $rag->ask($this->collection->load('searchIndex'), 'question');

        $source = $result['sources'][0];
        $this->assertArrayHasKey('resource_id', $source);
        $this->assertArrayHasKey('resource_name', $source);
        $this->assertArrayHasKey('file_id', $source);
        $this->assertArrayHasKey('pages', $source);
        $this->assertSame('Safety Manual v2', $source['resource_name']);
    }

    public function test_context_injected_into_llm_prompt_contains_source_heading(): void
    {
        $hits = $this->fakeHits(1);

        $mockEs = $this->mockEs($hits);
        $mockLlm = Mockery::mock(LlmServiceInterface::class);
        $mockLlm->shouldReceive('getModel')->andReturn('test-model');
        $mockLlm->shouldReceive('chat')
            ->once()
            ->withArgs(function ($messages) {
                $userContent = collect($messages)->firstWhere('role', 'user')['content'] ?? '';

                return str_contains($userContent, '[Source: Safety Manual v2')
                    && str_contains($userContent, 'chunk content here');
            })
            ->andReturn('answer');

        $rag = new RagService($mockEs, $this->mockEmbedder(), $mockLlm);
        $rag->ask($this->collection->load('searchIndex'), 'question');
    }

    public function test_sources_are_deduplicated_by_chunk_id(): void
    {
        // Two hits with the same chunk_id (simulates duplicate in ES results)
        $chunkId = 'same-chunk-uuid';
        $hits = [
            $this->makeHit($chunkId, 1),
            $this->makeHit($chunkId, 1),
        ];

        $mockEs = $this->mockEs($hits);
        $mockLlm = $this->mockLlm('answer');

        $rag = new RagService($mockEs, $this->mockEmbedder(), $mockLlm);
        $result = $rag->ask($this->collection->load('searchIndex'), 'question');

        $this->assertCount(1, $result['sources']);
    }

    // =========================================================================
    // Empty results
    // =========================================================================

    public function test_ask_returns_no_content_message_when_no_chunks_found(): void
    {
        $mockEs = $this->mockEs([]);
        $mockLlm = Mockery::mock(LlmServiceInterface::class);
        $mockLlm->shouldNotReceive('chat');

        $rag = new RagService($mockEs, $this->mockEmbedder(), $mockLlm);
        $result = $rag->ask($this->collection->load('searchIndex'), 'question');

        $this->assertStringContainsString('No relevant content', $result['answer']);
        $this->assertEmpty($result['sources']);
    }

    // =========================================================================
    // Error handling
    // =========================================================================

    public function test_ask_throws_when_collection_has_no_search_index(): void
    {
        $this->collection->update(['index_id' => null]);

        $mockEs = Mockery::mock(ElasticsearchService::class);
        $mockEmbedder = Mockery::mock(EmbeddingServiceInterface::class);
        $mockLlm = Mockery::mock(LlmServiceInterface::class);

        $rag = new RagService($mockEs, $mockEmbedder, $mockLlm);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no search index configured');

        $rag->ask($this->collection->load('searchIndex'), 'question');
    }

    public function test_context_is_truncated_to_max_chars(): void
    {
        config(['llm.max_context_chars' => 50]); // very small budget

        // Each chunk has content > 50 chars; only the first should fit
        $hits = [
            $this->makeHit('chunk-1', 1, str_repeat('A', 60)),
            $this->makeHit('chunk-2', 2, str_repeat('B', 60)),
        ];

        $mockEs = $this->mockEs($hits);
        $mockLlm = Mockery::mock(LlmServiceInterface::class);
        $mockLlm->shouldReceive('getModel')->andReturn('test-model');
        $mockLlm->shouldReceive('chat')
            ->once()
            ->withArgs(function ($messages) {
                $content = collect($messages)->firstWhere('role', 'user')['content'] ?? '';

                return ! str_contains($content, str_repeat('B', 60)); // second chunk excluded
            })
            ->andReturn('answer');

        $rag = new RagService($mockEs, $this->mockEmbedder(), $mockLlm);
        $rag->ask($this->collection->load('searchIndex'), 'question');
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function fakeHits(int $count): array
    {
        $hits = [];
        for ($i = 1; $i <= $count; $i++) {
            $hits[] = $this->makeHit("chunk-uuid-{$i}", $i);
        }

        return $hits;
    }

    private function makeHit(string $chunkId, int $pageNumber, string $content = 'chunk content here'): array
    {
        return [
            'chunk_id' => $chunkId,
            'resource_id' => $this->resource->id,
            'file_id' => 'file-uuid-1',
            'page_number' => $pageNumber,
            'content' => $content,
        ];
    }

    private function mockEs(array $hits): ElasticsearchService
    {
        $mock = Mockery::mock(ElasticsearchService::class);
        $mock->shouldReceive('buildChunksIndexName')->andReturn('tydal_test_chunks');
        $mock->shouldReceive('knnSearchChunksForRag')->andReturn($hits);
        $mock->shouldReceive('fetchMetaChunksForCollection')->andReturn([]);

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
