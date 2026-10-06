<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Workspace;
use App\Policies\Concerns\ChecksOrganizationAccess;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Auth\Access\Response;

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
 *
 * And one kind of membership change is a publishing decision after all:
 * adding to or removing from a workspace attached to a vault (manageResources).
 */
class WorkspacePolicy
{
    use ChecksOrganizationAccess, HandlesAuthorization;

    /** Deny code for manageResources' vault rule, and the bulk endpoints' skip reason. */
    public const VAULT_CONNECTED = 'vault_connected';

    public const VAULT_CONNECTED_MESSAGE = 'This workspace is shared through a vault. Ask an administrator to add or remove its resources.';

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

    /**
     * Add or remove resources — the single add/remove endpoints (the resource
     * editor's workspace chips, the upload wizard filling its AiTy batch) and
     * the basket's bulk membership actions.
     *
     * A workspace attached to a vault is a special case: its membership IS what
     * the vault shows outside TYDAL, so adding or removing a resource there
     * publishes or unpublishes it. That takes `workspaces.manage-vault-resources`
     * (admins and owners, through `workspaces.*`), on top of
     * `workspaces.manage-resources`. Vault write ops (gallery `activate`,
     * `ingest`) and graph:materialize write the pivot themselves with a key or
     * from the CLI and never reach this policy; AiTy Review batches are never
     * attached to a vault, so editors keep filling them.
     */
    public function manageResources(User $user, Workspace $workspace): Response|bool
    {
        if (! $this->inCurrentOrganization($workspace->organization_id)
            || ! $user->can('workspaces.manage-resources')) {
            return false;
        }

        if ($workspace->isVaultConnected() && ! $user->can('workspaces.manage-vault-resources')) {
            return Response::deny(self::VAULT_CONNECTED_MESSAGE, self::VAULT_CONNECTED);
        }

        return true;
    }

    public function delete(User $user, Workspace $workspace): bool
    {
        return $this->inCurrentOrganization($workspace->organization_id)
            && $user->can('workspaces.delete');
    }
}
