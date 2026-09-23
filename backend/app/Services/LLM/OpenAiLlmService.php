<?php

namespace App\Services\LLM;

use App\Services\LLM\Contracts\LlmServiceInterface;
use Illuminate\Support\Facades\Http;

class OpenAiLlmService implements LlmServiceInterface
{
    private const BASE_URL = 'https://api.openai.com';

    private string $baseUrl;

    private string $apiKey;

    private string $model;

    private int $timeout;

    private int $maxTokens;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('llm.openai.base_url', self::BASE_URL), '/');
        $this->apiKey = config('llm.openai.api_key', '');
        $this->model = config('llm.openai.model', 'gpt-4o');
        $this->timeout = config('llm.openai.timeout', 60);
        $this->maxTokens = config('llm.openai.max_tokens', 1024);
    }

    public function getModel(): string
    {
        return 'openai:'.$this->model;
    }

    public function chat(array $messages): string
    {
        $response = Http::withOptions(['timeout' => $this->timeout])
            ->withToken($this->apiKey)
            ->post("{$this->baseUrl}/v1/chat/completions", [
                'model' => $this->model,
                'messages' => $messages,
                'max_tokens' => $this->maxTokens,
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException(
                "OpenAI API failed (HTTP {$response->status()}): ".$response->body()
            );
        }

        return $response->json('choices.0.message.content', '');
    }

    /**
     * {@inheritdoc}
     *
     * Encodes the image as a base64 data URI and injects it as an image_url
     * content block in the last user message, following the OpenAI vision format.
     */
    public function chatWithVision(string $imageData, string $mimeType, array $messages): string
    {
        $dataUri = 'data:'.$mimeType.';base64,'.base64_encode($imageData);
        $imageBlock = [
            'type' => 'image_url',
            'image_url' => ['url' => $dataUri],
        ];

        // Convert all messages to the multi-content format, injecting the image
        // into the last user message (or creating one if there are none).
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

        $response = Http::withOptions(['timeout' => $this->timeout])
            ->withToken($this->apiKey)
            ->post("{$this->baseUrl}/v1/chat/completions", [
                'model' => $this->model,
                'messages' => $converted,
                'max_tokens' => $this->maxTokens,
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException(
                "OpenAI vision API failed (HTTP {$response->status()}): ".$response->body()
            );
        }

        return $response->json('choices.0.message.content', '');
    }
}
