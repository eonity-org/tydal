<?php

namespace App\Services\LLM;

use App\Services\LLM\Contracts\LlmServiceInterface;
use Illuminate\Support\Facades\Http;

/**
 * Zai AI driver (OpenAI-compatible chat completions).
 *
 * Also works with any other OpenAI-compatible endpoint:
 * OpenRouter, Together AI, local vLLM, etc.
 *
 * For local Ollama, use OllamaLlmService (LLM_TEXT_DRIVER=ollama) instead,
 * which reuses OLLAMA_HOST and is configured independently.
 */
class ZaiLlmService implements LlmServiceInterface
{
    private string $baseUrl;

    private ?string $apiKey;

    private ?string $model;

    private int $timeout;

    private int $maxTokens;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('llm.zai.base_url', ''), '/');
        $this->apiKey = config('llm.zai.api_key') ?: null;
        $this->model = config('llm.zai.model', '');
        $this->timeout = config('llm.zai.timeout', 60);
        $this->maxTokens = config('llm.zai.max_tokens', 1024);

        if (empty($this->baseUrl)) {
            throw new \RuntimeException('ZAI_BASE_URL is not configured.');
        }
    }

    public function getModel(): string
    {
        return 'zai:'.($this->model ?: 'unknown');
    }

    public function chat(array $messages): string
    {
        $request = Http::withOptions(['timeout' => $this->timeout])
            ->contentType('application/json');

        if ($this->apiKey !== null) {
            $request = $request->withToken($this->apiKey);
        }

        $body = [
            'model' => $this->model,
            'messages' => $messages,
            'max_tokens' => $this->maxTokens,
            // GLM's reasoning tokens count against max_tokens and aren't part of
            // the JSON answer we parse — left enabled, a long chain-of-thought can
            // consume the whole budget and truncate the JSON mid-object before it
            // ever closes (finish_reason "length"), which we can't tell apart from
            // a genuinely empty response. Disabling it is strictly better for our
            // structured-JSON use cases (autotag, RAG): same or better answers,
            // fewer tokens, no truncation risk.
            'thinking' => ['type' => 'disabled'],
        ];

        $response = $request->post("{$this->baseUrl}/chat/completions", $body);

        if (! $response->successful()) {
            throw new \RuntimeException(
                "Zai API failed (HTTP {$response->status()}): ".$response->body()
            );
        }

        return $response->json('choices.0.message.content', '');
    }

    /**
     * {@inheritdoc}
     *
     * Uses the OpenAI-compatible vision format (data URI in image_url content block),
     * supported by any OpenAI-compatible endpoint that accepts multimodal input.
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

        $request = Http::withOptions(['timeout' => $this->timeout])
            ->contentType('application/json');

        if ($this->apiKey !== null) {
            $request = $request->withToken($this->apiKey);
        }

        $response = $request->post("{$this->baseUrl}/chat/completions", [
            'model' => $this->model,
            'messages' => $converted,
            'max_tokens' => $this->maxTokens,
            'thinking' => ['type' => 'disabled'],
        ]);

        if (! $response->successful()) {
            throw new \RuntimeException(
                "Zai vision API failed (HTTP {$response->status()}): ".$response->body()
            );
        }

        return $response->json('choices.0.message.content', '');
    }
}
