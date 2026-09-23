<?php

namespace App\Services;

use App\Enums\ResourceState;
use App\Jobs\IndexAnnotationsToElasticsearch;
use App\Jobs\SyncResourcesToElasticsearch;
use App\Models\Workspace;
use App\Repositories\Interfaces\WorkspaceRepositoryInterface;
use App\Services\Interfaces\WorkspaceServiceInterface;
use Illuminate\Pagination\LengthAwarePaginator;

class WorkspaceService implements WorkspaceServiceInterface
{
    public function __construct(
        protected WorkspaceRepositoryInterface $workspaceRepository,
    ) {}

    /**
     * Get workspaces for an organization.
     *
     * System workspaces (is_system = true) are machine-managed — AITY review
     * batches, and vault writers such as the gallery `activate` op — so they are
     * omitted from the normal list, which feeds the workspace tabs and every
     * picker. They are not secret, though: their name shows on the cards of the
     * resources that belong to one, and an admin needs to be able to find out
     * what such a name is. `$includeSystem` is that door, opened only by the
     * workspace manager and only for org admins — see WorkspaceController::index.
     */
    public function getWorkspaces(string $organizationId, int $perPage, int $page, bool $includeSystem = false): LengthAwarePaginator
    {
        return Workspace::where('organization_id', $organizationId)
            ->when(! $includeSystem, fn ($q) => $q->where('is_system', false))
            ->withCount('resources')
            ->orderBy('created_at', 'desc')
            ->paginate($perPage, ['*'], 'page', $page);
    }

    /**
     * Get workspace by ID.
     */
    public function getWorkspaceById(string $id): ?Workspace
    {
        return Workspace::find($id);
    }

    /**
     * Create a new workspace.
     */
    public function createWorkspace(array $data): Workspace
    {
        return Workspace::create($data);
    }

    /**
     * Update a workspace.
     */
    public function updateWorkspace(string $id, array $data): ?Workspace
    {
        $workspace = $this->getWorkspaceById($id);

        if (! $workspace) {
            return null;
        }

        $nameChanged = isset($data['name']) && $data['name'] !== $workspace->name;

        $workspace->update($data);

        if ($nameChanged) {
            $workspace->resources()
                ->where('state', ResourceState::LIVE->value)
                ->select('resources.id')
                ->chunkById(100, function ($resources) {
                    foreach ($resources as $r) {
                        IndexAnnotationsToElasticsearch::dispatch($r->id);
                    }
                });
        }

        return $workspace->fresh();
    }

    /**
     * Delete a workspace.
     */
    /**
     * Delete a workspace, taking its members' search documents with it.
     *
     * Both `dam_resource_workspace` and `workspace_vault` cascade at the
     * DATABASE level, which bypasses Eloquent entirely: no pivot events, no
     * model events on the resources that just lost a membership, and therefore
     * no reindex. The rows vanished but every affected resource kept a stale
     * `workspace_ids` entry — and, because workspace membership is what grants
     * vault reachability, kept its document in the vault indexes that
     * membership used to justify. So the members are captured and detached
     * explicitly here, and reindexed after the delete.
     */
    public function deleteWorkspace(string $id): bool
    {
        $workspace = $this->getWorkspaceById($id);

        if (! $workspace) {
            return false;
        }

        $memberIds = $workspace->resources()->pluck('resources.id')->all();

        // Detach before the delete so the pivot is gone by the time the
        // reindex reads reachability, rather than relying on the FK cascade.
        if (! empty($memberIds)) {
            $workspace->resources()->detach($memberIds);
        }

        $deleted = $workspace->delete();

        if ($deleted && ! empty($memberIds)) {
            // Inline for a normal workspace so the dashboard's refetch right
            // after this call no longer shows the deleted workspace's chips;
            // queued only for batches too large to hold up the request.
            SyncResourcesToElasticsearch::run($memberIds);
        }

        return $deleted;
    }
}
