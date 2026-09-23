<?php

namespace App\Services;

use App\Enums\FileRelation;
use App\Enums\FileRole;
use App\Enums\ResourceState;
use App\Enums\ResourceType;
use App\Models\Collection;
use App\Models\File;
use App\Models\Resource;
use App\Models\Vault;
use App\Models\Workspace;
use App\Services\Interfaces\ResourceServiceInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The write method an `ai` vault exposes at the boundary
 * (VAULT_WRITE_METHODS.md §7). Where `gallery` re-projects existing works,
 * `ingest` accepts a *derived artifact* produced by an external AI — a
 * translated image plus a JSON descriptor document (tables/graphs/formulae) —
 * and materializes an **output resource** the vault owner can then export
 * (typically through a companion `delivery` vault) to a downstream renderer.
 *
 * The op is the permission/audit unit (`w:ingest`); its payload is a
 * declarative document. The landing spot (workspace + collection) is
 * vault-configured, never consumer-supplied — the consumer declares *what*, the
 * vault decides *where*:
 *
 *   exposure_policy = { "ingest": { "workspace_id": 12, "collection_id": 5 } }
 */
class AiVaultWriter
{
    public function __construct(
        private ResourceServiceInterface $resources,
        private VaultLinkService $links,
    ) {}

    /**
     * Materialize one output resource: canonical JSON descriptor + a component
     * translated image (`relation: translation`), placed in the vault's
     * configured ingest workspace. Transactional; returns the audit summary.
     *
     * The result names the new resource by its vault-purpose link hash, never
     * its internal id — no UUID crosses the write boundary either
     * (VAULT_WRITE_METHODS.md §7), matching how `activate` reports `hashes`.
     *
     * @param  array{descriptor: array<array-key, mixed>, name?: string, source_hash?: string}  $document
     * @return array{hash: string, files: int}
     */
    public function ingest(Vault $vault, array $document, UploadedFile $image): array
    {
        [$workspace, $collection] = $this->ingestTarget($vault);

        return DB::transaction(function () use ($vault, $document, $image, $workspace, $collection): array {
            $ownerId = $workspace->user_owner_id ?? $collection->user_owner_id;

            $resource = $this->resources->createResource([
                'organization_id' => $vault->organization_id,
                'collection_id' => $collection->id,
                'user_owner_id' => $ownerId,
                'type' => ResourceType::DOCUMENT->value,
                'name' => $document['name'] ?? 'Ingested figure',
                'state' => ResourceState::LIVE->value,
                'metadata' => isset($document['source_hash'])
                    ? ['source_hash' => $document['source_hash']]
                    : null,
            ]);

            // Canonical = the JSON descriptor (what the renderer addresses).
            $jsonPath = tempnam(sys_get_temp_dir(), 'ingest').'.json';
            file_put_contents(
                $jsonPath,
                json_encode($document['descriptor'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            );
            $this->attachFile($resource, $jsonPath, 'descriptor.json', FileRole::CANONICAL, null, null);

            // Component = the translated image; snapshot so it is the preview.
            $this->attachFile(
                $resource,
                (string) $image->getRealPath(),
                $image->getClientOriginalName() ?: 'translation',
                FileRole::COMPONENT,
                FileRelation::TRANSLATION,
                ['snapshot'],
            );

            $workspace->resources()->syncWithoutDetaching([$resource->id]);

            // Vault-purpose link (workspace-free key) — the same address form
            // reads mint via reachableProjectionVaults(), so the resource is
            // addressable through this vault the moment ingest returns.
            $link = $this->links->getOrCreateLink($vault, null, $resource->id, null);

            return [
                'hash' => $link->hash,
                'files' => 2,
            ];
        });
    }

    /**
     * The vault-configured landing spot. Both must exist and belong to the
     * vault's org — the consumer never chooses where its output lands.
     *
     * @return array{0: Workspace, 1: Collection}
     */
    private function ingestTarget(Vault $vault): array
    {
        $target = $vault->exposure_policy['ingest'] ?? null;
        $workspaceId = is_array($target) ? ($target['workspace_id'] ?? null) : null;
        $collectionId = is_array($target) ? ($target['collection_id'] ?? null) : null;

        if (! $workspaceId || ! $collectionId) {
            throw ValidationException::withMessages([
                'vault' => 'This vault has no ingest target — set exposure_policy.ingest.workspace_id and .collection_id.',
            ]);
        }

        $workspace = Workspace::where('organization_id', $vault->organization_id)->find($workspaceId);
        $collection = Collection::where('organization_id', $vault->organization_id)->find($collectionId);

        if (! $workspace || ! $collection) {
            throw ValidationException::withMessages([
                'vault' => 'The configured ingest workspace/collection was not found in this vault\'s organization.',
            ]);
        }

        return [$workspace, $collection];
    }

    /**
     * Attach a file from a local path to a resource via MediaLibrary and record
     * the File row (no request/user context — this runs behind the vault key).
     *
     * @param  list<string>|null  $usage
     */
    private function attachFile(
        Resource $resource,
        string $path,
        string $filename,
        FileRole $role,
        ?FileRelation $relation,
        ?array $usage,
    ): File {
        $media = $resource->addMedia($path)
            ->usingFileName($filename)
            ->withCustomProperties(['role' => $role->value])
            ->toMediaCollection('files');

        return $resource->files()->create([
            'user_owner_id' => $resource->user_owner_id,
            'filename' => $media->file_name,
            'mime_type' => $media->mime_type,
            'size' => $media->size,
            'role' => $role,
            'relation' => $relation,
            'usage' => $usage,
            'disk' => $media->disk,
            'path' => $media->getPathRelativeToRoot(),
            'media_id' => $media->id,
            'is_active' => true,
        ]);
    }
}
