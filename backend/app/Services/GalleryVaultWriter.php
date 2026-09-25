<?php

namespace App\Services;

use App\Enums\FileRole;
use App\Enums\ResourceState;
use App\Enums\ResourceType;
use App\Enums\VaultState;
use App\Jobs\RebuildVaultIndex;
use App\Models\Vault;
use App\Models\VaultLink;
use App\Models\Workspace;
use App\Services\Interfaces\ResourceServiceInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The write methods a `gallery` vault exposes at the boundary
 * (VAULT_WRITE_METHODS.md §3/§6). Full Frame's opening flow used to drive these
 * mechanics over the org-wide management API with a Sanctum admin token; they
 * now live here, behind a vault-scoped write key, so the consumer declares
 * intent ("project these works") and never touches a workspace, an org, or a
 * resource UUID.
 *
 * Every method is transactional and returns a small structured summary the
 * controller records in the write audit.
 */
class GalleryVaultWriter
{
    public function __construct(
        private ResourceServiceInterface $resources,
        private VaultLinkService $links,
        private VaultIngest $ingest,
    ) {}

    /**
     * A gallery's `ingest` (VAULT_WRITE_METHODS.md §3): one photograph, which
     * becomes a live image resource in the vault's ingest target and its own
     * preview. The metadata document is already split by VaultIngest; the
     * gallery requires `name` (the author owns the title). The stored filename
     * is derived from it, so the uploader's filename (often a person's name)
     * never reaches TYDAL. Nothing here rewrites what the consumer sent — no AI
     * enrichment runs on it.
     *
     * Refused when the target workspace isn't projected by this vault — the
     * photo would be written but never shown (e.g. after an `activate`, whose
     * selection replaces the projection until `close`).
     *
     * @param  array{columns: array<string, string|null>, metadata: array<string, scalar|null>}  $document
     * @return array{hash: string, name: string}
     */
    public function ingest(Vault $vault, array $document, UploadedFile $image): array
    {
        $name = $document['columns']['name'] ?? null;
        if ($name === null) {
            throw ValidationException::withMessages(['metadata' => 'metadata.name (the title) is required.']);
        }

        [$workspace, $collection] = $this->ingest->target($vault);

        $scheme = $collection->scheme;
        $mime = (string) $image->getMimeType();
        if ($scheme && ! $scheme->acceptsMime($mime)) {
            throw ValidationException::withMessages([
                'image' => "File type {$mime} is not accepted by this vault's collection.",
            ]);
        }

        $projected = $vault->has_public_workspace
            || $vault->workspaces()->where('workspaces.id', $workspace->id)->exists();
        if (! $projected) {
            throw ValidationException::withMessages([
                'vault' => 'The ingest workspace is not projected by this vault right now, so the photograph would not appear.',
            ]);
        }

        return DB::transaction(function () use ($vault, $document, $name, $image, $workspace, $collection): array {
            $metadata = array_filter($document['metadata'], fn ($v) => $v !== null);

            $resource = $this->resources->createResource([
                'organization_id' => $vault->organization_id,
                'collection_id' => $collection->id,
                'user_owner_id' => $workspace->user_owner_id ?? $collection->user_owner_id,
                'type' => ResourceType::IMAGE->value,
                'name' => $name,
                'description' => $document['columns']['description'] ?? null,
                'metadata' => $metadata === [] ? null : $metadata,
                'state' => ResourceState::LIVE->value,
            ]);

            $extension = strtolower($image->getClientOriginalExtension() ?: ($image->guessExtension() ?? 'jpg'));
            $stem = Str::slug($name) ?: 'photograph';

            // Images are their own snapshot (the preview every card shows).
            $this->ingest->attachFile(
                $resource,
                (string) $image->getRealPath(),
                "{$stem}.{$extension}",
                FileRole::CANONICAL,
                null,
                ['snapshot'],
            );

            $workspace->resources()->syncWithoutDetaching([$resource->id]);

            // Re-index now that the workspace edge exists — the create-time
            // index ran before it, so the vault's projected index missed it.
            $resource->touch();

            $link = $this->links->getOrCreateLink($vault, null, $resource->id, null);

            return ['hash' => $link->hash, 'name' => $name];
        });
    }

