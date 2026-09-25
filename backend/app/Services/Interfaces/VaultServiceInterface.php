<?php

namespace App\Services\Interfaces;

use App\Models\Vault;
use App\Models\Workspace;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

interface VaultServiceInterface
{
    public function listVaults(string $search, int $perPage, int $page): LengthAwarePaginator;

    public function getVaultById(string $id): ?Vault;

    public function createVault(array $data): Vault;

    public function updateVault(string $id, array $data): ?Vault;

    public function deleteVault(string $id): bool;

    public function getVaultsForWorkspace(string $workspaceId): Collection;

    public function attachVaultToWorkspace(string $workspaceId, string $vaultId): void;

    public function detachVaultFromWorkspace(string $workspaceId, string $vaultId): void;

    /** Why this link can't be added (`$attach`) or removed right now, or null. */
    public function associationLock(Vault $vault, Workspace $workspace, bool $attach): ?string;

    /**
     * Set exactly which (non-system) workspaces the vault reads from.
     *
     * @param  list<int>  $workspaceIds
     */
    public function syncWorkspaces(Vault $vault, array $workspaceIds): void;
}
