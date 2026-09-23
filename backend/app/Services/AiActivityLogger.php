<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * Structured AI pipeline activity logger.
 *
 * Only active when AI_DEBUG=true (autotagging.debug config key).
 * Each public method corresponds to one pipeline operation and writes
 * a single JSON line to storage/logs/ai-activity.log.
 *
 * Usage:
 *   AiActivityLogger::tikaExtract($file->id, $resource->id, $durationMs, [...]);
 *   AiActivityLogger::metadataPromote($resource->id, [...]);
 *   AiActivityLogger::ragContextBuild($resource->id, $question, $context, $hits);
 *
 * Tail the log:
 *   tail -f storage/logs/ai-activity.log | jq .
 */
class AiActivityLogger
{
    // ─── Tika ─────────────────────────────────────────────────────────────────

    /**
     * Full text extraction (documents, PDFs, audio transcripts).
     *
     * @param  array  $tikaMetadata  Raw tika_metadata dict returned by Tika
     * @param  int  $chunkCount  Number of text chunks produced
     * @param  int  $charCount  Total characters extracted
     */
    public static function tikaExtract(
        string $fileId,
        string $resourceId,
        string $mimeType,
        int $durationMs,
        array $tikaMetadata,
        int $chunkCount,
        int $charCount,
        string $status = 'completed',
        ?string $error = null
    ): void {
        self::write('tika_extract', $resourceId, $fileId, $status, $durationMs, [
            'mime_type' => $mimeType,
            'chunk_count' => $chunkCount,
            'char_count' => $charCount,
            'tika_keys' => array_keys($tikaMetadata),
            'error' => $error,
        ]);
    }

    /**
     * Metadata-only extraction (images, audio without transcription, video).
     *
     * @param  array  $tikaMetadata  Raw tika_metadata dict returned by Tika
     */
    public static function tikaMetadata(
        string $fileId,
        string $resourceId,
        string $mimeType,
        int $durationMs,
        array $tikaMetadata,
        string $status = 'completed',
        ?string $error = null
    ): void {
        self::write('tika_metadata', $resourceId, $fileId, $status, $durationMs, [
            'mime_type' => $mimeType,
            'tika_keys' => array_keys($tikaMetadata),
            'tika_values' => $tikaMetadata,   // full dict — useful for debugging EXIF/IPTC
            'error' => $error,
        ]);
    }

    // ─── Metadata promotion ───────────────────────────────────────────────────

    /**
     * recalculatePromotedMetadata — tracks which system files were inspected
     * and what was ultimately promoted to resources.promoted_file_metadata.
     *
     * @param  string|null  $canonicalFileId  The canonical file ID that was found (or null if none)
     * @param  array  $systemFiles  List of ['id', 'purpose', 'has_tika'] per SystemFile checked
     * @param  array|null  $mergedKeys  Keys that ended up in promoted_file_metadata (null = nothing promoted)
     * @param  array|null  $promotedValues  The actual promoted values (for deep debugging)
     */
    public static function metadataPromote(
        string $resourceId,
        ?string $canonicalFileId,
        array $systemFiles,
        ?array $mergedKeys,
        ?array $promotedValues,
        int $durationMs,
        string $status = 'completed'
    ): void {
        self::write('metadata_promote', $resourceId, $canonicalFileId, $status, $durationMs, [
            'canonical_file_id' => $canonicalFileId,
            'system_files_checked' => $systemFiles,
            'merged_keys' => $mergedKeys,
            'promoted_values' => $promotedValues,
            'promoted_count' => $mergedKeys ? count($mergedKeys) : 0,
        ]);
    }

    // ─── Auto-tagging (text / transcript) ─────────────────────────────────────

