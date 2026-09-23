<?php

namespace App\Services\LLM;

use App\Services\LLM\Contracts\LlmServiceInterface;
use Illuminate\Support\Facades\Http;

class GeminiLlmService implements LlmServiceInterface
{
    private const BASE_URL = 'https://generativelanguage.googleapis.com/v1beta/models';

    private string $apiKey;

    private string $model;

    private int $timeout;

    private int $maxTokens;

    public function __construct()
    {
        $this->apiKey = config('llm.gemini.api_key', '');
        $this->model = config('llm.gemini.model', 'gemini-2.0-flash');
        $this->timeout = config('llm.gemini.timeout', 30);
        $this->maxTokens = config('llm.gemini.max_tokens', 1024);
    }

    public function getModel(): string
    {
        return 'gemini:'.$this->model;
    }

    public function chat(array $messages): string
    {
        $url = self::BASE_URL."/{$this->model}:generateContent?key={$this->apiKey}";

        // Extract system message for systemInstruction (v1beta feature)
        $systemInstruction = null;
        $contents = [];

        foreach ($messages as $msg) {
            if ($msg['role'] === 'system') {
                $systemInstruction = ['parts' => [['text' => $msg['content']]]];
            } else {
                // Gemini uses 'model' instead of 'assistant'
                $contents[] = [
                    'role' => $msg['role'] === 'assistant' ? 'model' : 'user',
                    'parts' => [['text' => $msg['content']]],
                ];
            }
        }

        $body = [
            'contents' => $contents,
            'generationConfig' => ['maxOutputTokens' => $this->maxTokens],
        ];

        if ($systemInstruction !== null) {
            $body['systemInstruction'] = $systemInstruction;
        }

        $response = Http::withOptions(['timeout' => $this->timeout])
            ->post($url, $body);

        if (! $response->successful()) {
            throw new \RuntimeException(
                "Gemini API failed (HTTP {$response->status()}): ".$response->body()
            );
        }

        return $response->json('candidates.0.content.parts.0.text', '');
    }

    /**
     * {@inheritdoc}
     *
     * Gemini vision uses inline_data parts (base64 bytes + mimeType) rather than
     * the OpenAI image_url format. The image is injected into the last user turn.
     */
    public function chatWithVision(string $imageData, string $mimeType, array $messages): string
    {
        $url = self::BASE_URL."/{$this->model}:generateContent?key={$this->apiKey}";

        $systemInstruction = null;
        $contents = [];

        foreach ($messages as $msg) {
            if ($msg['role'] === 'system') {
                $systemInstruction = ['parts' => [['text' => $msg['content']]]];
            } else {
                $contents[] = [
                    'role' => $msg['role'] === 'assistant' ? 'model' : 'user',
                    'parts' => [['text' => $msg['content']]],
                ];
            }
        }

        // Inject the image into the last user turn, or create one if there are none.
        $imageBlock = ['inline_data' => ['mime_type' => $mimeType, 'data' => base64_encode($imageData)]];

        if (! empty($contents)) {
            $last = array_pop($contents);
            $last['parts'] = array_merge([$imageBlock], $last['parts']);
            $contents[] = $last;
        } else {
            $contents[] = ['role' => 'user', 'parts' => [$imageBlock]];
        }

        $body = [
            'contents' => $contents,
            'generationConfig' => ['maxOutputTokens' => $this->maxTokens],
        ];

        if ($systemInstruction !== null) {
            $body['systemInstruction'] = $systemInstruction;
        }

        $response = Http::withOptions(['timeout' => $this->timeout])
            ->post($url, $body);

        if (! $response->successful()) {
            throw new \RuntimeException(
                "Gemini vision API failed (HTTP {$response->status()}): ".$response->body()
            );
        }

        return $response->json('candidates.0.content.parts.0.text', '');
    }
}
