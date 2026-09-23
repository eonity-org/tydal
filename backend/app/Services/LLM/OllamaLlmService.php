<?php

namespace App\Services\LLM;

use App\Services\LLM\Contracts\LlmServiceInterface;
use App\Services\LLM\Contracts\StreamingLlmInterface;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Ollama local LLM driver.
 *
 * Talks to the same Ollama instance used for embeddings (OLLAMA_HOST),
 * but targets a separate chat/vision model (OLLAMA_LLM_MODEL).
 *
 * Ollama exposes an OpenAI-compatible /v1/chat/completions endpoint.
 * Vision is supported for multimodal models (e.g. llama3.2-vision, llava)
 * via the standard OpenAI image_url content block format.
 */
class OllamaLlmService implements LlmServiceInterface, StreamingLlmInterface
{
    private string $host;

    private string $model;

    private int $timeout;

    private int $connectTimeout;

    private int $maxTokens;

    private float $repeatPenalty;

    private ?float $temperature;

    public function __construct(bool $forVision = false)
    {
        // Reuse OLLAMA_HOST — same instance as the embedding service
        $this->host = rtrim(config('llm.ollama.host', 'http://localhost:11434'), '/');

        // Vision uses OLLAMA_VISION_MODEL when set; falls back to OLLAMA_LLM_MODEL.
        // Text models (llama3.2) will reject image input — use a multimodal model
        // like llama3.2-vision for the vision driver.
        $this->model = $forVision
            ? (config('llm.ollama.vision_model') ?? config('llm.ollama.model', 'llama3.2'))
            : config('llm.ollama.model', 'llama3.2');

        // Vision inference takes longer — use a separate timeout when available.
        $this->timeout = $forVision
            ? (int) config('llm.ollama.vision_timeout', config('llm.ollama.timeout', 360))
            : (int) config('llm.ollama.timeout', 240);

        $this->connectTimeout = (int) config('llm.ollama.connect_timeout', 10);
        // Vision uses OLLAMA_VISION_MAX_TOKENS when set; falls back to the text cap.
        $this->maxTokens = $forVision
            ? (int) (config('llm.ollama.vision_max_tokens') ?? config('llm.ollama.max_tokens', 2048))
            : (int) config('llm.ollama.max_tokens', 2048);
        $this->repeatPenalty = (float) config('llm.ollama.repeat_penalty', 1.1);
        $this->temperature = config('llm.ollama.temperature');
    }

    public function getModel(): string
    {
        return 'ollama:'.$this->model;
    }

    public function chat(array $messages): string
    {
        $response = Http::withOptions([
            'timeout' => $this->timeout,
            'connect_timeout' => $this->connectTimeout,
        ])
            ->post("{$this->host}/v1/chat/completions", [
                'model' => $this->model,
                'messages' => $messages,
                'max_tokens' => $this->maxTokens,
                'options' => array_filter([
                    'repeat_penalty' => $this->repeatPenalty,
                    'temperature' => $this->temperature,
                ], fn ($v) => $v !== null),
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException($this->errorMessage($response, 'Ollama API'));
        }

        return $response->json('choices.0.message.content', '');
    }

    /**
     * {@inheritdoc}
     *
     * Ollama's OpenAI-compatible endpoint emits SSE `data: {…}` frames when
     * `stream: true`; each carries a `choices[0].delta.content` fragment,
     * terminated by `data: [DONE]`. We read the raw PSR-7 body and yield the
     * fragments as they land.
     */
    public function chatStream(array $messages): \Generator
    {
        $response = Http::withOptions([
            'timeout' => $this->timeout,
            'connect_timeout' => $this->connectTimeout,
            'stream' => true,
        ])
            ->post("{$this->host}/v1/chat/completions", [
                'model' => $this->model,
                'messages' => $messages,
                'max_tokens' => $this->maxTokens,
                'stream' => true,
                'options' => array_filter([
                    'repeat_penalty' => $this->repeatPenalty,
                    'temperature' => $this->temperature,
                ], fn ($v) => $v !== null),
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException($this->errorMessage($response, 'Ollama API'));
        }

        $body = $response->toPsrResponse()->getBody();
        $buffer = '';

        while (! $body->eof()) {
            $buffer .= $body->read(8192);

            while (($nl = strpos($buffer, "\n")) !== false) {
                $line = trim(substr($buffer, 0, $nl));
                $buffer = substr($buffer, $nl + 1);

                if ($line === '' || ! str_starts_with($line, 'data:')) {
                    continue;
                }

                $data = trim(substr($line, 5));
                if ($data === '[DONE]') {
                    return;
                }

                $delta = json_decode($data, true)['choices'][0]['delta']['content'] ?? '';
                if ($delta !== '') {
                    yield $delta;
                }
            }
        }
    }

    /**
     * {@inheritdoc}
     *
     * Uses the OpenAI image_url content block format supported by Ollama
     * multimodal models (llama3.2-vision, llava, etc.).
     */
    public function chatWithVision(string $imageData, string $mimeType, array $messages): string
    {
        $dataUri = 'data:'.$mimeType.';base64,'.base64_encode($imageData);
        $imageBlock = [
            'type' => 'image_url',
            'image_url' => ['url' => $dataUri],
        ];

        $converted = [];
        foreach ($messages as $msg) {
            $converted[] = $msg;
        }

        if (! empty($converted)) {
            $last = array_pop($converted);
            $last['content'] = [
                $imageBlock,
                ['type' => 'text', 'text' => $last['content']],
            ];
            $converted[] = $last;
        } else {
            $converted[] = [
                'role' => 'user',
                'content' => [$imageBlock],
            ];
        }

        $response = Http::withOptions([
            'timeout' => $this->timeout,
            'connect_timeout' => $this->connectTimeout,
        ])
            ->post("{$this->host}/v1/chat/completions", [
                'model' => $this->model,
                'messages' => $converted,
                'max_tokens' => $this->maxTokens,
                'options' => array_filter([
                    'repeat_penalty' => $this->repeatPenalty,
                    'temperature' => $this->temperature,
                ], fn ($v) => $v !== null),
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException($this->errorMessage($response, 'Ollama vision API'));
        }

        return $response->json('choices.0.message.content', '');
    }

    // =========================================================================
    // INTERNAL
    // =========================================================================

    /**
     * Extract a human-readable message from an Ollama error response.
     * Ollama's OpenAI-compatible endpoint returns {"error":{"message":"..."}} on failure.
     */
    private function errorMessage(Response $response, string $prefix): string
    {
        $msg = $response->json('error.message') ?? $response->json('error') ?? $response->body();

        return "{$prefix} failed (HTTP {$response->status()}): {$msg}";
    }
}
