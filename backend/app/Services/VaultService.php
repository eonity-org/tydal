<?php

namespace App\Services;

use App\Enums\VaultCapability;
use App\Jobs\RebuildVaultIndex;
use App\Models\Vault;
use App\Models\Workspace;
use App\Repositories\Interfaces\VaultRepositoryInterface;
use App\Services\Interfaces\VaultServiceInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

class VaultService implements VaultServiceInterface
{
    public function __construct(
        protected VaultRepositoryInterface $vaultRepository
    ) {}

    public function listVaults(string $search, int $perPage, int $page): LengthAwarePaginator
    {
        // Platform surface (superadmin): legitimately cross-org, so each row
        // carries its organization for display.
        $query = Vault::query()
            ->with(['organization:id,name,slug', 'workspaces:workspaces.id,workspaces.name,workspaces.is_system'])
            ->orderBy('name');

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'LIKE', "%{$search}%")
                    ->orWhere('slug', 'LIKE', "%{$search}%");
            });
        }

        return $query->paginate($perPage, ['*'], 'page', $page);
    }

    public function getVaultById(string $id): ?Vault
    {
        return Vault::find($id);
    }

    public function createVault(array $data): Vault
    {
        return Vault::create($data);
    }

    public function updateVault(string $id, array $data): ?Vault
    {
        $vault = Vault::find($id);
        if (! $vault) {
            return null;
        }
        $vault->update($data);

        return $vault->fresh();
    }

    public function deleteVault(string $id): bool
    {
        $vault = Vault::find($id);
        if (! $vault) {
            return false;
        }

        return $vault->delete();
    }

    public function getVaultsForWorkspace(string $workspaceId): Collection
    {
        $workspace = Workspace::find($workspaceId);
        if (! $workspace) {
            return new Collection;
        }

        // Each link says whether the vault controls it, so the workspace
        // selector can show it locked instead of failing on click.
        return $workspace->vaults()->get()->each(
            fn (Vault $vault) => $vault->setAttribute('association_lock', $this->associationLock($vault, $workspace, false)),
        );
    }

    public function attachVaultToWorkspace(string $workspaceId, string $vaultId): void
    {
        $workspace = Workspace::findOrFail($workspaceId);
        $vault = Vault::findOrFail($vaultId);

        // Tenancy boundary (spec §2): a workspace only feeds vaults of its
        // own organization — otherwise one org's resources would project
        // through another org's public boundary.
        if ($vault->organization_id !== $workspace->organization_id) {
            abort(422, 'Vault and workspace belong to different organizations');
        }
        if ($reason = $this->associationLock($vault, $workspace, true)) {
            abort(409, $reason);
        }

        $workspace->vaults()->syncWithoutDetaching([$vaultId]);

        // Membership changed → the vault's projected index is stale. Rebuild
        // it so search/browse reflect the new resource set (e.g. an
        // exhibition's opening swap). Non-delivery vaults only.
        if (! $vault->isDelivery()) {
            RebuildVaultIndex::dispatch($vault->id);
        }
    }

    public function detachVaultFromWorkspace(string $workspaceId, string $vaultId): void
    {
        $workspace = Workspace::findOrFail($workspaceId);
        $vault = Vault::find($vaultId);
        if ($vault && ($reason = $this->associationLock($vault, $workspace, false))) {
            abort(409, $reason);
        }

        $workspace->vaults()->detach($vaultId);

        if ($vault && ! $vault->isDelivery()) {
            RebuildVaultIndex::dispatch($vault->id);
        }
    }

    /**
     * When the vault itself controls a workspace link, so neither the
     * workspace selector nor the vault form may change it:
     *
     * - a system workspace (a gallery's `vault-{id}-selection`) is TYDAL's own
     *   bookkeeping, never edited by hand;
     * - while a gallery's selection is active, the opening decides what the
     *   vault shows (`activate` / `close` own the links until then);
     * - the vault's ingest target can't be removed while the vault accepts
     *   `ingest` — uploads would be refused as "not projected".
     */
    public function associationLock(Vault $vault, Workspace $workspace, bool $attach): ?string
    {
        if ($workspace->is_system) {
            return 'Managed by TYDAL — this workspace holds a gallery\'s selection.';
        }
        if ($vault->selection_snapshot !== null) {
            return 'This vault\'s published selection decides what it shows — close the exhibition first.';
        }
        $target = $vault->exposure_policy[VaultCapability::INGEST->value]['workspace_id'] ?? null;
        if (! $attach && $target !== null && (int) $target === (int) $workspace->id
            && $vault->allowsWriteMethod('ingest')) {
            return 'This is the vault\'s ingest target — uploads land here. Change the target first.';
        }

        return null;
    }

    /**
     * The vault form's side of the same links: set exactly which workspaces the
     * vault reads from. System workspaces are left as they are; every added or
     * removed link passes the same lock as the workspace selector, and the
     * projected index is rebuilt once.
     *
     * @param  list<int>  $workspaceIds
     */
    public function syncWorkspaces(Vault $vault, array $workspaceIds): void
    {
        $wanted = Workspace::where('organization_id', $vault->organization_id)
            ->where('is_system', false)
            ->whereIn('id', $workspaceIds)
            ->get()
            ->keyBy('id');

        if ($wanted->count() !== count(array_unique($workspaceIds))) {
            abort(422, 'Every workspace must belong to the vault\'s organization.');
        }

        $current = $vault->workspaces()->where('workspaces.is_system', false)->get()->keyBy('id');
        /** @var \Illuminate\Support\Collection<int, Workspace> $added */
        $added = $wanted->diffKeys($current);
        /** @var \Illuminate\Support\Collection<int, Workspace> $removed */
        $removed = $current->diffKeys($wanted);

        if ($added->isEmpty() && $removed->isEmpty()) {
            return;
        }
        $refuse = function (Workspace $workspace, bool $attach) use ($vault): void {
            if ($reason = $this->associationLock($vault, $workspace, $attach)) {
                abort(409, "{$workspace->name}: {$reason}");
            }
        };
        $added->each(fn (Workspace $workspace) => $refuse($workspace, true));
        $removed->each(fn (Workspace $workspace) => $refuse($workspace, false));

        $vault->workspaces()->syncWithoutDetaching($added->keys()->all());
        $vault->workspaces()->detach($removed->keys()->all());

        if (! $vault->isDelivery()) {
            RebuildVaultIndex::dispatch($vault->id);
        }
    }
}
