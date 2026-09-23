<?php

namespace App\Policies;

use App\Models\Resource;
use App\Models\User;
use App\Policies\Concerns\ChecksOrganizationAccess;
use Illuminate\Auth\Access\HandlesAuthorization;

class ResourcePolicy
{
    use ChecksOrganizationAccess, HandlesAuthorization;

    public function before(User $user, string $ability): ?bool
    {
        return $this->isPlatformAdmin($user) ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->can('resources.view');
    }

    public function view(User $user, Resource $resource): bool
    {
        // A resource in a deactivated collection is not readable by anyone.
        if ($resource->collection && ! $resource->collection->is_active) {
            return false;
        }

        return $user->can('resources.view')
            && $this->inCurrentOrganization($resource->organization_id);
    }

    public function create(User $user): bool
    {
        return $user->can('resources.create')
            && $this->belongsToCurrentOrganization($user);
    }

    /**
     * Authorship protects a resource from peers, not from the people who run
     * the organization.
     *
     * This previously required `user_owner_id === $user->id` unconditionally,
     * so an organization owner could not correct a colleague's resource — while
     * restore() and forceDelete() below already allowed exactly that. The two
     * halves now agree.
     */
    public function update(User $user, Resource $resource): bool
    {
        return $user->can('resources.update')
            && $this->inCurrentOrganization($resource->organization_id)
            && $this->ownsOrAdministers($user, $resource->user_owner_id);
    }

    public function delete(User $user, Resource $resource): bool
    {
        return $user->can('resources.delete')
            && $this->inCurrentOrganization($resource->organization_id)
            && $this->ownsOrAdministers($user, $resource->user_owner_id);
    }

    /**
     * Restore from the trash.
     *
     * The organization check is new. Without it an administrator of one
     * organization could restore — and, via forceDelete below, permanently
     * destroy — a trashed resource belonging to another organization, given
     * only its id. The list endpoint was scoped; the single-id endpoints were not.
     */
    public function restore(User $user, Resource $resource): bool
    {
        return $this->inCurrentOrganization($resource->organization_id)
            && $this->ownsOrAdministers($user, $resource->user_owner_id);
    }

    public function forceDelete(User $user, Resource $resource): bool
    {
        return $this->inCurrentOrganization($resource->organization_id)
            && $this->ownsOrAdministers($user, $resource->user_owner_id);
    }
}
