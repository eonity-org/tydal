<?php

namespace App\Services\LLM;

use App\Services\LLM\Contracts\LlmServiceInterface;
use Illuminate\Support\Facades\Http;

class ClaudeLlmService implements LlmServiceInterface
{
    private const API_VERSION = '2023-06-01';

    private const NATIVE_HOST = 'api.anthropic.com';

    private string $baseUrl;

    private string $apiKey;

    private string $model;

    private int $timeout;

    private int $maxTokens;

    public function __construct()
    {
        $this->baseUrl = config('llm.claude.base_url', 'https://api.anthropic.com');
        $this->apiKey = config('llm.claude.api_key', '');
        $this->model = config('llm.claude.model', 'claude-sonnet-4-6');
        $this->timeout = config('llm.claude.timeout', 60);
        $this->maxTokens = config('llm.claude.max_tokens', 1024);
    }

    public function getModel(): string
    {
        return 'claude:'.$this->model;
    }

    public function chat(array $messages): string
    {
        // Anthropic requires system prompt as a top-level key, not a message role
        $system = '';
        $filtered = [];

        foreach ($messages as $msg) {
            if ($msg['role'] === 'system') {
                $system = $msg['content'];
            } else {
                $filtered[] = $msg;
            }
        }

        // Z.ai's Anthropic-compatible endpoint enables a reasoning block by
        // default. With a bounded response, it can consume every output token
        // before emitting a text block, leaving AITY with an empty response.
        $isProxy = ! str_contains($this->baseUrl, self::NATIVE_HOST);

        $body = [
            'model' => $this->model,
            'max_tokens' => $this->maxTokens,
            'messages' => $filtered,
        ];

        if ($system !== '') {
            $body['system'] = $system;
        }

        if ($isProxy) {
            $body['thinking'] = ['type' => 'disabled'];
        }

        // Native Anthropic API uses x-api-key; proxies (z.ai) expect Authorization: Bearer
        $authHeaders = $isProxy
            ? ['Authorization' => "Bearer {$this->apiKey}"]
            : ['x-api-key' => $this->apiKey, 'anthropic-version' => self::API_VERSION];

        $url = $this->baseUrl.'/v1/messages';
        $response = Http::withOptions(['timeout' => $this->timeout])
            ->withHeaders($authHeaders)
            ->post($url, $body);

        if (! $response->successful()) {
            throw new \RuntimeException(
                "Claude API failed (HTTP {$response->status()}): ".$response->body()
            );
        }

        return $response->json('content.0.text', '');
    }

    /**
     * {@inheritdoc}
     *
     * Encodes the image as base64 and injects it as the first content block
     * of the last user message, following the Anthropic vision API format.
     */
    public function chatWithVision(string $imageData, string $mimeType, array $messages): string
    {
        $system = '';
        $filtered = [];

        foreach ($messages as $msg) {
            if ($msg['role'] === 'system') {
                $system = $msg['content'];
            } else {
                $filtered[] = $msg;
            }
        }

        // Inject the image as a base64 content block into the last user message.
        // If there are no user messages, create one with just the image.
        $imageBlock = [
            'type' => 'image',
            'source' => [
                'type' => 'base64',
                'media_type' => $mimeType,
                'data' => base64_encode($imageData),
            ],
        ];

        if (! empty($filtered)) {
            $last = array_pop($filtered);
            $last['content'] = [
                $imageBlock,
                ['type' => 'text', 'text' => $last['content']],
            ];
            $filtered[] = $last;
        } else {
            $filtered[] = [
                'role' => 'user',
                'content' => [$imageBlock],
            ];
        }

        $isProxy = ! str_contains($this->baseUrl, self::NATIVE_HOST);

        $body = [
            'model' => $this->model,
            'max_tokens' => $this->maxTokens,
            'messages' => $filtered,
        ];

        if ($system !== '') {
            $body['system'] = $system;
        }

        if ($isProxy) {
            $body['thinking'] = ['type' => 'disabled'];
        }

        $authHeaders = $isProxy
            ? ['Authorization' => "Bearer {$this->apiKey}"]
            : ['x-api-key' => $this->apiKey, 'anthropic-version' => self::API_VERSION];

        $url = $this->baseUrl.'/v1/messages';
        $response = Http::withOptions(['timeout' => $this->timeout])
            ->withHeaders($authHeaders)
            ->post($url, $body);

        if (! $response->successful()) {
            throw new \RuntimeException(
                "Claude vision API failed (HTTP {$response->status()}): ".$response->body()
            );
        }

        return $response->json('content.0.text', '');
    }
}
