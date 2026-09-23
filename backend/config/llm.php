<?php

return [
    // Driver used for chat (RAG Q&A, text auto-tagging)
    // claude | anthropicproxy | gemini | zai | openai | ollama
    //
    // 'claude' and 'anthropicproxy' both resolve to ClaudeLlmService (same
    // ANTHROPIC_* config below, same request shape) — 'anthropicproxy' exists
    // purely so an Anthropic-compatible proxy (e.g. Z.ai's /api/anthropic,
    // which silently answers with its own model regardless of the `model`
    // field sent) is visible in this one value, not something you can only
    // discover by also reading ANTHROPIC_BASE_URL. 'zai' is Z.ai's *native*
    // API (ZaiLlmService, OpenAI-shaped /chat/completions) — a different
    // driver, not just a different URL; the two are not interchangeable by
    // swapping ANTHROPIC_BASE_URL alone (wrong path shape, 404s).
    'text_driver' => env('LLM_TEXT_DRIVER', 'claude'),

    // Driver used for vision (image analysis). Defaults to LLM_TEXT_DRIVER when not set.
    // Useful when you run a local model for chat but need a cloud model for vision.
    'vision_driver' => env('LLM_VISION_DRIVER') ?: null,

    'claude' => [
        // Shared by both 'claude' and 'anthropicproxy' — base URL decides
        // which one you're actually talking to; override for a proxy like
        // Z.ai's Anthropic-compatible endpoint (ANTHROPIC_BASE_URL)
        'base_url' => rtrim(env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com'), '/'),
        // Auth token — ANTHROPIC_AUTH_TOKEN takes precedence over ANTHROPIC_API_KEY
        'api_key' => env('ANTHROPIC_AUTH_TOKEN') ?? env('ANTHROPIC_API_KEY', ''),
        'model' => env('CLAUDE_MODEL', 'claude-sonnet-4-6'),
        'timeout' => (int) env('CLAUDE_TIMEOUT', 60),
        'max_tokens' => (int) env('CLAUDE_MAX_TOKENS', 1024),
    ],

    'gemini' => [
        'api_key' => env('GEMINI_API_KEY', ''),
        'model' => env('GEMINI_MODEL', 'gemini-2.0-flash'),
        'timeout' => (int) env('GEMINI_TIMEOUT', 30),
        'max_tokens' => (int) env('GEMINI_MAX_TOKENS', 1024),
    ],

    'zai' => [
        'base_url' => env('ZAI_BASE_URL'),
        'api_key' => env('ZAI_API_KEY'),
        'model' => env('ZAI_MODEL'),
        'timeout' => (int) env('ZAI_TIMEOUT', 60),
        'max_tokens' => (int) env('ZAI_MAX_TOKENS', 1024),
    ],

    // Ollama — local LLM on the same host as the embedding service.
    // OLLAMA_HOST is shared with config/embedding.php (same Ollama instance).
    // Use a separate OLLAMA_LLM_MODEL for chat (different from the embed model).
    'ollama' => [
        'host' => env('OLLAMA_HOST', 'http://localhost:11434'),
        'model' => env('OLLAMA_LLM_MODEL', 'llama3.2'),
        // vision_model falls back to model when not set, but should point to a
        // multimodal model (e.g. llama3.2-vision) since text models can't process images.
        'vision_model' => env('OLLAMA_VISION_MODEL') ?: null,
        // text chat timeout (RAG, autotag)
        'timeout' => (int) env('OLLAMA_TIMEOUT', 240),
        // vision timeout — separate because multimodal inference takes longer than text
        'vision_timeout' => (int) env('OLLAMA_VISION_TIMEOUT', 360),
        // connect_timeout — fail fast if Ollama is unreachable (default 0 = no limit)
        'connect_timeout' => (int) env('OLLAMA_CONNECT_TIMEOUT', 10),
        'max_tokens' => (int) env('OLLAMA_LLM_MAX_TOKENS', 2048),
        // vision_max_tokens — separate cap for image analysis; falls back to max_tokens when unset.
        'vision_max_tokens' => env('OLLAMA_VISION_MAX_TOKENS') !== null ? (int) env('OLLAMA_VISION_MAX_TOKENS') : null,
        // repeat_penalty > 1.0 suppresses token repetition loops.
        // Default 1.1 is a safe starting point; raise to 1.3 if loops persist.
        'repeat_penalty' => (float) env('OLLAMA_REPEAT_PENALTY', 1.1),
        // temperature: null = use model default. Lower values (0.1–0.3) produce
        // more consistent JSON output; useful for structured-output tasks.
        'temperature' => env('OLLAMA_TEMPERATURE') !== null ? (float) env('OLLAMA_TEMPERATURE') : null,
    ],

    'openai' => [
        // Override base_url to point at any OpenAI-compatible proxy (e.g. Azure, LiteLLM)
        'base_url' => rtrim(env('OPENAI_BASE_URL', 'https://api.openai.com'), '/'),
        'api_key' => env('OPENAI_API_KEY', ''),
        'model' => env('OPENAI_MODEL', 'gpt-4o'),
        'timeout' => (int) env('OPENAI_TIMEOUT', 60),
        'max_tokens' => (int) env('OPENAI_MAX_TOKENS', 1024),
    ],

    // Max total characters of context injected into the prompt.
    // 12 000 was fine for small collections; with 20+ resources meta chunks alone can exceed
    // it. Claude Sonnet handles 200 k tokens comfortably — 40 000 chars ≈ 10 000 tokens.
    'max_context_chars' => (int) env('LLM_MAX_CONTEXT_CHARS', 40000),

    // Per-resource meta chunk character cap.
    // Prevents a single verbose description / Tika dump from consuming the whole budget
    // and starving other resources. Truncated text ends with "…".
    'meta_chunk_max_chars' => (int) env('LLM_META_CHUNK_MAX_CHARS', 600),
];