    /**
     * Project exactly the given works — declarative replace. Non-listed works
     * simply stop resolving (projection-pure revocation). The references are
     * vault link hashes; any hash that does not resolve to a resource in THIS
     * vault fails the whole call (all-or-nothing), so a partial projection can
     * never be written.
     *
     * @param  list<string>  $linkHashes
     * @return array{activated: int, hashes: list<string>}
     */
    public function activate(Vault $vault, array $linkHashes): array
    {
        $hashes = array_values(array_unique($linkHashes));

        return DB::transaction(function () use ($vault, $hashes): array {
            // Resolve hash → resource, scoped to this vault. A link belongs to
            // exactly one vault (VAULT_SYSTEM.md §6), so this cannot reach
            // across the boundary; the org pin in vaultResourceQuery is the
            // second, structural guard.
            $links = VaultLink::where('vault_id', $vault->id)
                ->whereIn('hash', $hashes)
                ->get(['hash', 'resource_id']);

            $missing = array_diff($hashes, $links->pluck('hash')->all());
            if ($missing !== []) {
                throw ValidationException::withMessages([
                    'resources' => 'Unknown link hash(es) for this vault: '.implode(', ', $missing),
                ]);
            }

            $resourceIds = $links->pluck('resource_id')->unique()->values();

            $selection = $this->selectionWorkspace($vault);
            $selection->resources()->sync($resourceIds);

            // Snapshot the pre-selection projection on the FIRST activate only,
            // so a re-cut (activate again) doesn't overwrite the true baseline
            // that close() must restore.
            if ($vault->selection_snapshot === null) {
                $vault->selection_snapshot = [
                    'workspace_ids' => $vault->workspaces()
                        ->where('workspaces.id', '!=', $selection->id)
                        ->pluck('workspaces.id')->all(),
                    'has_public_workspace' => $vault->has_public_workspace,
                ];
            }

            // Narrow the projection to the selection: attach it, drop the rest,
            // and force membership to actually govern (a public-workspace vault
            // would otherwise project the whole org regardless of selection).
            $vault->has_public_workspace = false;
            $vault->save();

            $others = $vault->workspaces()
                ->where('workspaces.id', '!=', $selection->id)
                ->pluck('workspaces.id')->all();
            $vault->workspaces()->syncWithoutDetaching([$selection->id]);
            $vault->workspaces()->detach($others);

            RebuildVaultIndex::dispatch($vault->id);

            return ['activated' => $resourceIds->count(), 'hashes' => $hashes];
        });
    }

    /**
     * Publish: private → public. The address alone resolves after this; before
     * it, a key or signed grant was required (VAULT_SYSTEM.md §1).
     *
     * @return array{state: string}
     */
    public function open(Vault $vault): array
    {
        $vault->update(['state' => VaultState::PUBLIC->value]);

        return ['state' => VaultState::PUBLIC->value];
    }

    /**
     * Reverse the opening: back to private, and restore the full submission
     * projection captured at the first activate (the selection workspace is
     * detached, the original workspace set re-attached). Idempotent — a vault
     * that was never activated just goes private.
     *
     * @return array{state: string, restored: int}
     */
    public function close(Vault $vault): array
    {
        return DB::transaction(function () use ($vault): array {
            $snapshot = $vault->selection_snapshot;
            $restored = 0;

            if ($snapshot !== null) {
                $selection = $this->selectionWorkspace($vault);
                $vault->workspaces()->detach($selection->id);

                $ids = $snapshot['workspace_ids'] ?? [];
                if ($ids !== []) {
                    $vault->workspaces()->syncWithoutDetaching($ids);
                    $restored = count($ids);
                }

                $vault->has_public_workspace = (bool) ($snapshot['has_public_workspace'] ?? false);
                $vault->selection_snapshot = null;
            }

            $vault->state = VaultState::PRIVATE;
            $vault->save();

            RebuildVaultIndex::dispatch($vault->id);

            return ['state' => VaultState::PRIVATE->value, 'restored' => $restored];
        });
    }

    /**
     * The dedicated, per-vault workspace that holds the activated set. Keyed by
     * a deterministic slug so re-cuts and close() find the same one; it is an
     * internal, system-owned artifact, never surfaced to the consumer.
     *
     * Its owner is inherited from an existing workspace in the vault's org
     * (workspaces require a user_owner_id, and a gallery being activated always
     * has its submission workspace present) — the writer never invents a user.
     */
    private function selectionWorkspace(Vault $vault): Workspace
    {
        $ownerId = $vault->workspaces()->value('workspaces.user_owner_id')
            ?? Workspace::where('organization_id', $vault->organization_id)->value('user_owner_id');

        if ($ownerId === null) {
            throw ValidationException::withMessages([
                'vault' => 'This vault has no workspace to inherit an owner from — nothing to project.',
            ]);
        }

        return Workspace::firstOrCreate(
            ['organization_id' => $vault->organization_id, 'slug' => "vault-{$vault->id}-selection"],
            [
                'name' => "{$vault->name} — selection",
                'user_owner_id' => $ownerId,
                'is_default' => false,
                'is_system' => true,
                'is_active' => true,
            ],
        );
    }
}
