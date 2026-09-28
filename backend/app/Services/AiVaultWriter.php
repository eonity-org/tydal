<?php

namespace App\Services;

use App\Enums\FileRelation;
use App\Enums\FileRole;
use App\Enums\ResourceState;
use App\Enums\ResourceType;
use App\Models\Vault;
use App\Services\Interfaces\ResourceServiceInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * How an `ai` vault stores an `ingest` (VAULT_WRITE_METHODS.md §7): a
 * *derived artifact* produced by an external AI — a translated image plus a
 * JSON descriptor document (tables/graphs/formulae) — materialized as an
 * **output resource** the vault owner can then export (typically through a
 * companion `delivery` vault) to a downstream renderer.
 *
 * The landing spot, the metadata document, `update` and `withdraw` are shared
 * by every purpose (VaultIngest); only the file composition lives here.
 */
class AiVaultWriter
{
    public function __construct(
        private ResourceServiceInterface $resources,
        private VaultLinkService $links,
        private VaultIngest $ingest,
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
     * The metadata document (already split by VaultIngest) is stored as
     * given — `name`/`description` on the resource, the rest (e.g.
     * `source_hash`) in its metadata.
     *
     * @param  array{columns: array<string, string|null>, metadata: array<string, scalar|null>}  $document
     * @param  array<array-key, mixed>  $descriptor
     * @return array{hash: string, files: int}
     */
    public function ingest(Vault $vault, array $document, array $descriptor, UploadedFile $image): array
    {
        [$workspace, $collection] = $this->ingest->target($vault);

        return DB::transaction(function () use ($vault, $document, $descriptor, $image, $workspace, $collection): array {
            $metadata = array_filter($document['metadata'], fn ($v) => $v !== null);

            $ownerId = $workspace->user_owner_id ?? $collection->user_owner_id;

            $resource = $this->resources->createResource([
                'organization_id' => $vault->organization_id,
                'collection_id' => $collection->id,
                'user_owner_id' => $ownerId,
                'type' => ResourceType::DOCUMENT->value,
                'name' => $document['columns']['name'] ?? 'Ingested figure',
                'description' => $document['columns']['description'] ?? null,
                'state' => ResourceState::LIVE->value,
                'metadata' => $metadata === [] ? null : $metadata,
            ]);

            // Canonical = the JSON descriptor (what the renderer addresses).
            $jsonPath = tempnam(sys_get_temp_dir(), 'ingest').'.json';
            file_put_contents(
                $jsonPath,
                json_encode($descriptor, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            );
            $this->ingest->attachFile($resource, $jsonPath, 'descriptor.json', FileRole::CANONICAL, null, null);

            // Component = the translated image; snapshot so it is the preview.
            $this->ingest->attachFile(
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
}