    /**
     * AutoTagResource LLM call.
     *
     * @param  string  $sourceType  'extracted_text' | 'transcription'
     * @param  string  $contentSnippet  First 500 chars of the text sent to LLM
     * @param  array  $messages  Full messages array sent to LLM
     * @param  string  $rawResponse  Raw LLM response string
     * @param  array  $parsed  Parsed suggestions array
     */
    public static function autotag(
        string $resourceId,
        string $fileId,
        string $sourceType,
        string $language,
        int $contentChars,
        string $contentSnippet,
        array $messages,
        string $rawResponse,
        array $parsed,
        int $durationMs,
        string $status = 'completed',
        ?string $error = null
    ): void {
        self::write('autotag', $resourceId, $fileId, $status, $durationMs, [
            'source_type' => $sourceType,
            'language' => $language,
            'content_chars' => $contentChars,
            'content_snippet' => $contentSnippet,
            'prompt' => self::truncateMessages($messages),
            'raw_response' => mb_substr($rawResponse, 0, 1000),
            'suggested_name' => $parsed['suggested_name'] ?? null,
            'suggested_desc' => mb_substr($parsed['suggested_description'] ?? '', 0, 200),
            'tags_count' => count($parsed['suggested_tags'] ?? []),
            'tags' => array_column($parsed['suggested_tags'] ?? [], 'label'),
            'error' => $error,
        ]);
    }

    // ─── Vision analysis ──────────────────────────────────────────────────────

    /**
     * AnalyzeImageContent LLM call.
     *
     * @param  array  $parsed  Parsed suggestions from LLM response
     */
    public static function visionAnalyze(
        string $fileId,
        string $resourceId,
        string $mimeType,
        int $imageBytes,
        string $language,
        string $rawResponse,
        array $parsed,
        int $durationMs,
        string $status = 'completed',
        ?string $error = null
    ): void {
        self::write('vision_analyze', $resourceId, $fileId, $status, $durationMs, [
            'mime_type' => $mimeType,
            'image_kb' => round($imageBytes / 1024, 1),
            'language' => $language,
            'raw_response' => mb_substr($rawResponse, 0, 1000),
            'suggested_name' => $parsed['suggested_name'] ?? null,
            'suggested_desc' => mb_substr($parsed['suggested_description'] ?? '', 0, 200),
            'tags_count' => count($parsed['suggested_tags'] ?? []),
            'tags' => array_column($parsed['suggested_tags'] ?? [], 'label'),
            'error' => $error,
        ]);
    }

    // ─── RAG ──────────────────────────────────────────────────────────────────

    /**
     * RAG context assembly — which chunks were selected and what the context looks like.
     *
     * @param  array  $hits  Raw ES hit records (resource_id, chunk_id, page_number, content)
     * @param  array  $includedChunks  Chunks that made it into the context (after char budget)
     * @param  string  $context  The assembled context string
     * @param  bool  $strict  Whether strict mode was active
     */
    public static function ragContextBuild(
        string $resourceOrScopeId,
        string $question,
        array $hits,
        array $includedChunks,
        string $context,
        bool $strict,
        int $durationMs
    ): void {
        $metaChunks = array_filter($includedChunks, fn ($c) => str_starts_with($c['chunk_id'] ?? '', 'meta-'));
        $textChunks = array_filter($includedChunks, fn ($c) => ! str_starts_with($c['chunk_id'] ?? '', 'meta-'));

        self::write('rag_context_build', $resourceOrScopeId, null, 'completed', $durationMs, [
            'question' => $question,
            'strict_mode' => $strict,
            'es_hits_count' => count($hits),
            'included_count' => count($includedChunks),
            'text_chunks' => count($textChunks),
            'meta_chunks' => count($metaChunks),
            'context_chars' => strlen($context),
            'context_preview' => mb_substr($context, 0, 1500),
            'included_sources' => array_values(array_map(fn ($c) => [
                'chunk_id' => $c['chunk_id'] ?? null,
                'resource_id' => $c['resource_id'] ?? null,
                'page' => $c['page_number'] ?? null,
            ], $includedChunks)),
        ]);
    }

    /**
     * Full RAG query — question in, answer + sources out.
     */
    public static function ragQuery(
        string $scopeId,
        string $scopeType,   // 'collection' | 'workspace' | 'vault'
        string $question,
        string $answer,
        array $sources,
        int $durationMs,
        string $status = 'completed',
        ?string $error = null
    ): void {
        self::write('rag_query', $scopeId, null, $status, $durationMs, [
            'scope_type' => $scopeType,
            'question' => $question,
            'answer' => mb_substr($answer, 0, 1000),
            'sources_count' => count($sources),
            'sources' => array_map(fn ($s) => [
                'resource_id' => $s['resource_id'] ?? null,
                'resource_name' => $s['resource_name'] ?? null,
                'page' => $s['page_number'] ?? null,
            ], $sources),
            'error' => $error,
        ]);
    }

