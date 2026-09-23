<?php

namespace App\Jobs;

use App\Enums\SystemFilePurpose;
use App\Models\Resource;
use App\Models\SystemFile;
use App\Services\ElasticsearchService;
use App\Services\Interfaces\ResourceServiceInterface;
use App\Services\Processing\Contracts\EmbeddingServiceInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Upserts a synthetic "metadata chunk" for a resource into its chunks index.
 *
 * Chunk ID: "meta-{resourceId}" — deterministic and stable across re-runs.
 * Sequence: -1 — sentinel value that identifies this as a metadata chunk,
 *           not an extracted text passage, so it is excluded from source citations.
 *
 * Content varies by the organization's AiTy RAG mode:
 *
 *   Strict mode (default):
 *     Only human-confirmed fields — name, description, applied semantic tags.
 *
 *   Non-strict mode (org setting: settings.aity.rag_strict_mode = false):
 *     Confirmed fields take precedence; AI suggestions fill gaps (name/description)
 *     or augment (tags union). Resources become RAG-searchable immediately after
 *     AutoTagResource, without waiting for human review.
 *
 * Dispatched from:
 *  - EmbedFileChunks       — first embedding pass after text extraction
 *  - ExtractFileText       — metadata-only files (images/audio/video), which
 *                            never reach EmbedFileChunks
 *  - Resource::boot updated — when name or description is saved
 *  - SemanticTagController — when semantic tags are synced
 *  - AutoTagResource        — when non-strict: after suggestions are stored
 */
