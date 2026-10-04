<?php

namespace App\Services;

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Interfaces\OrganizationRepositoryInterface;
use App\Services\Interfaces\OrganizationServiceInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class OrganizationService implements OrganizationServiceInterface
{
    protected OrganizationRepositoryInterface $organizationRepository;

    /**
     * OrganizationService constructor.
     */
    public function __construct(OrganizationRepositoryInterface $organizationRepository)
    {
        $this->organizationRepository = $organizationRepository;
    }

    /**
     * Get all organizations for the authenticated user.
     */
    public function getUserOrganizations(): Collection
    {
        $user = Auth::user();

        if (! $user) {
            return new Collection;
        }

        // Superadmins get all organizations
        if ($user->is_superadmin) {
            return Organization::all();
        }

        return $user->organizations()->get();
    }

    /**
     * Get all organizations.
     */
    public function getAllOrganizations(): Collection
    {
        return $this->organizationRepository->all();
    }

    /**
     * Get organization by ID.
     */
    public function getOrganizationById(string $id): ?Organization
    {
        return $this->organizationRepository->find($id);
    }

    /**
     * Get organization by slug.
     */
    public function getOrganizationBySlug(string $slug): ?Organization
    {
        return $this->organizationRepository->findBySlug($slug);
    }

    /**
     * Create a new organization, owned by `$owner` — else the signed-in user
     * (the API path). Whoever owns it is attached as `owner`, owns its default
     * workspace, and lands in it on their next login if they had nowhere yet.
     *
     * An owner is required: without one this used to write the organization's
     * own id into `workspaces.user_owner_id` (a users FK), so creating an
     * organization outside an HTTP request always failed.
     */
    public function createOrganization(array $data, ?User $owner = null): Organization
    {
        $owner ??= Auth::user();
        if (! $owner instanceof User) {
            throw new RuntimeException('An organization needs an owner: pass one, or create it as a signed-in user.');
        }

        return DB::transaction(function () use ($data, $owner): Organization {
            /** @var Organization $organization */
            $organization = $this->organizationRepository->create($data);

            // (This used to attach 'org-admin' AND pass an 'id' column that
            // `organization_user` does not have — its primary key is the
            // composite (organization_id, user_id) — so every call threw and
            // POST /organizations was simply broken.)
            $organization->users()->attach($owner->id, [
                'role' => OrganizationRole::OWNER->value,
            ]);

            // Create the default workspace (contains all org resources without explicit pivot)
            Workspace::create([
                'organization_id' => $organization->id,
                'user_owner_id' => $owner->id,
                'name' => 'All Resources',
                'slug' => $organization->slug.'-all',
                'description' => 'All resources in this organization',
                'is_active' => true,
                'is_default' => true,
                'is_system' => false,
            ]);

            if ($owner->last_organization_id === null) {
                $owner->forceFill(['last_organization_id' => $organization->id])->save();
            }

            return $organization;
        });
    }

    /**
     * Update an organization.
     */
    public function updateOrganization(string $id, array $data): ?Organization
    {
        return $this->organizationRepository->update($id, $data);
    }

    /**
     * Delete an organization.
     */
    public function deleteOrganization(string $id): bool
    {
        return $this->organizationRepository->delete($id);
    }

    /**
     * Get users in an organization.
     */
    public function getOrganizationUsers(string $organizationId): ?Collection
    {
        $organization = $this->getOrganizationById($organizationId);

        if (! $organization) {
            return null;
        }

        return $organization->users()->get();
    }
}
