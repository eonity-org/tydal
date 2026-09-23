<?php

namespace Tests\Unit\LLM;

use App\Services\LLM\GeminiLlmService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Tests for GeminiLlmService.
 * All HTTP calls are intercepted with Http::fake() — no real Gemini API required.
 */
class GeminiLlmServiceTest extends TestCase
{
    private function makeService(): GeminiLlmService
    {
        config(['llm.gemini.api_key' => 'test-gemini-key']);
        config(['llm.gemini.model' => 'gemini-2.0-flash']);
        config(['llm.gemini.timeout' => 30]);
        config(['llm.gemini.max_tokens' => 512]);

        return new GeminiLlmService;
    }

    private function geminiResponse(string $text): string
    {
        return json_encode([
            'candidates' => [[
                'content' => ['parts' => [['text' => $text]]],
            ]],
        ]);
    }

    // =========================================================================
    // Request format
    // =========================================================================

    public function test_chat_posts_to_gemini_generatecontent_endpoint(): void
    {
        Http::fake(['*generateContent*' => Http::response($this->geminiResponse('Hi'), 200)]);

        $this->makeService()->chat([['role' => 'user', 'content' => 'Hi']]);

        Http::assertSent(fn ($req) => str_contains($req->url(), 'generateContent'));
    }

    public function test_chat_includes_api_key_in_query_string(): void
    {
        Http::fake(['*generateContent*' => Http::response($this->geminiResponse('Hi'), 200)]);

        $this->makeService()->chat([['role' => 'user', 'content' => 'Hi']]);

        Http::assertSent(fn ($req) => str_contains($req->url(), 'key=test-gemini-key'));
    }

    public function test_system_message_maps_to_system_instruction(): void
    {
        Http::fake(['*generateContent*' => Http::response($this->geminiResponse('Answer'), 200)]);

        $this->makeService()->chat([
            ['role' => 'system', 'content' => 'You are a document assistant.'],
            ['role' => 'user',   'content' => 'What is this about?'],
        ]);

        Http::assertSent(function ($req) {
            $body = $req->data();
            $hasInstruction = isset($body['systemInstruction']['parts'][0]['text'])
                && $body['systemInstruction']['parts'][0]['text'] === 'You are a document assistant.';
            // system message must not appear in contents
            $noSystemInContents = collect($body['contents'])->every(fn ($c) => $c['role'] !== 'system');

            return $hasInstruction && $noSystemInContents;
        });
    }

    public function test_assistant_role_maps_to_model_role(): void
    {
        Http::fake(['*generateContent*' => Http::response($this->geminiResponse('Answer'), 200)]);

        $this->makeService()->chat([
            ['role' => 'user',      'content' => 'Question'],
            ['role' => 'assistant', 'content' => 'Previous answer'],
            ['role' => 'user',      'content' => 'Follow up'],
        ]);

        Http::assertSent(function ($req) {
            $contents = $req->data()['contents'];

            return $contents[1]['role'] === 'model';
        });
    }

    // =========================================================================
    // Response parsing
    // =========================================================================

    public function test_chat_returns_text_from_response(): void
    {
        Http::fake(['*generateContent*' => Http::response(
            $this->geminiResponse('Gemini says hello.'),
            200
        )]);

        $result = $this->makeService()->chat([['role' => 'user', 'content' => 'Hi']]);

        $this->assertSame('Gemini says hello.', $result);
    }

    // =========================================================================
    // Error handling
    // =========================================================================

    public function test_chat_throws_on_api_error(): void
    {
        Http::fake(['*generateContent*' => Http::response('{"error":"API_KEY_INVALID"}', 400)]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Gemini API failed \(HTTP 400\)/');

        $this->makeService()->chat([['role' => 'user', 'content' => 'Hi']]);
    }
}
