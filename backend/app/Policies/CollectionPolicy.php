<?php

namespace App\Policies;

use App\Models\Collection;
use App\Models\User;
use App\Policies\Concerns\ChecksOrganizationAccess;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * A collection carries a scheme (the field contract) and a search index, so
 * creating or deleting one is a structural act reserved to administrators.
 * Editing one is content work.
 */
class CollectionPolicy
{
    use ChecksOrganizationAccess, HandlesAuthorization;

    /**
     * Platform admins bypass the contextual checks. This hook was missing here
     * and on CategoryPolicy — the only two policies without it — which is why a
     * platform admin outside an organization was refused every collection.
     */
    public function before(User $user, string $ability): ?bool
    {
        return $this->isPlatformAdmin($user) ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $this->belongsToCurrentOrganization($user)
            && $user->can('collections.view');
    }

    public function view(User $user, Collection $collection): bool
    {
        return $this->belongsToCurrentOrganization($user)
            && $this->inCurrentOrganization($collection->organization_id)
            && $user->can('collections.view');
    }

    public function create(User $user): bool
    {
        return $this->belongsToCurrentOrganization($user)
            && $user->can('collections.create');
    }

    public function update(User $user, Collection $collection): bool
    {
        return $this->inCurrentOrganization($collection->organization_id)
            && $user->can('collections.update')
            && $this->ownsOrAdministers($user, $collection->user_owner_id);
    }

    public function delete(User $user, Collection $collection): bool
    {
        return $this->inCurrentOrganization($collection->organization_id)
            && $user->can('collections.delete')
            && $this->ownsOrAdministers($user, $collection->user_owner_id);
    }
}