    // ─── Model calls (concise log) ────────────────────────────────────────────

    /**
     * Record a single external model invocation in storage/logs/ai-models.log.
     *
     * This log is intentionally concise: one line per call, no prompt content.
     * It is gated by the same AI_DEBUG flag as the detailed ai-activity log.
     *
     * @param  string  $subject  'resource:<id>' for autotag/vision, 'ask-aity' for internal RAG, 'ask-vault' for the vault ask head
     * @param  string  $model  Driver+model identifier, e.g. 'claude:claude-sonnet-4-6'
     * @param  string  $operation  'autotag' | 'vision' | 'rag'
     * @param  string  $status  'completed' | 'failed'
     * @param  int  $durationMs  Wall-clock time of the LLM call in milliseconds
     * @param  string|null  $about  Brief description of what was sent: content snippet, mime+size, or question
     * @param  string|null  $error  Error message when status = 'failed'
     * @param  string|null  $responsePrev  First 200 chars of the raw model response (for diagnosing bad output)
     */
    public static function modelCall(
        string $subject,
        string $model,
        string $operation,
        string $status,
        int $durationMs,
        ?string $about = null,
        ?string $error = null,
        ?string $responsePrev = null
    ): void {
        if (! config('autotagging.debug', false)) {
            return;
        }

        $context = [
            'subject' => $subject,
            'model' => $model,
            'operation' => $operation,
            'about' => $about,
            'status' => $status,
            'duration_ms' => $durationMs,
        ];

        if ($error !== null) {
            $context['error'] = $error;
        }

        if ($responsePrev !== null) {
            $context['response_preview'] = $responsePrev;
        }

        Log::channel('ai_models')->info('model_call', $context);
    }

    // ─── AutoTag tracking (dispatch + job lifecycle) ──────────────────────────

    /**
     * Logged just before AutoTagResource::dispatch — confirms the job was queued.
     *
     * @param  string  $trigger  'extract_pipeline' | 'manual'
     */
    public static function autotagDispatched(
        string $resourceId,
        ?string $fileId,
        string $trigger = 'extract_pipeline'
    ): void {
        self::write('autotag_dispatched', $resourceId, $fileId, 'dispatched', null, [
            'trigger' => $trigger,
        ]);
    }

    /**
     * Logged at the very start of AutoTagResource::handle — confirms the job ran.
     */
    public static function autotagJobStarted(
        string $resourceId,
        ?string $fileId
    ): void {
        self::write('autotag_job_started', $resourceId, $fileId, 'started', null, []);
    }

    // ─── Extract tracking (job lifecycle) ─────────────────────────────────────

    /**
     * Logged at the very start of ExtractFileText::handle — confirms the job ran.
     */
    public static function extractJobStarted(string $fileId): void
    {
        self::write('extract_job_started', null, $fileId, 'started', null, []);
    }

    // ─── Vision tracking (dispatch + job lifecycle) ───────────────────────────

    /**
     * Logged from ExtractFileText just before AnalyzeImageContent::dispatch —
     * confirms the job was queued regardless of whether it runs or logs itself.
     */
    public static function visionDispatched(
        string $fileId,
        string $resourceId,
        string $mimeType
    ): void {
        self::write('vision_dispatched', $resourceId, $fileId, 'dispatched', null, [
            'mime_type' => $mimeType,
        ]);
    }

    /**
     * Logged at the very start of AnalyzeImageContent::handle — confirms the job ran.
     */
    public static function visionJobStarted(
        string $fileId,
        string $resourceId,
        string $mimeType,
        int $imageBytes
    ): void {
        self::write('vision_job_started', $resourceId, $fileId, 'started', null, [
            'mime_type' => $mimeType,
            'image_kb' => round($imageBytes / 1024, 1),
        ]);
    }

    /**
     * Logged at any early-exit path in AnalyzeImageContent before the LLM call.
     */
    public static function visionSkipped(
        string $fileId,
        string $resourceId,
        string $mimeType,
        string $reason
    ): void {
        self::write('vision_skipped', $resourceId, $fileId, 'skipped', null, [
            'mime_type' => $mimeType,
            'reason' => $reason,
        ]);
    }

