<?php

namespace Tests\Unit\Processing;

use App\Services\Processing\VoyageEmbeddingService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Tests for VoyageEmbeddingService.
 * All HTTP calls are intercepted with Http::fake() — no real Voyage AI API required.
 */
class VoyageEmbeddingServiceTest extends TestCase
{
    private function makeService(): VoyageEmbeddingService
    {
        config(['embedding.voyage.api_key' => 'test-key']);
        config(['embedding.voyage.model' => 'voyage-3']);
        config(['embedding.voyage.timeout' => 30]);
        config(['embedding.dimensions' => 3]);

        return new VoyageEmbeddingService;
    }

    private function voyageResponse(array $vectors): string
    {
        $data = [];
        foreach ($vectors as $i => $vector) {
            $data[] = ['index' => $i, 'embedding' => $vector];
        }

        return json_encode(['data' => $data]);
    }

    // =========================================================================
    // embedBatch
    // =========================================================================

    public function test_embed_batch_posts_to_voyage_api(): void
    {
        Http::fake(['*/v1/embeddings' => Http::response(
            $this->voyageResponse([[0.1, 0.2, 0.3]]),
            200
        )]);

        $this->makeService()->embedBatch(['hello']);

        Http::assertSent(fn ($req) => str_contains($req->url(), 'voyageai.com/v1/embeddings'));
    }

    public function test_embed_batch_sends_bearer_token_and_model(): void
    {
        Http::fake(['*/v1/embeddings' => Http::response(
            $this->voyageResponse([[0.1, 0.2, 0.3]]),
            200
        )]);

        $this->makeService()->embedBatch(['hello']);

        Http::assertSent(function ($req) {
            return $req->header('Authorization')[0] === 'Bearer test-key'
                && $req->data()['model'] === 'voyage-3';
        });
    }

    public function test_embed_batch_returns_vectors_sorted_by_index(): void
    {
        // Voyage may return results out of order — service must sort by index
        $outOfOrder = json_encode(['data' => [
            ['index' => 1, 'embedding' => [0.4, 0.5, 0.6]],
            ['index' => 0, 'embedding' => [0.1, 0.2, 0.3]],
        ]]);

        Http::fake(['*/v1/embeddings' => Http::response($outOfOrder, 200)]);

        $vectors = $this->makeService()->embedBatch(['first', 'second']);

        $this->assertSame([0.1, 0.2, 0.3], $vectors[0]);
        $this->assertSame([0.4, 0.5, 0.6], $vectors[1]);
    }

    public function test_embed_batch_throws_on_api_error(): void
    {
        Http::fake(['*/v1/embeddings' => Http::response('{"error":"invalid key"}', 401)]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Voyage AI embed failed \(HTTP 401\)/');

        $this->makeService()->embedBatch(['hello']);
    }

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
