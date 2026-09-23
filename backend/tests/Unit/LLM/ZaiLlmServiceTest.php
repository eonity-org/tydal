<?php

namespace Tests\Unit\LLM;

use App\Services\LLM\ZaiLlmService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Tests for ZaiLlmService (generic OpenAI-compatible driver).
 * All HTTP calls are intercepted with Http::fake() — no real API required.
 */
class ZaiLlmServiceTest extends TestCase
{
    private function makeService(array $overrides = []): ZaiLlmService
    {
        config(['llm.zai.base_url' => $overrides['base_url'] ?? 'https://openrouter.ai/api/v1']);
        config(['llm.zai.api_key' => $overrides['api_key'] ?? 'test-zai-key']);
        config(['llm.zai.model' => $overrides['model'] ?? 'mistral-7b']);
        config(['llm.zai.timeout' => $overrides['timeout'] ?? 30]);
        config(['llm.zai.max_tokens' => $overrides['max_tokens'] ?? 512]);

        return new ZaiLlmService;
    }

    private function openAiResponse(string $text): string
    {
        return json_encode([
            'choices' => [['message' => ['role' => 'assistant', 'content' => $text]]],
        ]);
    }

    // =========================================================================
    // Request format
    // =========================================================================

    public function test_chat_posts_to_chat_completions_endpoint(): void
    {
        Http::fake(['*/chat/completions' => Http::response($this->openAiResponse('Hi'), 200)]);

        $this->makeService()->chat([['role' => 'user', 'content' => 'Hi']]);

        Http::assertSent(fn ($req) => str_ends_with($req->url(), '/chat/completions'));
    }

    public function test_chat_sends_bearer_token_when_api_key_set(): void
    {
        Http::fake(['*/chat/completions' => Http::response($this->openAiResponse('Hi'), 200)]);

        $this->makeService()->chat([['role' => 'user', 'content' => 'Hi']]);

        Http::assertSent(fn ($req) => $req->header('Authorization')[0] === 'Bearer test-zai-key');
    }

    public function test_chat_omits_authorization_header_when_no_api_key(): void
    {
        Http::fake(['*/chat/completions' => Http::response($this->openAiResponse('Hi'), 200)]);

        $this->makeService(['api_key' => ''])->chat([['role' => 'user', 'content' => 'Hi']]);

        Http::assertSent(fn ($req) => empty($req->header('Authorization')));
    }

    public function test_chat_sends_model_and_messages(): void
    {
        Http::fake(['*/chat/completions' => Http::response($this->openAiResponse('Hi'), 200)]);

        $messages = [['role' => 'user', 'content' => 'Tell me about foxes']];
        $this->makeService()->chat($messages);

        Http::assertSent(function ($req) use ($messages) {
            $body = $req->data();

            return $body['model'] === 'mistral-7b'
                && $body['messages'] === $messages;
        });
    }

    public function test_chat_uses_configured_base_url(): void
    {
        Http::fake(['*together*' => Http::response($this->openAiResponse('Hi'), 200)]);

        $this->makeService(['base_url' => 'https://api.together.ai/v1'])
            ->chat([['role' => 'user', 'content' => 'Hi']]);

        Http::assertSent(fn ($req) => str_contains($req->url(), 'together.ai'));
    }

    // =========================================================================
    // Response parsing
    // =========================================================================

    public function test_chat_returns_text_from_response(): void
    {
        Http::fake(['*/chat/completions' => Http::response(
            $this->openAiResponse('Zai says hello.'),
            200
        )]);

        $result = $this->makeService()->chat([['role' => 'user', 'content' => 'Hi']]);

        $this->assertSame('Zai says hello.', $result);
    }

    // =========================================================================
    // Error handling
    // =========================================================================

    public function test_chat_throws_on_api_error(): void
    {
        Http::fake(['*/chat/completions' => Http::response('{"error":"unauthorized"}', 401)]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Zai API failed \(HTTP 401\)/');

        $this->makeService()->chat([['role' => 'user', 'content' => 'Hi']]);
    }

    public function test_constructor_throws_when_base_url_not_configured(): void
    {
        config(['llm.zai.base_url' => '']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('ZAI_BASE_URL is not configured.');

        new ZaiLlmService;
    }
}