    // ─── Internal ─────────────────────────────────────────────────────────────

    private static function write(
        string $operation,
        ?string $resourceId,
        ?string $fileId,
        string $status,
        ?int $durationMs,
        array $payload
    ): void {
        // Strip null values from payload to keep log entries lean
        $payload = array_filter($payload, fn ($v) => $v !== null);

        // Always-on audit write to resource_events for any event tied to a resource.
        // This is independent of the AI_DEBUG file log below — we want the modal
        // timeline to reflect what happened in production regardless of debug flags.
        if ($resourceId !== null) {
            self::writeResourceEvent($operation, $resourceId, $fileId, $status, $durationMs, $payload);
        }

        if (! config('autotagging.debug', false)) {
            return;
        }

        Log::channel('ai_activity')->debug($operation, [
            'operation' => $operation,
            'resource_id' => $resourceId,
            'file_id' => $fileId,
            'status' => $status,
            'duration_ms' => $durationMs,
            'payload' => $payload,
        ]);
    }

    /**
     * Persist an AITY background-work event into resource_events so the modal's
     * activity timeline can render it. Service-locator (app()) is acceptable
     * here because AiActivityLogger is intentionally static for all its callers.
     *
     * The event_type is "aity_{operation}", with a "_failed" suffix folded in
     * for failed statuses so the frontend can render failure rows distinctly.
     * Bulky payload fields (raw LLM output, full prompts, tika value dumps,
     * RAG context previews) are dropped — keep the timeline lean.
     */
    /**
     * Operations that pass a scope id (collection/workspace), not a resource id, in
     * the resourceId slot. We must not attempt to write these to resource_events —
     * the FK would fail and (worse) poison any enclosing DB transaction.
     */
    private const NON_RESOURCE_OPERATIONS = ['rag_context_build', 'rag_query'];

    private static function writeResourceEvent(
        string $operation,
        string $resourceId,
        ?string $fileId,
        string $status,
        ?int $durationMs,
        array $payload
    ): void {
        if (in_array($operation, self::NON_RESOURCE_OPERATIONS, true)) {
            return;
        }

        // Defer the resolution so failures booting the logger never break the
        // primary code path (job worker, http request, …). Logging is a side-channel.
        try {
            /** @var ResourceEventLogger $logger */
            $logger = app(ResourceEventLogger::class);
        } catch (\Throwable) {
            return;
        }

        $eventType = "aity_{$operation}";
        if ($status === 'failed' && ! str_ends_with($operation, '_failed')) {
            $eventType .= '_failed';
        }

        $BULKY = [
            'raw_response', 'prompt', 'tika_values', 'sources', 'included_sources',
            'context_preview', 'promoted_values', 'system_files_checked', 'context',
            'answer',
        ];
        $lean = array_diff_key($payload, array_flip($BULKY));
        if ($durationMs !== null) {
            $lean['duration_ms'] = $durationMs;
        }
        if ($status !== 'completed') {
            $lean['status'] = $status;
        }
        if ($fileId !== null) {
            $lean['source_file_id'] = $fileId;
        }

        try {
            $logger->log(
                resourceId: $resourceId,
                eventType: $eventType,
                actorType: ResourceEventLogger::ACTOR_AITY,
                targetType: $fileId ? ResourceEventLogger::TARGET_FILE : null,
                targetId: $fileId,
                payload: $lean,
            );
        } catch (\Throwable $e) {
            // Never let an audit-log write break the calling job. We have the
            // file log as a fallback for verbose diagnostics.
            Log::warning('resource_events write failed', [
                'operation' => $operation, 'resource_id' => $resourceId, 'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Truncate messages array for logging — keeps system prompt full,
     * truncates user content to 800 chars to keep log entries readable.
     */
    private static function truncateMessages(array $messages): array
    {
        return array_map(function ($msg) {
            $content = $msg['content'] ?? '';
            if (is_string($content) && $msg['role'] === 'user') {
                $content = mb_substr($content, 0, 800).(mb_strlen($content) > 800 ? '…' : '');
            }

            return ['role' => $msg['role'], 'content' => $content];
        }, $messages);
    }
}
