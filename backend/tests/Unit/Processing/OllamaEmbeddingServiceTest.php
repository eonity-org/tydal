<?php

namespace Tests\Unit\Processing;

use App\Services\Processing\OllamaEmbeddingService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Tests for OllamaEmbeddingService.
 * All HTTP calls are intercepted with Http::fake() — no real Ollama server required.
 */
class OllamaEmbeddingServiceTest extends TestCase
{
    private function makeService(): OllamaEmbeddingService
    {
        config(['embedding.ollama.host' => 'http://ollama-test:11434']);
        config(['embedding.ollama.model' => 'nomic-embed-text']);
        config(['embedding.ollama.timeout' => 30]);
        config(['embedding.dimensions' => 3]);

        return new OllamaEmbeddingService;
    }

    // =========================================================================
    // embedBatch — /api/embed (batch endpoint)
    // =========================================================================

    public function test_embed_batch_calls_api_embed_endpoint(): void
    {
        Http::fake(['*/api/embed' => Http::response(
            json_encode(['embeddings' => [[0.1, 0.2, 0.3]]]),
            200
        )]);

        $this->makeService()->embedBatch(['hello world']);

        Http::assertSent(fn ($req) => str_ends_with($req->url(), '/api/embed'));
    }

    public function test_embed_batch_sends_model_and_input(): void
    {
        Http::fake(['*/api/embed' => Http::response(
            json_encode(['embeddings' => [[0.1, 0.2, 0.3], [0.4, 0.5, 0.6]]]),
            200
        )]);

        $this->makeService()->embedBatch(['text one', 'text two']);

        Http::assertSent(function ($req) {
            $body = $req->data();

            return $body['model'] === 'nomic-embed-text'
                && $body['input'] === ['text one', 'text two'];
        });
    }

    public function test_embed_batch_returns_correct_number_of_vectors(): void
    {
        Http::fake(['*/api/embed' => Http::response(
            json_encode(['embeddings' => [[0.1, 0.2, 0.3], [0.4, 0.5, 0.6]]]),
            200
        )]);

        $vectors = $this->makeService()->embedBatch(['a', 'b']);

        $this->assertCount(2, $vectors);
        $this->assertSame([0.1, 0.2, 0.3], $vectors[0]);
        $this->assertSame([0.4, 0.5, 0.6], $vectors[1]);
    }

    // =========================================================================
    // embedBatch — /api/embeddings (sequential fallback for older Ollama)
    // =========================================================================

    public function test_embed_batch_falls_back_to_sequential_on_404(): void
    {
        Http::fake([
            '*/api/embed' => Http::response('Not Found', 404),
            '*/api/embeddings' => Http::response(json_encode(['embedding' => [0.1, 0.2, 0.3]]), 200),
        ]);

        $vectors = $this->makeService()->embedBatch(['hello']);

        $this->assertCount(1, $vectors);
        $this->assertSame([0.1, 0.2, 0.3], $vectors[0]);
    }

    public function test_embed_batch_throws_on_server_error(): void
    {
        Http::fake(['*/api/embed' => Http::response('Internal Server Error', 500)]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Ollama embed failed \(HTTP 500\)/');

        $this->makeService()->embedBatch(['hello']);
    }

    // =========================================================================
    // embed (single)
    // =========================================================================

    public function test_embed_single_returns_one_vector(): void
    {
        Http::fake(['*/api/embed' => Http::response(
            json_encode(['embeddings' => [[0.1, 0.2, 0.3]]]),
            200
        )]);

        $vector = $this->makeService()->embed('hello');

        $this->assertSame([0.1, 0.2, 0.3], $vector);
    }

    // =========================================================================
    // Edge cases
    // =========================================================================

    public function test_empty_batch_returns_empty_array_without_http_call(): void
    {
        Http::fake();

        $result = $this->makeService()->embedBatch([]);

        $this->assertSame([], $result);
        Http::assertNothingSent();
    }

    public function test_dimensions_returns_config_value(): void
    {
        $this->assertSame(3, $this->makeService()->dimensions());
    }
}
