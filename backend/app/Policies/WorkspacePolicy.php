<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Workspace;
use App\Policies\Concerns\ChecksOrganizationAccess;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Workspace membership is what a vault projects, so creating and deleting
 * workspaces is an administrative act. Curating which resources sit in one is
 * content work, and editors may do it.
 *
 * One kind of workspace is not editorial: an AiTy Review batch
 * (`purpose = aity_review`), which the upload wizard's Auto mode opens to
 * group the files it just uploaded for background enrichment. It is
 * system-managed (`is_system`, hidden from the workspace list, never indexed
 * for a vault), so opening one is part of uploading, not a publishing
 * decision — whoever may create resources may open one.
 */
class WorkspacePolicy
{
    use ChecksOrganizationAccess, HandlesAuthorization;

    public function before(User $user, string $ability): ?bool
    {
        return $this->isPlatformAdmin($user) ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $this->belongsToCurrentOrganization($user)
            && $user->can('workspaces.view');
    }

    public function view(User $user, Workspace $workspace): bool
    {
        return $this->belongsToCurrentOrganization($user)
            && $this->inCurrentOrganization($workspace->organization_id)
            && $user->can('workspaces.view');
    }

    public function create(User $user): bool
    {
        return $this->belongsToCurrentOrganization($user)
            && $user->can('workspaces.create');
    }

    /**
     * Open an AiTy Review batch. Granted to anyone who may create resources
     * (editors and up) as well as anyone who may create workspaces at all.
     */
    public function createAityReviewBatch(User $user): bool
    {
        return $this->belongsToCurrentOrganization($user)
            && ($user->can('workspaces.create') || $user->can('resources.create'));
    }

    public function update(User $user, Workspace $workspace): bool
    {
        return $this->inCurrentOrganization($workspace->organization_id)
            && $user->can('workspaces.update');
    }

    /** Add or remove resources — the basket's bulk membership actions. */
    public function manageResources(User $user, Workspace $workspace): bool
    {
        return $this->inCurrentOrganization($workspace->organization_id)
            && $user->can('workspaces.manage-resources');
    }

    public function delete(User $user, Workspace $workspace): bool
    {
        return $this->inCurrentOrganization($workspace->organization_id)
            && $user->can('workspaces.delete');
    }
}
