<?php

namespace App\Services;

use App\Enums\SystemFilePurpose;
use App\Jobs\AutoTagResource;
use App\Jobs\ExtractFileText;
use App\Models\File;
use App\Models\Resource;
use App\Models\SystemFile;
use App\Services\Processing\TikaService;

class AityEnrichmentService
{
    public function __construct(
        private readonly TikaService $tika,
    ) {}

    // =========================================================================
    // TRIGGERS
    // =========================================================================

    /**
     * Full pipeline: Tika extraction → chunking → LLM/vision suggestions.
     * Used on file upload and on manual full-retry.
     * Writes processing_status = queued synchronously, then dispatches ExtractFileText
     * which chains downstream jobs (AutoTagResource, AnalyzeImageContent, EmbedFileChunks).
     */
    public function enrich(File $file): void
    {
        $resource = Resource::find($file->resource_id);
        $mime = $file->mime_type ?? '';

        if (! $this->tika->isTextExtractable($mime) && ! $this->tika->isMetadataExtractable($mime)) {
            // MIME type has no extraction or AI path — mark as not_applicable immediately.
            $file->updateProcessingStage('not_applicable', [
                'queued_at' => now()->toIso8601String(),
                'done_at' => now()->toIso8601String(),
                'results' => ['source' => 'none', 'has_name' => false, 'has_description' => false, 'tag_count' => 0],
            ]);
            $resource?->recomputeAndSaveAityStatus();

            return;
        }

        $file->updateProcessingStage('queued', ['queued_at' => now()->toIso8601String()]);
        ExtractFileText::dispatch($file->id);
        $resource?->recomputeAndSaveAityStatus();
    }

    /**
     * LLM-only retag: skips Tika, reads existing extracted text or transcription.
     * Used by the aity-retag endpoint when extraction has already run.
     */
    public function retag(File $file): void
    {
        $file->updateProcessingStage('queued', ['queued_at' => now()->toIso8601String()]);

        $hasExtractedText = SystemFile::where('source_file_id', $file->id)
            ->whereIn('purpose', [
                SystemFilePurpose::EXTRACTED_TEXT->value,
                SystemFilePurpose::TRANSCRIPTION->value,
            ])
            ->where('is_active', true)
            ->exists();

        if ($hasExtractedText) {
            AutoTagResource::dispatch($file->resource_id, $file->id);
        } else {
            AutoTagResource::dispatch($file->resource_id);
        }

        Resource::find($file->resource_id)?->recomputeAndSaveAityStatus();
    }

    /**
     * Full enrich for every file of a resource.
     */
    public function enrichAll(Resource $resource): void
    {
        $resource->load('files');
        foreach ($resource->files as $file) {
            $this->enrich($file);
        }
    }

    /**
     * Retag every file of a resource that already has extracted text.
     */
    public function retagAll(Resource $resource): void
    {
        $resource->load('files');
        foreach ($resource->files as $file) {
            $this->retag($file);
        }
    }

    // =========================================================================
    // STATUS
    // =========================================================================

    /**
     * Returns the current enrichment status for a file, including suggestions
     * payload when stage is 'done'. Used by the aity-status polling endpoint.
     */
    public function getStatus(File $file): array
    {
        $ps = $file->processing_status ?? [];
        $stage = $ps['stage'] ?? null;

        $suggestions = null;

        if ($stage === 'done') {
            $file->load([
                'latestAiSuggestedTagsSystemFileForDisplay',
                'latestAiSuggestedNameSystemFileForDisplay',
                'latestAiSuggestedDescriptionSystemFileForDisplay',
                'latestAiSuggestedMetadataSystemFileForDisplay',
            ]);

            $name = $file->latestAiSuggestedNameSystemFileForDisplay?->metadata['value'] ?? null;
            $description = $file->latestAiSuggestedDescriptionSystemFileForDisplay?->metadata['value'] ?? null;
            $tags = $file->latestAiSuggestedTagsSystemFileForDisplay?->metadata['value'] ?? [];
            // Scheme-field suggestions (ai_fill contract, docs/SCHEMA_FIELDS.md)
            $metadata = $file->latestAiSuggestedMetadataSystemFileForDisplay?->metadata['value'] ?? [];

            if ($name !== null || $description !== null || ! empty($tags) || ! empty($metadata)) {
                $suggestions = [
                    'name' => is_string($name) ? $name : null,
                    'description' => is_string($description) ? $description : null,
                    'tags' => is_array($tags) ? $tags : [],
                    'metadata' => is_array($metadata) ? $metadata : [],
                ];
            }
        }

        return [
            'file_id' => $file->id,
            'stage' => $stage,
            'error' => $ps['error'] ?? null,
            'queued_at' => $ps['queued_at'] ?? null,
            'started_at' => $ps['started_at'] ?? null,
            'done_at' => $ps['done_at'] ?? null,
            'results' => $ps['results'] ?? null,
            'suggestions' => $suggestions,
        ];
    }
}
