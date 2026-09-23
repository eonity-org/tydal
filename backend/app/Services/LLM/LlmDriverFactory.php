<?php

namespace App\Services\LLM;

use App\Services\LLM\Contracts\LlmServiceInterface;

/**
 * Resolves an LlmServiceInterface implementation by driver name.
 *
 * Centralises the driver → service mapping so that both AppServiceProvider
 * and AiTestController can resolve drivers without duplicating the match.
 */
class LlmDriverFactory
{
    public static function make(string $driver): LlmServiceInterface
    {
        return match ($driver) {
            'gemini' => new GeminiLlmService,
            'zai' => new ZaiLlmService,
            // Same class as 'claude' (ANTHROPIC_* config, auto-detects
            // proxy-vs-native from ANTHROPIC_BASE_URL) — a distinct name so
            // "Anthropic-shaped request routed through Z.ai" is visible in
            // LLM_TEXT_DRIVER itself, not just discoverable by also reading
            // ANTHROPIC_BASE_URL. Genuine Anthropic still uses 'claude'.
            'anthropicproxy' => new ClaudeLlmService,
            'openai' => new OpenAiLlmService,
            'ollama' => new OllamaLlmService,
            default => new ClaudeLlmService,
        };
    }

    public static function chatDriver(): LlmServiceInterface
    {
        return static::make(config('llm.text_driver', 'claude'));
    }

    public static function visionDriver(): LlmServiceInterface
    {
        $driver = config('llm.vision_driver') ?: config('llm.text_driver', 'claude');

        // Pass forVision=true so OllamaLlmService picks OLLAMA_VISION_MODEL
        // instead of OLLAMA_LLM_MODEL when both drivers resolve to ollama.
        return $driver === 'ollama'
            ? new OllamaLlmService(forVision: true)
            : static::make($driver);
    }
}
