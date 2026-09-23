<?php

namespace App\Services\Interfaces;

use App\Models\Workspace;
use Illuminate\Pagination\LengthAwarePaginator;

interface WorkspaceServiceInterface
{
    /**
     * Get workspaces for an organization.
     */
    public function getWorkspaces(string $organizationId, int $perPage, int $page, bool $includeSystem = false): LengthAwarePaginator;

    /**
     * Get workspace by ID.
     */
    public function getWorkspaceById(string $id): ?Workspace;

    /**
     * Create a new workspace.
     */
    public function createWorkspace(array $data): Workspace;

    /**
     * Update a workspace.
     */
    public function updateWorkspace(string $id, array $data): ?Workspace;

    /**
     * Delete a workspace.
     */
    public function deleteWorkspace(string $id): bool;
}
