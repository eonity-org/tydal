<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Auto-tagging
    |--------------------------------------------------------------------------
    |
    | When enabled, the AutoTagResource job is dispatched after every successful
    | text extraction. It sends a truncated version of the extracted content to
    | the configured LLM driver and attaches the returned labels as SemanticTags.
    |
    | The LLM driver is shared with Phase 4 (RAG) — configure it via LLM_TEXT_DRIVER.
    |
    */

    'enabled' => env('AUTOTAGGING_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Vision AI (image analysis)
    |--------------------------------------------------------------------------
    |
    | When enabled, the AnalyzeImageContent job is dispatched after every image
    | upload. It sends the image to the configured LLM vision API and returns
    | a suggested name, description, and tags — separate from EXIF/Tika metadata.
    |
    | Requires a vision-capable LLM driver (claude, openai, gemini, zai, ollama).
    | Set to false to keep text autotagging on while suppressing vision API costs.
    |
    */
    'vision_enabled' => env('AI_VISION_ENABLED', false),

    /*
    | Maximum characters of extracted text to send to the LLM.
    | 6 000 chars ≈ ~1 500 tokens — a comfortable budget for most models.
    */
    'max_chars' => (int) env('AUTOTAGGING_MAX_CHARS', 6000),

    /*
    | Maximum number of tags the LLM is asked to generate per resource.
    */
    'max_tags' => (int) env('AUTOTAGGING_MAX_TAGS', 10),

    /*
    |--------------------------------------------------------------------------
    | AiTy RAG strict mode (global default)
    |--------------------------------------------------------------------------
    |
    | When true (strict), the RAG metadata chunk for a resource only contains
    | human-confirmed data: the resource's saved name, description, and applied
    | semantic tags. AI suggestions sitting in the review queue are ignored.
    |
    | When false (non-strict), unconfirmed AI suggestions are folded into the
    | metadata chunk immediately after AutoTagResource runs. Confirmed fields
    | always take precedence; suggestions only fill gaps (name, description) or
    | augment (tags union). This makes resources semantically searchable via RAG
    | before any human review, at the cost of occasional hallucinated metadata.
    |
    | Per-organization override: organizations.settings.aity.rag_strict_mode
    | Platform-wide override:    AITY_RAG_STRICT_MODE env variable
    |
    */
    'rag_strict_mode' => env('AITY_RAG_STRICT_MODE', true),

    /*
    |--------------------------------------------------------------------------
    | AI Activity Debug Log
    |--------------------------------------------------------------------------
    |
    | When enabled, every AI pipeline operation (Tika extraction, metadata
    | promotion, LLM calls, RAG context assembly) is written as a JSON line
    | to storage/logs/ai-activity.log.
    |
    | Inspect: tail -f storage/logs/ai-activity.log | jq .
    |
    | Keep disabled in production — this logs full prompts and metadata values.
    |
    */
    'debug' => env('AI_DEBUG', false),

];
