<?php

namespace App\Services\Processing;

use App\Services\Processing\Contracts\EmbeddingServiceInterface;
use Illuminate\Support\Facades\Http;

class VoyageEmbeddingService implements EmbeddingServiceInterface
{
    private const API_URL = 'https://api.voyageai.com/v1/embeddings';

    private string $apiKey;

    private string $model;

    private int $timeout;

    private int $dimensions;

    public function __construct()
    {
        $this->apiKey = config('embedding.voyage.api_key', '');
        $this->model = config('embedding.voyage.model', 'voyage-3');
        $this->timeout = config('embedding.voyage.timeout', 30);
        $this->dimensions = config('embedding.dimensions', 768);
    }

    public function embed(string $text): array
    {
        return $this->embedBatch([$text])[0];
    }

    /**
     * Voyage AI supports native batch embedding.
     *
     * @param  string[]  $texts
     * @return float[][]
     */
    public function embedBatch(array $texts): array
    {
        if (empty($texts)) {
            return [];
        }

        $response = Http::withOptions(['timeout' => $this->timeout])
            ->withToken($this->apiKey)
            ->post(self::API_URL, [
                'model' => $this->model,
                'input' => $texts,
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException(
                "Voyage AI embed failed (HTTP {$response->status()}): ".$response->body()
            );
        }

        $data = $response->json('data', []);

        // Sort by index to guarantee order matches input
        usort($data, fn ($a, $b) => $a['index'] <=> $b['index']);

        return array_column($data, 'embedding');
    }

    public function dimensions(): int
    {
        return $this->dimensions;
    }
}
