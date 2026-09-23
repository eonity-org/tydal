<?php

namespace App\Policies;

use App\Models\Category;
use App\Models\User;
use App\Policies\Concerns\ChecksOrganizationAccess;
use Illuminate\Auth\Access\HandlesAuthorization;

class CategoryPolicy
{
    use ChecksOrganizationAccess, HandlesAuthorization;

    /** See CollectionPolicy::before — this hook was missing here too. */
    public function before(User $user, string $ability): ?bool
    {
        return $this->isPlatformAdmin($user) ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $this->belongsToCurrentOrganization($user)
            && $user->can('categories.view');
    }

    public function view(User $user, Category $category): bool
    {
        return $this->belongsToCurrentOrganization($user)
            && $this->inCurrentOrganization($category->organization_id)
            && $user->can('categories.view');
    }

    public function create(User $user): bool
    {
        return $this->belongsToCurrentOrganization($user)
            && $user->can('categories.create');
    }

    public function update(User $user, Category $category): bool
    {
        return $this->inCurrentOrganization($category->organization_id)
            && $user->can('categories.update')
            && $this->ownsOrAdministers($user, $category->user_owner_id);
    }

    public function delete(User $user, Category $category): bool
    {
        return $this->inCurrentOrganization($category->organization_id)
            && $user->can('categories.delete')
            && $this->ownsOrAdministers($user, $category->user_owner_id);
    }
}
