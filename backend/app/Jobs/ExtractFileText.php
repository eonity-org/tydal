<?php

namespace App\Jobs;

use App\Enums\FileRole;
use App\Enums\SystemFilePurpose;
use App\Models\File;
use App\Models\FileChunk;
use App\Models\Resource;
use App\Models\SystemFile;
use App\Services\AiActivityLogger;
use App\Services\ElasticsearchService;
use App\Services\FileStorageService;
use App\Services\Interfaces\ResourceServiceInterface;
use App\Services\Processing\ChunkingService;
use App\Services\Processing\TikaService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ExtractFileText implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Allow up to 180 seconds per attempt (Tika can be slow on large PDFs).
     * 2 tries: one attempt + one retry on transient failure (e.g. Tika restarting).
     */
    public int $timeout = 180;

    public int $tries = 2;

    public function __construct(private readonly string $fileId) {}

    // One extraction job per file at a time — prevents race conditions when the
    // user retries before the original job finishes (or on at-least-once delivery).
    public function uniqueId(): string
    {
        return $this->fileId;
    }

    // Hold the lock for 10 min — longer than the job timeout so the lock survives
    // the full Tika + queued downstream jobs window.
    public function uniqueFor(): int
    {
        return 600;
    }

    // =========================================================================
    // HANDLE
    // =========================================================================

    public function handle(TikaService $tika, ChunkingService $chunker, ResourceServiceInterface $resourceService): void
    {
        $file = File::with(['resource.collection.scheme'])->find($this->fileId);

        if (! $file || ! $file->resource) {
            return;
        }

        $file->updateProcessingStage('extracting', ['started_at' => now()->toIso8601String()]);

        AiActivityLogger::extractJobStarted($this->fileId);

        $resource = $file->resource;
        $scheme = $resource->collection?->scheme;
        $chunkSize = $scheme?->getChunkSize() ?? (int) config('embedding.max_chunk_words', 400);
        $overlap = $scheme?->getChunkOverlap() ?? (int) config('embedding.chunk_overlap', 50);

        ['path' => $absolutePath, 'temp' => $isTemp] = $tika->resolveAbsolutePath($file);

        try {
            if ($tika->isTextExtractable($file->mime_type)) {
                // Exceptions propagate → job retries → failed() on exhaustion
                $this->processTextFile($file, $resource, $tika, $chunker, $absolutePath, $chunkSize, $overlap);
            } elseif ($tika->isMetadataExtractable($file->mime_type)) {
                // Best-effort: metadata failures are caught internally, not retried
                $this->processMetadataFile($file, $resource, $tika, $absolutePath);
            }
            // else: unsupported type — skip silently
        } finally {
            if ($isTemp) {
                @unlink($absolutePath);
            }
        }

        // Promote Tika metadata to the resource — canonical or component files
        // drive promoted_file_metadata (components contribute equally, see
        // docs/RESOURCE_MODEL.md); supporting files never do.
        if ($file->isCanonical() || $file->role === FileRole::COMPONENT) {
            $resourceService->recalculatePromotedMetadata($resource);
            $resource->refresh(); // ensure promoted_file_metadata is fresh before ES indexing
        }

        // Re-index so ES picks up extracted_text + tika_metadata
        if ($resource->state->isVisible()) {
            IndexResourceToElasticsearch::dispatchSync($resource->id);
        }

        // Metadata-only files (images/audio/video) never reach EmbedFileChunks,
        // so the resource's identity would never enter vector space — upsert
        // the synthetic metadata chunk here or the resource stays invisible to
        // every semantic surface (card k-NN, both ask heads).
        if (! $tika->isTextExtractable($file->mime_type) && $tika->isMetadataExtractable($file->mime_type)) {
            UpsertResourceMetadataChunk::dispatch($resource->id);
        }

        // Chain embedding for text-extractable files (metadata-only files have no chunks to embed).
        // Embedding runs for all roles so supporting/component chunks remain k-NN searchable.
        $llmDispatched = false;
        if ($tika->isTextExtractable($file->mime_type)) {
            EmbedFileChunks::dispatch($file->id);
            if (config('autotagging.enabled')) {
                AiActivityLogger::autotagDispatched($resource->id, $file->id);
                AutoTagResource::dispatch($resource->id, $file->id);
                $llmDispatched = true;
            }
        }

        // Vision-based analysis for images — all file roles contribute tag suggestions.
        if (str_starts_with($file->mime_type ?? '', 'image/') && config('autotagging.vision_enabled')) {
            AiActivityLogger::visionDispatched($file->id, $resource->id, $file->mime_type ?? '');
            AnalyzeImageContent::dispatch($file->id);
            $llmDispatched = true;
        }

        // No LLM job will follow — Tika ran but AI does not apply for this MIME type.
        if (! $llmDispatched) {
            $file->updateProcessingStage('not_applicable', [
                'done_at' => now()->toIso8601String(),
                'results' => ['source' => 'tika', 'has_name' => false, 'has_description' => false, 'tag_count' => 0],
            ]);
            $resource->recomputeAndSaveAityStatus();
        }

        // Extract embedded preview image.
        // Canonical files always drive the snapshot.
        // For multi-component resources (no canonical) the first eligible PDF/audio
        // processed wins — skip if a snapshot already exists to avoid races.
        if ($file->isAudio() || $file->mime_type === 'application/pdf') {
            $shouldExtract = $file->isCanonical()
                || ! SystemFile::where('resource_id', $file->resource_id)
                    ->where('purpose', SystemFilePurpose::PREVIEW_SNAPSHOT->value)
                    ->where('is_active', true)
                    ->exists();

            if ($shouldExtract) {
                ExtractEmbeddedPreview::dispatch($file->id);
            }
        }
    }

    // =========================================================================
    // TEXT EXTRACTION (with chunking)
    // =========================================================================

    private function processTextFile(
        File $file,
        Resource $resource,
        TikaService $tika,
        ChunkingService $chunker,
        string $absolutePath,
        int $chunkSize,
        int $overlap
    ): void {
        $t0 = hrtime(true);
        $extracted = $tika->extractText($absolutePath);
        $metadata = $tika->extractMetadata($absolutePath);
        $chunks = $chunker->chunk($extracted['xhtml'], $file->mime_type, $chunkSize, $overlap);
        $charCount = array_sum(array_column($chunks, 'word_count'));

        $disk = $file->disk ?? 'local';
        $stored = FileStorageService::storeExtractedText($resource->id, $file->id, json_encode($chunks), $disk);

        DB::transaction(function () use ($file, $resource, $stored, $chunks, $metadata) {
            // Deactivate superseded extraction records for this source file
            SystemFile::where('resource_id', $resource->id)
                ->where('source_file_id', $file->id)
                ->where('purpose', SystemFilePurpose::EXTRACTED_TEXT->value)
                ->update(['is_active' => false]);

            $systemFile = SystemFile::create([
                'resource_id' => $resource->id,
                'source_file_id' => $file->id,
                'purpose' => SystemFilePurpose::EXTRACTED_TEXT,
                'filename' => $stored['filename'],
                'mime_type' => $stored['mime_type'],
                'size' => $stored['size'],
                'path' => $stored['path'],
                'disk' => $stored['disk'],
                'is_active' => true,
                'metadata' => [
                    'chunk_count' => count($chunks),
                    'extracted_at' => now()->toIso8601String(),
                    'char_count' => array_sum(array_column($chunks, 'word_count')),
                    'tika_metadata' => $metadata,
                ],
            ]);

            // Replace chunk manifest for this source file
            FileChunk::where('resource_id', $resource->id)
                ->where('source_file_id', $file->id)
                ->delete();

            if (! empty($chunks)) {
                $rows = array_map(fn ($chunk) => [
                    'id' => (string) Str::orderedUuid(),
                    'resource_id' => $resource->id,
                    'source_file_id' => $file->id,
                    'archive_file_id' => $systemFile->id,
                    'sequence' => $chunk['sequence'],
                    'page_number' => $chunk['page_number'],
                    'word_count' => $chunk['word_count'],
                    'char_start' => $chunk['char_start'],
                    'char_end' => $chunk['char_end'],
                    'created_at' => now(),
                ], $chunks);

                FileChunk::insert($rows);
            }
        });

        // Mirror the manifest's replace semantics in ES: the new rows carry
        // fresh UUIDs, so EmbedFileChunks' upserts can never overwrite the
        // previous generation's documents — without this purge, /chunks and
        // chunk k-NN serve both generations.
        $indexName = $resource->collection?->searchIndex?->index_name;
        if ($indexName) {
            $es = app(ElasticsearchService::class);
            $es->invalidateChunksByFileId($es->buildChunksIndexName($indexName), $file->id);
        }

        AiActivityLogger::tikaExtract(
            $file->id,
            $resource->id,
            $file->mime_type ?? '',
            (int) round((hrtime(true) - $t0) / 1e6),
            $metadata ?? [],
            count($chunks),
            $charCount,
        );
    }

    // =========================================================================
    // METADATA ONLY (images, audio, video)
    // =========================================================================

    private function processMetadataFile(
        File $file,
        Resource $resource,
        TikaService $tika,
        string $absolutePath
    ): void {
        $t0 = hrtime(true);
        try {
            $metadata = $tika->extractMetadata($absolutePath);

            // Replace any existing tika_metadata record for this source file
            SystemFile::where('resource_id', $resource->id)
                ->where('source_file_id', $file->id)
                ->where('purpose', SystemFilePurpose::TIKA_METADATA->value)
                ->delete();

            SystemFile::create([
                'resource_id' => $resource->id,
                'source_file_id' => $file->id,
                'purpose' => SystemFilePurpose::TIKA_METADATA,
                'filename' => '',
                'mime_type' => '',
                'size' => 0,
                'path' => '',
                'disk' => '',
                'is_active' => true,
                'metadata' => ['tika_metadata' => $metadata],
            ]);

            AiActivityLogger::tikaMetadata(
                $file->id,
                $resource->id,
                $file->mime_type ?? '',
                (int) round((hrtime(true) - $t0) / 1e6),
                $metadata ?? [],
            );
        } catch (\Throwable $e) {
            Log::warning("Tika metadata extraction failed for file {$file->id}: ".$e->getMessage());
            AiActivityLogger::tikaMetadata(
                $file->id,
                $resource->id,
                $file->mime_type ?? '',
                (int) round((hrtime(true) - $t0) / 1e6),
                [],
                'failed',
                $e->getMessage(),
            );
            // Do not rethrow — metadata extraction is best-effort
        }
    }

    // =========================================================================
    // FAILURE HANDLER
    // =========================================================================

    /**
     * Called by Laravel after all retries are exhausted.
     * Records the failure in system_files so the status is visible via API.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error("ExtractFileText permanently failed for file {$this->fileId}: ".$exception->getMessage());

        $file = File::find($this->fileId);
        if (! $file) {
            return;
        }

        $file->updateProcessingStage('failed', ['error' => $exception->getMessage()]);
        Resource::find($file->resource_id)?->recomputeAndSaveAityStatus();

        // Only text-extractable files reach this point (metadata failures are caught above)
        $tika = app(TikaService::class);
        if (! $tika->isTextExtractable($file->mime_type)) {
            return;
        }

        SystemFile::create([
            'resource_id' => $file->resource_id,
            'source_file_id' => $file->id,
            'purpose' => SystemFilePurpose::EXTRACTED_TEXT,
            'filename' => 'extracted_text.json.gz',
            'mime_type' => 'application/gzip',
            'size' => 0,
            'path' => '',
            'disk' => $file->disk ?? 'local',
            'is_active' => false,
            'metadata' => [
                'error_message' => $exception->getMessage(),
                'failed_at' => now()->toIso8601String(),
            ],
        ]);
    }
}