class UpsertResourceMetadataChunk implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 60;

    public function __construct(private readonly string $resourceId) {}

    public function getResourceId(): string
    {
        return $this->resourceId;
    }

    public function handle(EmbeddingServiceInterface $embedder, ElasticsearchService $es, ResourceServiceInterface $resourceService): void
    {
        $resource = Resource::with([
            'collection.searchIndex',
            'collection.organization',
            'semanticTags',
        ])->find($this->resourceId);

        if (! $resource) {
            return;
        }

        $indexName = $resource->collection?->searchIndex?->index_name;

        if (! $indexName) {
            return;
        }

        $chunksIndex = $es->buildChunksIndexName($indexName);

        if (! $es->chunksIndexExists($chunksIndex)) {
            return;
        }

        // ── Confirmed fields ──────────────────────────────────────────────────

        $name = trim($resource->name ?? '');
        $description = trim($resource->description ?? '');
        $tagLabels = $resource->semanticTags
            ->where('is_active', true)
            ->pluck('label')
            ->filter()
            ->values()
            ->all();

        // ── Non-strict mode: fold in AI suggestions ───────────────────────────

        $organization = $resource->collection?->organization;
        $strictMode = $organization ? $organization->aityRagStrictMode() : true;

        if (! $strictMode) {
            $suggestions = $this->loadAiSuggestions($resource);

            // Confirmed fields always win; suggestions only fill confirmed gaps
            if (! $name && ! empty($suggestions['name'])) {
                $name = trim($suggestions['name']);
            }
            if (! $description && ! empty($suggestions['description'])) {
                $description = trim($suggestions['description']);
            }

            // Tags: union of confirmed labels + suggested labels (deduped)
            $suggestedLabels = array_filter(array_column($suggestions['tags'], 'label'));
            $tagLabels = array_values(array_unique(array_merge($tagLabels, $suggestedLabels)));
        }

        // ── Build content ─────────────────────────────────────────────────────

        if (! $name && ! $description && empty($tagLabels)) {
            return; // Nothing to embed — skip
        }

        $tikaLine = $this->formatTikaMetadata($resource->promoted_file_metadata ?? []);

        $lines = array_filter([
            $name ? "Resource: {$name}" : null,
            $description ? "Description: {$description}" : null,
            ! empty($tagLabels) ? 'Tags: '.implode(', ', $tagLabels) : null,
            $tikaLine ? "File properties: {$tikaLine}" : null,
        ]);
        $content = implode("\n", $lines);

        // ── Embed and upsert ──────────────────────────────────────────────────

        try {
            $vector = $embedder->embed($content);
        } catch (\Throwable $e) {
            Log::warning("UpsertResourceMetadataChunk: embedding failed for resource {$this->resourceId}: ".$e->getMessage());

            return;
        }

        // refresh: the recalculation below searches this document back —
        // without it the NRT delay makes chunkless resources miss their
        // fallback vector and keep a NULL embedding.
        $es->upsertChunkVector($chunksIndex, [
            'chunk_id' => 'meta-'.$resource->id,
            'resource_id' => $resource->id,
            'file_id' => '',                     // no source file — synthetic chunk
            'collection_id' => $resource->collection_id,
            'sequence' => -1,                     // sentinel: metadata chunk
            'page_number' => null,
            'content' => $content,
            'vector' => $vector,
        ], refresh: true);

        // A fresh meta vector can change the resource mean — for chunkless
        // resources it IS the mean (fallback in recalculateResourceEmbedding),
        // which is what makes them rankable in semantic card search at all.
        $resourceService->recalculateResourceEmbedding($resource);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("UpsertResourceMetadataChunk permanently failed for resource {$this->resourceId}: ".$exception->getMessage());
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Build a compact, human-readable summary of Tika metadata for embedding.
     *
     * Returns a single comma-separated line of "Key: value" pairs, or empty string
     * if nothing useful is present. Filters out:
     *   - Tika-internal headers (X- prefix)
     *   - Parser/handler class names (X-TIKA:*, X-Parsed-By)
     *   - Empty values and values > 300 chars (likely base64 thumbnail data)
     *
     * Everything else is kept: EXIF dates, GPS, camera make/model, author,
     * page count, language, subject — all useful for semantic search.
     */
    /**
     * Human-readable aliases for common Tika/EXIF/PDF metadata keys.
     * Unmapped keys fall back to a basic colon/underscore-to-space cleanup.
     * Keeping this list focused on fields that are semantically useful for RAG.
     */
    private const TIKA_KEY_ALIASES = [
        // Authorship / ownership
        'pdf:docinfo:creator' => 'Author',
        'dc:creator' => 'Author',
        'meta:author' => 'Author',
        'Author' => 'Author',
        'creator' => 'Author',

        // Title / subject
        'pdf:docinfo:title' => 'Title',
        'dc:title' => 'Title',
        'title' => 'Title',
        'pdf:docinfo:subject' => 'Subject',
        'dc:subject' => 'Subject',
        'subject' => 'Subject',

        // Description (Tika may include a document-level description field)
        'dc:description' => 'Document Description',

        // Dates
        'pdf:docinfo:created' => 'Created',
        'pdf:docinfo:creationdate' => 'Created',
        'meta:creation-date' => 'Created',
        'dcterms:created' => 'Created',
        'pdf:docinfo:modified' => 'Modified',
        'meta:last-modified' => 'Modified',
        'dcterms:modified' => 'Modified',
        'exif:DateTimeOriginal' => 'Date Taken',
        'Date/Time Original' => 'Date Taken',
        'Date/Time' => 'Date',

        // Document properties
        'pdf:encrypted' => 'PDF Encrypted',
        'pdf:PDFVersion' => 'PDF Version',
        'xmpTPg:NPages' => 'Page Count',
        'Page-Count' => 'Page Count',
        'meta:page-count' => 'Page Count',
        'meta:word-count' => 'Word Count',
        'dc:language' => 'Language',
        'language' => 'Language',
        'pdf:docinfo:producer' => 'PDF Producer',

        // Camera / image EXIF
        'tiff:Make' => 'Camera Make',
        'tiff:Model' => 'Camera Model',
        'Image Make' => 'Camera Make',
        'Image Model' => 'Camera Model',
        'exif:FNumber' => 'F-Number',
        'F-Number' => 'F-Number',
        'exif:ExposureTime' => 'Exposure Time',
        'Exposure Time' => 'Exposure Time',
        'exif:ISOSpeedRatings' => 'ISO',
        'ISO Speed Ratings' => 'ISO',
        'exif:FocalLength' => 'Focal Length',
        'Focal Length' => 'Focal Length',
        'tiff:ImageWidth' => 'Image Width',
        'tiff:ImageLength' => 'Image Height',
        'Image Width' => 'Image Width',
        'Image Height' => 'Image Height',

        // GPS
        'GPS Latitude' => 'GPS Latitude',
        'GPS Longitude' => 'GPS Longitude',
        'GPS Altitude' => 'GPS Altitude',
        'exif:GPSLatitude' => 'GPS Latitude',
        'exif:GPSLongitude' => 'GPS Longitude',

        // Audio / video
        'xmpDM:duration' => 'Duration',
        'xmpDM:audioSampleRate' => 'Sample Rate',
        'xmpDM:audioChannelType' => 'Audio Channels',
    ];

    private function formatTikaMetadata(array $metadata): string
    {
        // Keys to drop — Tika internals, redundant MIME info, binary markers
        $dropExact = [
            'resourceName', 'Content-Encoding', 'Content-Length',
            'Content-Type', 'Content-Type-Override',
        ];

        // Collect into label → value, deduping aliased keys (first wins)
        $pairs = [];

        foreach ($metadata as $key => $rawValue) {
            // Skip Tika internal headers
            if (str_starts_with((string) $key, 'X-') || str_starts_with((string) $key, 'x-tika')) {
                continue;
            }
            if (in_array($key, $dropExact, true)) {
                continue;
            }

            // Normalise arrays (Tika sometimes returns multi-value fields as arrays)
            $value = is_array($rawValue) ? implode(', ', $rawValue) : (string) $rawValue;
            $value = trim($value);

            // Skip empty values and base64 / binary blobs
            if ($value === '' || strlen($value) > 300) {
                continue;
            }

            // Resolve human-readable label; fall back to basic cleanup
            $label = self::TIKA_KEY_ALIASES[(string) $key]
                ?? str_replace([':', '_'], ' ', (string) $key);

            // Dedupe: if an alias already provided this label, skip duplicates
            if (! isset($pairs[$label])) {
                $pairs[$label] = "{$label}: {$value}";
            }
        }

        return implode(', ', $pairs);
    }

    /**
     * Load the most recent active AI suggestions for a resource from SystemFiles.
     * The metadata.value column mirrors the archived content for fast reads.
     *
     * @return array{name: string|null, description: string|null, tags: array}
     */
    private function loadAiSuggestions(Resource $resource): array
    {
        $files = SystemFile::where('resource_id', $resource->id)
            ->whereIn('purpose', [
                SystemFilePurpose::AI_SUGGESTED_NAME->value,
                SystemFilePurpose::AI_SUGGESTED_DESCRIPTION->value,
                SystemFilePurpose::AI_SUGGESTED_TAGS->value,
            ])
            ->where('is_active', true)
            ->whereNull('applied_at')
            ->get()
            ->keyBy('purpose');

        return [
            'name' => $files[SystemFilePurpose::AI_SUGGESTED_NAME->value]?->metadata['value'] ?? null,
            'description' => $files[SystemFilePurpose::AI_SUGGESTED_DESCRIPTION->value]?->metadata['value'] ?? null,
            'tags' => $files[SystemFilePurpose::AI_SUGGESTED_TAGS->value]?->metadata['value'] ?? [],
        ];
    }
}
