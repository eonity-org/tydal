<?php

namespace App\Services\Processing;

use App\Services\Processing\Contracts\EmbeddingServiceInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OllamaEmbeddingService implements EmbeddingServiceInterface
{
    private string $host;

    private string $model;

    private int $timeout;

    private int $dimensions;

    private bool $truncate;

    private int $maxInputChars;

    public function __construct()
    {
        $this->host = rtrim(config('embedding.ollama.host', 'http://localhost:11434'), '/');
        $this->model = config('embedding.ollama.model', 'nomic-embed-text');
        $this->timeout = config('embedding.ollama.timeout', 120);
        $this->dimensions = config('embedding.dimensions', 768);
        $this->truncate = (bool) config('embedding.ollama.truncate', true);
        $this->maxInputChars = (int) config('embedding.ollama.max_input_chars', 0);
    }

    /**
     * Hard safety cap on input length. Ollama's own `truncate` flag is unreliable
     * for token-dense multibyte text (it can still 400 with "input length exceeds
     * the context length"), so we pre-truncate by characters to keep requests
     * within the model's context window. Disabled when max_input_chars <= 0.
     *
     * @param  string[]  $texts
     * @return string[]
     */
    private function capInputs(array $texts): array
    {
        if ($this->maxInputChars <= 0) {
            return $texts;
        }

        $capped = 0;

        $result = array_map(function (string $text) use (&$capped) {
            if (mb_strlen($text) > $this->maxInputChars) {
                $capped++;

                return mb_substr($text, 0, $this->maxInputChars);
            }

            return $text;
        }, $texts);

        if ($capped > 0) {
            Log::warning('Ollama embedding input truncated to fit context window', [
                'model' => $this->model,
                'max_input_chars' => $this->maxInputChars,
                'inputs_truncated' => $capped,
                'total_inputs' => count($texts),
            ]);
        }

        return $result;
    }

    public function embed(string $text): array
    {
        return $this->embedBatch([$text])[0];
    }

    /**
     * Ollama's /api/embed endpoint accepts an array of strings natively (Ollama ≥ 0.3).
     * Falls back to sequential /api/embeddings calls if the batch endpoint returns 404.
     *
     * @param  string[]  $texts
     * @return float[][]
     */
    public function embedBatch(array $texts): array
    {
        if (empty($texts)) {
            return [];
        }

        $texts = $this->capInputs($texts);

        $response = Http::withOptions(['timeout' => $this->timeout])
            ->post("{$this->host}/api/embed", [
                'model' => $this->model,
                'input' => $texts,
                'truncate' => $this->truncate,
            ]);

        if ($response->successful()) {
            $data = $response->json();
            // /api/embed returns {"embeddings": [[...],...]}
            if (isset($data['embeddings']) && is_array($data['embeddings'])) {
                return $data['embeddings'];
            }
        }

        // Fallback: /api/embeddings (single-text, older Ollama that lacks /api/embed)
        if ($response->status() === 404) {
            return $this->embedSequential($texts);
        }

        throw new \RuntimeException(
            "Ollama embed failed (HTTP {$response->status()}): ".$response->body()
        );
    }

    public function dimensions(): int
    {
        return $this->dimensions;
    }

    private function embedSequential(array $texts): array
    {
        $texts = $this->capInputs($texts);

        $vectors = [];
        foreach ($texts as $text) {
            $response = Http::withOptions(['timeout' => $this->timeout])
                ->post("{$this->host}/api/embeddings", [
                    'model' => $this->model,
                    'prompt' => $text,
                    'truncate' => $this->truncate,
                ]);

            if (! $response->successful()) {
                throw new \RuntimeException(
                    "Ollama embeddings failed (HTTP {$response->status()}): ".$response->body()
                );
            }

            $vectors[] = $response->json('embedding');
        }

        return $vectors;
    }
}
