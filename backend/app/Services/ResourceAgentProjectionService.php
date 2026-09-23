<?php

namespace App\Services;

use App\Models\File;
use App\Models\Resource;
use Illuminate\Support\Collection;

/**
 * Builds the lean, AI-agent-facing view of a Resource.
 *
 * ResourceController::show() returns the raw Eloquent model — every loaded
 * relation, every AI-pipeline audit field (ai_suggested_*, auto_approve_log,
 * confidence scores) — because it also powers the SPA's admin review UI.
 * That shape is wrong for MCP/agent consumption: it's bloated, it duplicates
 * data (promoted_file_metadata vs. per-file tika metadata), and it leaks
 * internal pipeline state an external agent has no use for.
 *
 * This service produces a single flat projection instead: identity, tags,
 * files with direct URLs and inlined Vault links, and chunk/embedding
 * availability — everything an agent needs to act on a resource without a
 * second round trip, and nothing it doesn't.
 */
class ResourceAgentProjectionService
{
    public function __construct(private readonly VaultLinkService $vaultLinks) {}

    /**
     * Loads collection, files, semanticTags, workspaces, and chunks if not
     * already loaded.
     */
    public function project(Resource $resource): array
    {
        $resource->loadMissing(['collection', 'files', 'semanticTags', 'workspaces', 'chunks']);

        $vault = $this->vaultLinks->getOrCreateLinksForResource($resource->id);
        $fileVaultLinks = collect($vault['files'] ?? [])->keyBy('id');

        $chunksByFile = $resource->chunks->groupBy('source_file_id');

        return [
            'id' => $resource->id,
            'slug' => $resource->slug,
            'name' => $resource->name,
            'description' => $resource->description,
            'type' => $resource->type->value,
            'state' => $resource->state->value,
            'tags' => $resource->semanticTags->map(fn ($tag) => [
                'id' => $tag->id,
                'label' => $tag->label,
                'entity_type' => $tag->entity_type,
            ])->values(),
            'metadata' => $resource->metadata,
            'collection' => $resource->collection ? [
                'id' => $resource->collection->id,
                'name' => $resource->collection->name,
            ] : null,
            'files' => $resource->files->map(fn (File $file) => $this->projectFile($file, $chunksByFile, $fileVaultLinks))->values(),
            'vault_links' => $vault['resource']['links'] ?? [],
            'content' => [
                'has_chunks' => $resource->chunks->isNotEmpty(),
                'chunk_count' => $resource->chunks->count(),
            ],
            'workspaces' => $resource->workspaces->map(fn ($ws) => [
                'id' => $ws->id,
                'name' => $ws->name,
            ])->values(),
            'created_at' => $resource->created_at,
            'updated_at' => $resource->updated_at,
        ];
    }

    /**
     * @param  Collection<int|string, mixed>  $chunksByFile  FileChunk[] grouped by source_file_id
     * @param  Collection<int|string, mixed>  $fileVaultLinks  Vault link entries keyed by file id
     */
    private function projectFile(File $file, Collection $chunksByFile, Collection $fileVaultLinks): array
    {
        $chunks = $chunksByFile->get($file->id, collect());
        $stage = $file->processing_status['stage'] ?? null;

        return [
            'id' => $file->id,
            'filename' => $file->filename,
            'mime_type' => $file->mime_type,
            'role' => $file->role->value,
            'relation' => $file->relation?->value,
            'size' => $file->size,
            'url' => $file->url,
            'conversion_urls' => $file->conversion_urls,
            'vault_links' => $fileVaultLinks->get($file->id)['links'] ?? [],
            'content' => [
                'has_chunks' => $chunks->isNotEmpty(),
                'chunk_count' => $chunks->count(),
                'embedded' => $stage === 'embedded',
            ],
        ];
    }
}
