<?php

namespace App\Services;

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
        $query = Vault::query()->with('organization:id,name,slug')->orderBy('name');

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

        return $workspace->vaults()->get();
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
        $workspace->vaults()->detach($vaultId);

        $vault = Vault::find($vaultId);
        if ($vault && ! $vault->isDelivery()) {
            RebuildVaultIndex::dispatch($vault->id);
        }
    }
}
