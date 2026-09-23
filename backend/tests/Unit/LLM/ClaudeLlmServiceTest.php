<?php

namespace Tests\Unit\LLM;

use App\Services\LLM\ClaudeLlmService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Tests for ClaudeLlmService.
 * All HTTP calls are intercepted with Http::fake() — no real Anthropic API required.
 */
class ClaudeLlmServiceTest extends TestCase
{
    private function makeService(): ClaudeLlmService
    {
        config(['llm.claude.base_url' => 'https://api.anthropic.com']);
        config(['llm.claude.api_key' => 'test-anthropic-key']);
        config(['llm.claude.model' => 'claude-sonnet-4-6']);
        config(['llm.claude.timeout' => 30]);
        config(['llm.claude.max_tokens' => 512]);

        return new ClaudeLlmService;
    }

    private function claudeResponse(string $text): string
    {
        return json_encode(['content' => [['type' => 'text', 'text' => $text]]]);
    }

    // =========================================================================
    // Request format
    // =========================================================================

    public function test_chat_posts_to_anthropic_messages_endpoint(): void
    {
        Http::fake(['*/v1/messages' => Http::response($this->claudeResponse('Hello!'), 200)]);

        $this->makeService()->chat([['role' => 'user', 'content' => 'Hi']]);

        Http::assertSent(fn ($req) => str_ends_with($req->url(), '/v1/messages'));
    }

    public function test_chat_sends_api_key_and_version_headers(): void
    {
        Http::fake(['*/v1/messages' => Http::response($this->claudeResponse('Hi'), 200)]);

        $this->makeService()->chat([['role' => 'user', 'content' => 'Hi']]);

        Http::assertSent(function ($req) {
            return $req->header('x-api-key')[0] === 'test-anthropic-key'
                && $req->header('anthropic-version')[0] === '2023-06-01';
        });
    }

    public function test_system_role_is_extracted_to_top_level_system_key(): void
    {
        Http::fake(['*/v1/messages' => Http::response($this->claudeResponse('Answer'), 200)]);

        $this->makeService()->chat([
            ['role' => 'system', 'content' => 'You are a helpful assistant.'],
            ['role' => 'user',   'content' => 'What is 2+2?'],
        ]);

        Http::assertSent(function ($req) {
            $body = $req->data();
            // system extracted to top-level key
            $hasSystemKey = isset($body['system']) && $body['system'] === 'You are a helpful assistant.';
            // messages array must NOT contain the system role
            $noSystemInMessages = collect($body['messages'])->every(fn ($m) => $m['role'] !== 'system');

            return $hasSystemKey && $noSystemInMessages;
        });
    }

    public function test_chat_sends_correct_model_and_max_tokens(): void
    {
        Http::fake(['*/v1/messages' => Http::response($this->claudeResponse('Hi'), 200)]);

        $this->makeService()->chat([['role' => 'user', 'content' => 'Hi']]);

        Http::assertSent(function ($req) {
            $body = $req->data();

            return $body['model'] === 'claude-sonnet-4-6'
                && $body['max_tokens'] === 512;
        });
    }

    // =========================================================================
    // Response parsing
    // =========================================================================

    public function test_chat_returns_text_from_response(): void
    {
        Http::fake(['*/v1/messages' => Http::response(
            $this->claudeResponse('The answer is 42.'),
            200
        )]);

        $result = $this->makeService()->chat([['role' => 'user', 'content' => 'question']]);

        $this->assertSame('The answer is 42.', $result);
    }

    // =========================================================================
    // Error handling
    // =========================================================================

    public function test_chat_throws_on_api_error(): void
    {
        Http::fake(['*/v1/messages' => Http::response('{"error":"invalid_api_key"}', 401)]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Claude API failed \(HTTP 401\)/');

        $this->makeService()->chat([['role' => 'user', 'content' => 'Hi']]);
    }

    public function test_proxy_mode_uses_bearer_token_and_custom_base_url(): void
    {
        Http::fake(['https://api.z.ai/*' => Http::response($this->claudeResponse('proxy answer'), 200)]);

        config([
            'llm.claude.base_url' => 'https://api.z.ai/api/anthropic',
            'llm.claude.api_key' => 'my-zai-token',
        ]);

        $service = new ClaudeLlmService;
        $result = $service->chat([['role' => 'user', 'content' => 'Hi']]);

        $this->assertSame('proxy answer', $result);

        Http::assertSent(function ($req) {
            $body = $req->data();

            return str_contains($req->url(), 'api.z.ai')
                && $req->header('Authorization')[0] === 'Bearer my-zai-token'
                && ! $req->hasHeader('x-api-key')
                && ($body['thinking']['type'] ?? null) === 'disabled';
        });
    }
}
