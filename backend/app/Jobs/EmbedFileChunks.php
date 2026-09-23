<?php

namespace App\Jobs;

use App\Enums\SystemFilePurpose;
use App\Exceptions\EmbeddingDimensionMismatchException;
use App\Models\File;
use App\Models\FileChunk;
use App\Models\SystemFile;
use App\Services\ElasticsearchService;
use App\Services\FileStorageService;
use App\Services\Interfaces\ResourceServiceInterface;
use App\Services\Processing\Contracts\EmbeddingServiceInterface;
use App\Services\ResourceEventLogger;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class EmbedFileChunks implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Embedding can be slow on large files with many chunks against a local Ollama instance.
     * 2 tries: one attempt + one retry on transient failure.
     */
    public int $timeout = 300;

    public int $tries = 2;

    public function __construct(private readonly string $fileId) {}

    public function getFileId(): string
    {
        return $this->fileId;
    }

    // =========================================================================
    // HANDLE
    // =========================================================================

    public function handle(EmbeddingServiceInterface $embedder, ElasticsearchService $es): void
    {
        $file = File::with(['resource.collection.searchIndex'])->find($this->fileId);

        if (! $file || ! $file->resource) {
            return;
        }

        $resource = $file->resource;
        $indexName = $resource->collection?->searchIndex?->index_name;

        if (! $indexName) {
            return;  // No ES index configured for this collection
        }

        // Find the active extracted_text archive for this specific file
        $systemFile = SystemFile::where('source_file_id', $this->fileId)
            ->where('purpose', SystemFilePurpose::EXTRACTED_TEXT->value)
            ->where('is_active', true)
            ->latest()
            ->first();

        if (! $systemFile) {
            return;  // Text extraction hasn't run yet or failed — nothing to embed
        }

        // Read chunks from gzip archive (source of truth on disk)
        $chunks = FileStorageService::readExtractedText($systemFile->path, $systemFile->disk);

        if (empty($chunks)) {
            return;
        }

        // Load chunk manifest from MySQL to get stable UUIDs (keyed by sequence)
        $chunkRows = FileChunk::where('source_file_id', $this->fileId)
            ->orderBy('sequence')
            ->get()
            ->keyBy('sequence');

        // Batch-embed all chunk contents
        $batchSize = (int) config('embedding.batch_size', 32);
        $chunksIndex = $es->buildChunksIndexName($indexName);

        $batches = array_chunk($chunks, $batchSize);

        $expectedDims = (int) config('embedding.dimensions', 768);
        $driver = config('embedding.driver', 'ollama');
        $model = config("embedding.{$driver}.model");

        foreach ($batches as $batch) {
            $texts = array_column($batch, 'content');
            $vectors = $embedder->embedBatch($texts);

            // Fail loudly on a size mismatch instead of letting ES reject every
            // vector with an opaque mapping error. failed() records it on the
            // resource timeline.
            if (! empty($vectors) && is_array($vectors[0])) {
                EmbeddingDimensionMismatchException::assert(count($vectors[0]), $expectedDims, $model, $driver);
            }

            foreach ($batch as $i => $chunk) {
                $seq = $chunk['sequence'];
                $chunkRow = $chunkRows->get($seq);

                if (! $chunkRow) {
                    Log::warning("EmbedFileChunks: no FileChunk row for file {$this->fileId} sequence {$seq}");

                    continue;
                }

                $es->upsertChunkVector($chunksIndex, [
                    'chunk_id' => $chunkRow->id,
                    'resource_id' => $resource->id,
                    'file_id' => $this->fileId,
                    'collection_id' => $resource->collection_id,
                    'sequence' => $seq,
                    'page_number' => $chunk['page_number'] ?? null,
                    'content' => $chunk['content'],
                    'vector' => $vectors[$i],
                ]);
            }
        }

        // Upsert a synthetic metadata chunk so the resource's name and description
        // are searchable via k-NN even if the user fills them in after upload.
        // This chunk is also re-upserted whenever name/description changes (Resource::boot).
        UpsertResourceMetadataChunk::dispatch($resource->id);

        // Record embedding completion the same way ExtractFileText records
        // extraction stages — lets callers check "is this file embedded"
        // without querying Elasticsearch.
        $file->updateProcessingStage('embedded', ['chunk_count' => count($chunks)]);

        // Fresh chunk vectors change the resource-level mean embedding (§8)
        app(ResourceServiceInterface::class)
            ->recalculateResourceEmbedding($resource);
    }

    // =========================================================================
    // FAILURE HANDLER
    // =========================================================================

    public function failed(\Throwable $exception): void
    {
        Log::error("EmbedFileChunks permanently failed for file {$this->fileId}: ".$exception->getMessage());

        // Update the active SystemFile metadata to record the embedding failure
        SystemFile::where('source_file_id', $this->fileId)
            ->where('purpose', SystemFilePurpose::EXTRACTED_TEXT->value)
            ->where('is_active', true)
            ->update([
                'metadata' => DB::raw(
                    "JSON_SET(metadata, '$.embedding_error', ".
                    json_encode($exception->getMessage()).
                    ", '$.embedding_failed_at', ".
                    json_encode(now()->toIso8601String()).')'
                ),
            ]);

        // Surface the failure on the resource activity timeline (not just the
        // file chip), so a misconfigured embedding model is visible there.
        $resourceId = File::where('id', $this->fileId)->value('resource_id');
        if ($resourceId) {
            $isDimMismatch = $exception instanceof EmbeddingDimensionMismatchException;
            app(ResourceEventLogger::class)->log(
                resourceId: $resourceId,
                eventType: 'embedding_failed',
                actorType: ResourceEventLogger::ACTOR_SYSTEM,
                targetType: ResourceEventLogger::TARGET_FILE,
                targetId: $this->fileId,
                payload: [
                    'error' => $exception->getMessage(),
                    'reason' => $isDimMismatch ? 'dimension_mismatch' : 'error',
                    'driver' => config('embedding.driver', 'ollama'),
                    'model' => config('embedding.'.config('embedding.driver', 'ollama').'.model'),
                ],
            );
        }
    }
}
