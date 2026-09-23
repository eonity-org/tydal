<?php

namespace App\Jobs;

use App\Enums\SystemFilePurpose;
use App\Models\File;
use App\Models\Resource;
use App\Models\SystemFile;
use App\Services\AiActivityLogger;
use App\Services\FileStorageService;
use App\Services\LLM\Contracts\LlmServiceInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AutoTagResource implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * LLM calls can be slow, especially on local Ollama (model load + inference).
     * Timeout is deliberately higher than the LLM HTTP timeout so the job can catch
     * and log a clean error rather than being killed mid-request.
     * 2 tries: one attempt + one retry on transient failure.
     */
    public int $timeout = 300;

    public int $tries = 2;

    /**
     * @param  string  $resourceId  The resource to tag.
     * @param  string|null  $sourceFileId  When set, only use the extracted text of this specific file.
     *                                     When null, falls back to the most recent extraction for the resource
     *                                     (used by the manual /autotag endpoint).
     */
    public function __construct(
        private readonly string $resourceId,
        private readonly ?string $sourceFileId = null,
    ) {}

    public function getResourceId(): string
    {
        return $this->resourceId;
    }

    public function getSourceFileId(): ?string
    {
        return $this->sourceFileId;
    }

    // =========================================================================
    // HANDLE
    // =========================================================================

    public function handle(LlmServiceInterface $llm): void
    {
        if (! config('autotagging.enabled')) {
            return;
        }

        AiActivityLogger::autotagJobStarted($this->resourceId, $this->sourceFileId);

        if ($this->sourceFileId) {
            $sourceFile = File::find($this->sourceFileId);
            if ($sourceFile && ($sourceFile->processing_status['stage'] ?? null) !== 'done') {
                $sourceFile->updateProcessingStage('ai_analyzing', ['started_at' => now()->toIso8601String()]);
            }
        }

        $resource = Resource::with(['organization', 'collection'])->find($this->resourceId);

        if (! $resource || ! $resource->organization_id) {
            return;
        }

        // File is now ai_analyzing — surface that in the resource-level status.
        if ($this->sourceFileId) {
            $resource->recomputeAndSaveAityStatus();
        }

        // Find the active text source.
        // When sourceFileId is set, target that file's extraction specifically.
        // Otherwise fall back to the most recent extraction for the resource (manual /autotag).
        // Priority: EXTRACTED_TEXT (documents/PDFs) → TRANSCRIPTION (audio/video via Whisper).
        $baseQuery = SystemFile::where('resource_id', $this->resourceId)
            ->where('is_active', true);

        if ($this->sourceFileId) {
            $baseQuery->where('source_file_id', $this->sourceFileId);
        }

        $systemFile = (clone $baseQuery)
            ->where('purpose', SystemFilePurpose::EXTRACTED_TEXT->value)
            ->latest()
            ->first();

        $isTranscription = false;

        if (! $systemFile) {
            $systemFile = (clone $baseQuery)
                ->where('purpose', SystemFilePurpose::TRANSCRIPTION->value)
                ->latest()
                ->first();

            $isTranscription = $systemFile !== null;
        }

        if (! $systemFile) {
            return;
        }

        $sourceFileId = $systemFile->source_file_id;

        if (! $sourceFileId) {
            Log::info("AutoTagResource: systemFile {$systemFile->id} has no source_file_id; suggestions skipped.");

            return;
        }

        $chunks = FileStorageService::readExtractedText($systemFile->path, $systemFile->disk);
        $content = implode("\n", array_column($chunks, 'content'));
        $content = mb_substr($content, 0, config('autotagging.max_chars', 6000));

        if (empty(trim($content))) {
            return;
        }

        $maxTags = config('autotagging.max_tags', 10);

        $contentLabel = $isTranscription ? 'audio transcript' : 'document';

        $language = $resource->collection?->language ?? 'en';
        $languageNote = " All text values in your JSON response (suggested_name, suggested_description, tag labels and descriptions) must be written in the language with BCP 47 code \"{$language}\".";

        // Scheme fields that opted into AI filling (ai_fill.enabled — the
        // semantic contract, docs/SCHEMA_FIELDS.md). The field's own contract
        // constrains the extraction: selects give a closed option list.
        $aiFillFields = collect($resource->collection?->scheme?->fields)
            ->filter(fn ($f) => ($f['ai_fill']['enabled'] ?? false) === true && is_string($f['name'] ?? null))
            ->values();

        $metadataSection = '';
        if ($aiFillFields->isNotEmpty()) {
            $specs = $aiFillFields->map(function (array $f) {
                $hint = $f['ai_fill']['hint'] ?? $f['display_name'] ?? $f['name'];
                $constraint = match ($f['type'] ?? 'string') {
                    'select' => isset($f['validators']['in']) && is_array($f['validators']['in'])
                        ? 'exactly one of: '.implode(' | ', $f['validators']['in'])
                        : 'a short string',
                    'integer' => 'an integer',
                    'boolean' => 'true or false',
                    'date' => 'an ISO 8601 date',
                    'array' => 'an array of short strings',
                    default => 'a short string',
                };

                return "    \"{$f['name']}\": {$constraint} — {$hint}";
            })->implode(",\n");

            $metadataSection = ",\n  \"suggested_metadata\": {\n{$specs}\n  } (use null for any field the {$contentLabel} gives no evidence for)";
        }

        $messages = [
            [
                'role' => 'system',
                'content' => 'You are a metadata assistant for a digital asset management system. '
                           ."Analyse the provided {$contentLabel} and return structured metadata suggestions."
                           .$languageNote.' '
                           .'Always respond with valid JSON only — no markdown, no explanation.',
            ],
            [
                'role' => 'user',
                'content' => "Analyse the following {$contentLabel} and return a JSON object with exactly these keys:\n\n"
                           ."{\n"
                           ."  \"suggested_name\": \"concise title for this {$contentLabel} (max 80 characters)\",\n"
                           ."  \"suggested_description\": \"1-3 sentence summary of the {$contentLabel} content\",\n"
                           ."  \"suggested_tags\": [{\"label\": \"Tag Label\", \"description\": \"Why this tag applies\", \"type\": \"one of: person|organization|place|thing|tag\", \"confidence\": 0.95}, ...] (up to {$maxTags} tags; confidence is a float 0.0–1.0 reflecting how strongly the tag applies to this content)"
                           .$metadataSection."\n"
                           ."}\n\n"
                           .ucfirst($contentLabel)." content:\n{$content}",
            ],
        ];

        $t0 = hrtime(true);
        try {
            $raw = $llm->chat($messages);
            $parsed = $this->parseAiSuggestionsResponse($raw);
        } catch (\Throwable $e) {
            $durationMs = (int) round((hrtime(true) - $t0) / 1e6);
            Log::warning("AutoTagResource: LLM call failed for resource {$this->resourceId}: ".$e->getMessage());
            AiActivityLogger::modelCall(
                'resource:'.$this->resourceId, $llm->getModel(),
                'autotag', 'failed', $durationMs,
                mb_substr($content, 0, 120), $e->getMessage(),
            );
            AiActivityLogger::autotag(
                $this->resourceId, $sourceFileId,
                $isTranscription ? 'transcription' : 'extracted_text',
                $language, mb_strlen($content),
                mb_substr($content, 0, 500),
                $messages, '', [],
                $durationMs,
                'failed', $e->getMessage(),
            );
            throw $e; // Allow retry
        }

        if (empty($parsed)) {
            Log::warning("AutoTagResource: no suggestions parsed from LLM response for resource {$this->resourceId}");

            // A valid model response can legitimately yield no usable fields.
            // This is still a completed analysis, not an in-flight one: leaving
            // the source at ai_analyzing keeps the AITY review batch waiting
            // forever for a job that has already returned.
            $sourceFile = File::find($sourceFileId);
            $sourceFile?->updateProcessingStage('done', [
                'done_at' => now()->toIso8601String(),
                'results' => [
                    'source' => 'llm',
                    'has_name' => false,
                    'has_description' => false,
                    'tag_count' => 0,
                ],
            ]);
            $resource->recomputeAndSaveAityStatus();

            return;
        }

        $durationMs = (int) round((hrtime(true) - $t0) / 1e6);
        AiActivityLogger::modelCall(
            'resource:'.$this->resourceId, $llm->getModel(), 'autotag', 'completed', $durationMs,
            mb_substr($content, 0, 120), null, mb_substr($raw, 0, 200),
        );
        AiActivityLogger::autotag(
            $this->resourceId, $sourceFileId,
            $isTranscription ? 'transcription' : 'extracted_text',
            $language, mb_strlen($content),
            mb_substr($content, 0, 500),
            $messages, $raw, $parsed,
            $durationMs,
        );

        // Re-check: resource may have been deleted while the LLM was running
        if (! Resource::find($this->resourceId)) {
            return;
        }

        $this->storeSuggestion(
            $resource,
            $sourceFileId,
            'suggested_tags',
            SystemFilePurpose::AI_SUGGESTED_TAGS,
            $parsed['suggested_tags'] ?? [],
        );

        $this->storeSuggestion(
            $resource,
            $sourceFileId,
            'suggested_name',
            SystemFilePurpose::AI_SUGGESTED_NAME,
            $parsed['suggested_name'] ?? null,
        );

        $this->storeSuggestion(
            $resource,
            $sourceFileId,
            'suggested_description',
            SystemFilePurpose::AI_SUGGESTED_DESCRIPTION,
            $parsed['suggested_description'] ?? null,
        );

        // Scheme-field suggestions — keep only ai_fill-enabled fields with a
        // real value; validation against the scheme happens at apply time.
        if ($aiFillFields->isNotEmpty()) {
            $allowed = $aiFillFields->pluck('name')->all();
            $metadata = collect((array) ($parsed['suggested_metadata'] ?? []))
                ->only($allowed)
                ->filter(fn ($v) => $v !== null && $v !== '')
                ->all();

            $this->storeSuggestion(
                $resource,
                $sourceFileId,
                'suggested_metadata',
                SystemFilePurpose::AI_SUGGESTED_METADATA,
                $metadata !== [] ? $metadata : null,
            );
        }

        // In non-strict RAG mode, fold the fresh suggestions into the metadata
        // chunk immediately so the resource is RAG-searchable without waiting
        // for human review. The job reads the org setting itself.
        $resource->load('collection.organization');
        $organization = $resource->collection?->organization;
        if ($organization && ! $organization->aityRagStrictMode()) {
            UpsertResourceMetadataChunk::dispatch($resource->id);
        }

        // Mark the source file as enrichment complete before recomputing so the
        // file is in 'done' stage when recompute reads it (not 'ai_analyzing').
        if ($sourceFileId) {
            $sourceFile = File::find($sourceFileId);
            $tags = $parsed['suggested_tags'] ?? [];
            $sourceFile?->updateProcessingStage('done', [
                'done_at' => now()->toIso8601String(),
                'results' => [
                    'source' => 'llm',
                    'has_name' => ! empty($parsed['suggested_name']),
                    'has_description' => ! empty($parsed['suggested_description']),
                    'tag_count' => is_array($tags) ? count($tags) : 0,
                ],
            ]);
        }

        // File is 'done' and suggestions are stored — surface suggestions_made status.
        $resource->recomputeAndSaveAityStatus();
    }

    // =========================================================================
    // FAILURE HANDLER
    // =========================================================================

    public function failed(\Throwable $exception): void
    {
        Log::error("AutoTagResource permanently failed for resource {$this->resourceId}: ".$exception->getMessage());

        if ($this->sourceFileId) {
            $sourceFile = File::find($this->sourceFileId);
            $sourceFile?->updateProcessingStage('failed', ['error' => $exception->getMessage()]);
        }

        Resource::find($this->resourceId)?->recomputeAndSaveAityStatus();
    }

    // =========================================================================
    // INTERNAL
    // =========================================================================

    /**
     * Parse the unified AI suggestions JSON object from the LLM response.
     * Handles markdown code fences and surrounding prose.
     *
     * @return array{suggested_name?: string, suggested_description?: string, suggested_tags?: array, suggested_metadata?: array}
     */
    private function parseAiSuggestionsResponse(string $response): array
    {
        // Strip markdown code fences if present
        $response = preg_replace('/```(?:json)?\s*([\s\S]*?)```/', '$1', $response) ?? $response;

        // Normalize literal newlines to spaces — LLMs often emit raw 0x0A inside
        // JSON string values, which is invalid JSON.
        $response = str_replace(["\r\n", "\r", "\n"], ' ', $response);

        // Find the outermost JSON object
        if (preg_match('/\{[\s\S]*\}/', $response, $matches)) {
            $decoded = json_decode($matches[0], true);
            if (is_array($decoded)) {
                // Normalise top-level keys so "suggested tags" → "suggested_tags"
                $normalised = [];
                foreach ($decoded as $k => $v) {
                    $normalised[str_replace(' ', '_', strtolower($k))] = $v;
                }
                // Normalise individual tag object keys to lowercase
                if (isset($normalised['suggested_tags']) && is_array($normalised['suggested_tags'])) {
                    $normalised['suggested_tags'] = array_map(
                        fn ($tag) => is_array($tag) ? array_change_key_case($tag, CASE_LOWER) : $tag,
                        $normalised['suggested_tags']
                    );
                }

                return $normalised;
            }
        }

        return [];
    }

    /**
     * Deactivate any previous SystemFile of the given purpose for this source file,
     * write the suggestion archive to disk, and create a new SystemFile record.
     *
     * The suggestion data is also stored in the SystemFile.metadata column so that
     * the API response can include it directly without re-reading the disk file.
     *
     * Suggestion archives are internal review artifacts (never served to clients),
     * so they always go on the private 'local' disk — consistent with extracted-text
     * and resource-level generated archives, and never the web-accessible 'public' disk.
     *
     * @param  mixed  $data  Raw suggestion value (array for tags, string for name/description)
     */
    private function storeSuggestion(
        Resource $resource,
        string $sourceFileId,
        string $suffix,
        SystemFilePurpose $purpose,
        mixed $data,
    ): void {
        if ($data === null || $data === [] || $data === '') {
            return;
        }

        $stored = FileStorageService::storeAiSuggestion($resource->id, $sourceFileId, $suffix, $data);

        // Atomically deactivate previous records and create the new one so that
        // a failure between the two operations never leaves the resource with no
        // active suggestion for this purpose.
        DB::transaction(function () use ($resource, $sourceFileId, $purpose, $stored, $data): void {
            SystemFile::where('source_file_id', $sourceFileId)
                ->where('purpose', $purpose->value)
                ->where('is_active', true)
                ->update(['is_active' => false]);

            SystemFile::create([
                'resource_id' => $resource->id,
                'source_file_id' => $sourceFileId,
                'purpose' => $purpose->value,
                'filename' => $stored['filename'],
                'mime_type' => $stored['mime_type'],
                'size' => $stored['size'],
                'path' => $stored['path'],
                'disk' => $stored['disk'],
                'metadata' => ['value' => $data],
                'is_active' => true,
            ]);
        });
    }
}
