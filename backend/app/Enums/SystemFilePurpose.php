<?php

namespace App\Enums;

enum SystemFilePurpose: string
{
    case EXTRACTED_TEXT = 'extracted_text';   // Phase 1 — Tika document output
    case TRANSCRIPTION = 'transcription';    // Phase 1 — Whisper audio output (deferred)
    case TIKA_METADATA = 'tika_metadata';    // Phase 1 — Tika metadata for images/audio/video

    case AI_SUGGESTED_TAGS = 'ai_suggested_tags';        // Phase 2 — LLM-suggested semantic tags awaiting review
    case AI_SUGGESTED_NAME = 'ai_suggested_name';        // Phase 2 — LLM-suggested resource name awaiting review
    case AI_SUGGESTED_DESCRIPTION = 'ai_suggested_description'; // Phase 2 — LLM-suggested description awaiting review
    case AI_SUGGESTED_METADATA = 'ai_suggested_metadata';       // Epic 3.3 seed — LLM-suggested scheme-field values ({field: value}) awaiting review

    // Resource-scoped AITY synthesis output (no source_file_id). Written when the LLM
    // generates a new value that does not exist verbatim in any single file's suggestion
    // (multi-component synthesis) or rewrites a tag label during workspace-wide dedup.
    case AI_GENERATED_TAGS = 'ai_generated_tags';
    case AI_GENERATED_NAME = 'ai_generated_name';
    case AI_GENERATED_DESCRIPTION = 'ai_generated_description';

    case PREVIEW_SNAPSHOT = 'preview_snapshot'; // Rendered preview image for PDFs/audio (not user-visible)

    public function label(): string
    {
        return match ($this) {
            self::EXTRACTED_TEXT => 'Extracted Text',
            self::TRANSCRIPTION => 'Transcription',
            self::TIKA_METADATA => 'Tika Metadata',
            self::AI_SUGGESTED_TAGS => 'AI Suggested Tags',
            self::AI_SUGGESTED_NAME => 'AI Suggested Name',
            self::AI_SUGGESTED_DESCRIPTION => 'AI Suggested Description',
            self::AI_SUGGESTED_METADATA => 'AI Suggested Metadata',
            self::AI_GENERATED_TAGS => 'AI Generated Tags',
            self::AI_GENERATED_NAME => 'AI Generated Name',
            self::AI_GENERATED_DESCRIPTION => 'AI Generated Description',
            self::PREVIEW_SNAPSHOT => 'Preview Snapshot',
        };
    }
}
