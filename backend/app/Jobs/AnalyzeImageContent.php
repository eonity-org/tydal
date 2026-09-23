<?php

namespace App\Jobs;

use App\Enums\SystemFilePurpose;
use App\Models\File;
use App\Models\Resource;
use App\Models\SystemFile;
use App\Services\AiActivityLogger;
use App\Services\FileStorageService;
use App\Services\LLM\Contracts\LlmServiceInterface;
use App\Services\Processing\VisionImagePreparer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class AnalyzeImageContent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Job timeout must exceed OLLAMA_VISION_TIMEOUT (default 360s) so the HTTP
     * request times out cleanly before Laravel kills the job process.
     * 2 tries: one attempt + one retry on transient failure (e.g. Ollama restarting).
     */
    public int $timeout = 420;

    public int $tries = 2;

    public function __construct(
        private readonly string $fileId,
    ) {}

    // =========================================================================
    // HANDLE
    // =========================================================================

    public function handle(LlmServiceInterface $llm): void
    {
        if (! config('autotagging.vision_enabled')) {
            return;
        }

        $file = File::with(['resource.collection'])->find($this->fileId);

        if (! $file || ! $file->resource) {
            Log::warning("AnalyzeImageContent: file {$this->fileId} not found or has no resource — job aborted");

            return;
        }

        $resource = $file->resource;

        // Only process images
        if (! str_starts_with($file->mime_type ?? '', 'image/')) {
            AiActivityLogger::visionSkipped($file->id, $resource->id, $file->mime_type ?? '', 'not_an_image');

            return;
        }

        // Read the image from storage
        $disk = $file->disk ?? 'local';
        $imageData = Storage::disk($disk)->get($file->path ?? '');

        if (! $imageData) {
            Log::warning("AnalyzeImageContent: could not read image file {$file->id} from disk '{$disk}' path '{$file->path}'");
            AiActivityLogger::visionSkipped($file->id, $resource->id, $file->mime_type ?? '', "file_not_readable disk={$disk} path={$file->path}");
            $this->stampVisionAnalyzed($file);
            $this->markDoneNoSuggestions($file);

            return;
        }

        // Log that the job is running and image was loaded — this fires before the LLM call
        AiActivityLogger::visionJobStarted($file->id, $resource->id, $file->mime_type ?? '', strlen($imageData));

        if (($file->processing_status['stage'] ?? null) !== 'done') {
            $file->updateProcessingStage('ai_analyzing', ['started_at' => now()->toIso8601String()]);
            $resource->recomputeAndSaveAityStatus();
        }

        // Verify the bytes are actually a supported image format before making an API call.
        // If the storage path is wrong, we might get HTML/XML (S3 error pages) or garbage.
        // Anthropic vision supports: JPEG (/9j/), PNG (iVBOR), GIF (R0lG), WEBP (UklG).
        $b64prefix = substr(base64_encode(substr($imageData, 0, 16)), 0, 8);
        $validPrefixes = ['/9j/', 'iVBOR', 'R0lG', 'UklG'];
        $isValidImage = array_filter($validPrefixes, fn ($p) => str_starts_with($b64prefix, $p));

        if (empty($isValidImage)) {
            Log::warning(
                "AnalyzeImageContent: file {$file->id} does not appear to be a valid image ".
                "(b64 prefix: {$b64prefix}); skipping vision call."
            );
            AiActivityLogger::visionSkipped($file->id, $resource->id, $file->mime_type ?? '', "invalid_image_bytes b64_prefix={$b64prefix}");
            $this->stampVisionAnalyzed($file);
            $this->markDoneNoSuggestions($file);

            return;
        }

        $originalBytes = strlen($imageData);
        $prepared = (new VisionImagePreparer)->prepare($imageData, $file->mime_type);
        $imageData = $prepared['data'];
        $imageMimeType = $prepared['mime_type'];
        if (strlen($imageData) !== $originalBytes) {
            Log::info('AnalyzeImageContent: prepared smaller vision image', [
                'file_id' => $file->id,
                'original_bytes' => $originalBytes,
                'vision_bytes' => strlen($imageData),
                'vision_mime_type' => $imageMimeType,
            ]);
        }

        $maxTags = config('autotagging.max_tags', 10);
        $language = $resource->collection?->language ?? 'en';
        // Use the human-readable language name — local models handle "English" / "Spanish"
        // more reliably than BCP 47 codes like "en" / "es".
        // Simple map avoids a dependency on the optional PHP intl extension.
        $languageNames = [
            'en' => 'English', 'es' => 'Spanish', 'fr' => 'French',  'de' => 'German',
            'it' => 'Italian', 'pt' => 'Portuguese', 'nl' => 'Dutch', 'ru' => 'Russian',
            'zh' => 'Chinese', 'ja' => 'Japanese',  'ko' => 'Korean', 'ar' => 'Arabic',
            'pl' => 'Polish',  'sv' => 'Swedish',   'da' => 'Danish', 'fi' => 'Finnish',
            'nb' => 'Norwegian', 'tr' => 'Turkish', 'cs' => 'Czech',  'ro' => 'Romanian',
            'hu' => 'Hungarian', 'uk' => 'Ukrainian', 'ca' => 'Catalan',
        ];
        $languageName = $languageNames[$language] ?? strtoupper($language);

        // Keep the system prompt neutral — no domain language that the model could
        // parrot back as image content (a common failure mode on local models).
        $messages = [
            [
                'role' => 'system',
                'content' => 'You are an image analysis assistant. '
                           .'Look at the image the user provides and describe exactly what you visually see. '
                           .'Respond ONLY with a valid JSON object — no markdown fences, no explanation, no extra text.',
            ],
            [
                'role' => 'user',
                'content' => "Describe what you see in this image. Write ALL text values in {$languageName} only.\n\n"
                           ."Return ONLY this JSON structure (no other text):\n"
                           .'{"suggested_name":"<title in '.$languageName.', max 80 chars>","suggested_description":"<1-3 sentences in '.$languageName.' about visual content>","suggested_tags":[{"label":"<word in '.$languageName.'>","description":"<why it applies>","type":"<person|organization|place|thing|tag>","confidence":0.75}]}'
                           ."\n\nUp to {$maxTags} tags. confidence is a float 0.0–1.0: use 0.85–1.0 only for elements that dominate or are central to the image; use 0.65–0.84 for clearly visible but secondary elements; use 0.40–0.64 for inferred, partial, or background elements. Base everything on what is visually present — do not reference these instructions.",
            ],
        ];

        $t0 = hrtime(true);
        try {
            $raw = $llm->chatWithVision($imageData, $imageMimeType, $messages);
            $parsed = $this->parseResponse($raw);
        } catch (\BadMethodCallException $e) {
            // Configured LLM driver does not support vision — skip silently
            Log::info("AnalyzeImageContent: vision not supported by current LLM driver for file {$file->id}");
            AiActivityLogger::visionSkipped($file->id, $resource->id, $file->mime_type ?? '', 'driver_no_vision: '.$e->getMessage());
            $this->stampVisionAnalyzed($file);
            $this->markDoneNoSuggestions($file);

            return;
        } catch (\Throwable $e) {
            $durationMs = (int) round((hrtime(true) - $t0) / 1e6);
            Log::warning("AnalyzeImageContent: LLM call failed for file {$file->id}: ".$e->getMessage());
            AiActivityLogger::modelCall(
                'resource:'.$resource->id, $llm->getModel(),
                'vision', 'failed', $durationMs,
                $file->mime_type.' ('.round(strlen($imageData) / 1024, 1).'KB) path:'.$file->path,
                $e->getMessage(),
            );
            AiActivityLogger::visionAnalyze(
                $file->id, $resource->id, $file->mime_type, strlen($imageData), $language,
                '', [], $durationMs, 'failed', $e->getMessage(),
            );
            throw $e; // Allow retry
        }

        if (empty($parsed)) {
            $durationMs = (int) round((hrtime(true) - $t0) / 1e6);
            // Strip the markdown fence if present (used for logging only)
            $stripped = preg_replace('/```(?:json)?\s*([\s\S]*?)```/', '$1', $raw) ?? $raw;
            $jsonBlob = null;
            if (preg_match('/\{[\s\S]*\}/', $stripped, $m)) {
                $jsonBlob = $m[0];
                json_decode($jsonBlob, true);
            }
            $parseError = json_last_error_msg();
            Log::warning(
                "AnalyzeImageContent: no suggestions parsed from LLM response for file {$file->id}. ".
                "json_last_error: {$parseError}. ".
                'Raw response ('.strlen($raw).' chars): '.mb_substr($raw, 0, 2000)
            );
            AiActivityLogger::visionAnalyze(
                $file->id, $resource->id, $file->mime_type, strlen($imageData), $language,
                mb_substr($raw, 0, 500), [], $durationMs, 'failed',
                "parse_empty: {$parseError} (raw len=".strlen($raw).')',
            );
            $this->stampVisionAnalyzed($file);
            $this->markDoneNoSuggestions($file);

            return;
        }

        $durationMs = (int) round((hrtime(true) - $t0) / 1e6);
        AiActivityLogger::modelCall(
            'resource:'.$resource->id, $llm->getModel(), 'vision', 'completed', $durationMs,
            $file->mime_type.' ('.round(strlen($imageData) / 1024, 1).'KB) path:'.$file->path,
            null,
            mb_substr($raw, 0, 200),
        );
        AiActivityLogger::visionAnalyze(
            $file->id, $resource->id, $file->mime_type, strlen($imageData), $language,
            $raw, $parsed, $durationMs,
        );

        // Re-check: resource may have been deleted while the vision model was running
        if (! Resource::find($resource->id)) {
            return;
        }

        $this->storeSuggestion(
            $resource,
            $file->id,
            'suggested_tags',
            SystemFilePurpose::AI_SUGGESTED_TAGS,
            $parsed['suggested_tags'] ?? [],
        );

        $this->storeSuggestion(
            $resource,
            $file->id,
            'suggested_name',
            SystemFilePurpose::AI_SUGGESTED_NAME,
            $parsed['suggested_name'] ?? null,
        );

        $this->storeSuggestion(
            $resource,
            $file->id,
            'suggested_description',
            SystemFilePurpose::AI_SUGGESTED_DESCRIPTION,
            $parsed['suggested_description'] ?? null,
        );

        $this->stampVisionAnalyzed($file);

        $tags = $parsed['suggested_tags'] ?? [];
        $file->updateProcessingStage('done', [
            'done_at' => now()->toIso8601String(),
            'results' => [
                'source' => 'vision',
                'has_name' => ! empty($parsed['suggested_name']),
                'has_description' => ! empty($parsed['suggested_description']),
                'tag_count' => is_array($tags) ? count($tags) : 0,
            ],
        ]);
        $resource->recomputeAndSaveAityStatus();
    }

    // =========================================================================
    // FAILURE HANDLER
    // =========================================================================

    public function failed(\Throwable $exception): void
    {
        Log::error("AnalyzeImageContent permanently failed for file {$this->fileId}: ".$exception->getMessage());

        $file = File::find($this->fileId);
        $file?->updateProcessingStage('failed', ['error' => $exception->getMessage()]);
        Resource::find($file?->resource_id)?->recomputeAndSaveAityStatus();
    }

    // =========================================================================
    // INTERNAL
    // =========================================================================

    private function markDoneNoSuggestions(File $file): void
    {
        $file->updateProcessingStage('done', [
            'done_at' => now()->toIso8601String(),
            'results' => ['source' => 'none', 'has_name' => false, 'has_description' => false, 'tag_count' => 0],
        ]);
        Resource::find($file->resource_id)?->recomputeAndSaveAityStatus();
    }

    /**
     * Write vision_analyzed_at into the file's metadata JSON so the resource status
     * logic can distinguish "vision job ran but found nothing" from "job hasn't run yet".
     * Called on every exit path — success, parse failure, skipped, unsupported driver.
     */
    private function stampVisionAnalyzed(File $file): void
    {
        $file->metadata = array_merge($file->metadata ?? [], ['vision_analyzed_at' => now()->toISOString()]);
        $file->save();
    }

    private function parseResponse(string $response): array
    {
        // Strip markdown fences
        $response = preg_replace('/```(?:json)?\s*([\s\S]*?)```/', '$1', $response) ?? $response;

        // Scrub invalid UTF-8 bytes (broken multibyte sequences → replacement char)
        $response = mb_convert_encoding($response, 'UTF-8', 'UTF-8');

        // Normalize literal newlines/CR to spaces BEFORE stripping other control chars.
        // Ollama often emits literal 0x0A bytes inside JSON string values (e.g. in
        // suggested_description), which violates the JSON spec and causes json_decode
        // to fail with "Control character error". Replacing them with a space is safe:
        // JSON structural whitespace between keys just becomes a single space, while
        // string values that contained raw line breaks become single-line (which is
        // what we want for name/description/tags).
        $response = str_replace(["\r\n", "\r", "\n"], ' ', $response);

        // Strip remaining ASCII control characters + problematic Unicode characters
        // that are illegal unescaped in JSON strings.
        $response = (string) preg_replace(
            '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]|\x{00AD}|\x{200B}|\x{200C}|\x{200D}|\x{FEFF}|\x{2028}|\x{2029}/u',
            '',
            $response
        );

        // Find the first '{' and use bracket counting to locate its matching '}'.
        // This avoids the greedy regex picking up garbage closing braces that local
        // models sometimes emit after the real JSON object ends.
        $start = strpos($response, '{');
        if ($start === false) {
            return [];
        }

        $depth = 0;
        $inString = false;
        $escape = false;
        $end = null;

        for ($i = $start, $len = strlen($response); $i < $len; $i++) {
            $ch = $response[$i];

            if ($escape) {
                $escape = false;

                continue;
            }
            if ($ch === '\\' && $inString) {
                $escape = true;

                continue;
            }
            if ($ch === '"') {
                $inString = ! $inString;

                continue;
            }
            if ($inString) {
                continue;
            }
            if ($ch === '{') {
                $depth++;
            } elseif ($ch === '}') {
                $depth--;
                if ($depth === 0) {
                    $end = $i;
                    break;
                }
            }
        }

        if ($end === null) {
            // JSON was truncated before closing — try to rescue fields that were fully emitted
            return $this->parsePartialResponse($response, $start);
        }

        $json = substr($response, $start, $end - $start + 1);
        $decoded = json_decode($json, true);

        if (! is_array($decoded)) {
            return $this->parsePartialResponse($response, $start);
        }

        // Normalise top-level keys: strip spaces and lowercase so that local models
        // returning "suggested tags" (with space) map to "suggested_tags".
        $normalised = [];
        foreach ($decoded as $k => $v) {
            $normalised[str_replace(' ', '_', strtolower($k))] = $v;
        }
        $decoded = $normalised;

        // Normalise individual tag object keys to lowercase
        if (isset($decoded['suggested_tags']) && is_array($decoded['suggested_tags'])) {
            $decoded['suggested_tags'] = array_map(
                fn ($tag) => is_array($tag) ? array_change_key_case($tag, CASE_LOWER) : $tag,
                $decoded['suggested_tags']
            );
        }

        return $decoded;
    }

    /**
     * Last-resort extraction when the LLM response is truncated mid-JSON.
     * Extracts top-level string fields with regex and complete tag objects with a
     * bracket counter, stopping at the first incomplete object.
     *
     * Validates each extracted string: model runaway loops (soft-hyphen floods,
     * ellipsis avalanches) produce technically-parseable but garbage values that
     * must be rejected rather than stored.
     */
    private function parsePartialResponse(string $response, int $start): array
    {
        $result = [];

        $maxLens = ['suggested_name' => 120, 'suggested_description' => 600];

        foreach (['suggested_name', 'suggested_description'] as $key) {
            if (! preg_match('/"'.$key.'"\s*:\s*"((?:[^"\\\\]|\\\\.)*)"/u', $response, $m)) {
                continue;
            }
            $decoded = json_decode('"'.$m[1].'"');
            $val = is_string($decoded) ? $decoded : $m[1];

            // Reject runaway output: too short, too long, or dense with ellipsis chars.
            // A model stuck in a loop outputs streams of U+2026 (…) or U+00AD (soft hyphen).
            if (mb_strlen(trim($val)) < 3
                || mb_strlen($val) > $maxLens[$key]
                || mb_substr_count($val, '…') > 2
            ) {
                continue;
            }

            $result[$key] = $val;
        }

        $tagsPos = strpos($response, '"suggested_tags"', $start);
        if ($tagsPos !== false) {
            $arrStart = strpos($response, '[', $tagsPos);
            if ($arrStart !== false) {
                $tags = [];
                $pos = $arrStart + 1;
                $len = strlen($response);

                while ($pos < $len) {
                    while ($pos < $len && in_array($response[$pos], [' ', "\t", ','], true)) {
                        $pos++;
                    }
                    if ($pos >= $len || $response[$pos] !== '{') {
                        break;
                    }

                    $depth = 0;
                    $inStr = false;
                    $esc = false;
                    $objEnd = null;

                    for ($i = $pos; $i < $len; $i++) {
                        $ch = $response[$i];
                        if ($esc) {
                            $esc = false;

                            continue;
                        }
                        if ($ch === '\\' && $inStr) {
                            $esc = true;

                            continue;
                        }
                        if ($ch === '"') {
                            $inStr = ! $inStr;

                            continue;
                        }
                        if ($inStr) {
                            continue;
                        }
                        if ($ch === '{') {
                            $depth++;
                        } elseif ($ch === '}') {
                            $depth--;
                            if ($depth === 0) {
                                $objEnd = $i;
                                break;
                            }
                        }
                    }

                    if ($objEnd === null) {
                        break; // truncated object — stop here
                    }

                    $tag = json_decode(substr($response, $pos, $objEnd - $pos + 1), true);
                    if (is_array($tag)) {
                        $tags[] = array_change_key_case($tag, CASE_LOWER);
                    }
                    $pos = $objEnd + 1;
                }

                if (! empty($tags)) {
                    $result['suggested_tags'] = $tags;
                }
            }
        }

        // Detect pure garbled/runaway output: no closing brace AND high ellipsis density.
        // Log a distinct warning so this is easy to grep for vs a clean truncation.
        $ellipsisCount = mb_substr_count($response, '…');
        if (empty($result) && $ellipsisCount > 20) {
            Log::warning(
                "AnalyzeImageContent: garbled model output for file {$this->fileId} — ".
                "response contains {$ellipsisCount} ellipsis chars (runaway loop?); no data rescued. ".
                'Consider checking repeat_penalty / temperature on the model.'
            );

            return [];
        }

        if (! empty($result)) {
            Log::info(
                "AnalyzeImageContent: partial JSON recovery for file {$this->fileId} — ".
                'rescued fields: '.implode(', ', array_keys($result)).
                (isset($result['suggested_tags']) ? ' ('.count($result['suggested_tags']).' tags)' : '')
            );
        }

        return $result;
    }

    // Suggestion archives are internal review artifacts (never served to clients), so they
    // always go on the private 'local' disk — never the web-accessible 'public' disk that
    // holds the source image.
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
