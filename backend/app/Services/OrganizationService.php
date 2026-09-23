<?php

namespace App\Services;

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\Workspace;
use App\Repositories\Interfaces\OrganizationRepositoryInterface;
use App\Services\Interfaces\OrganizationServiceInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;

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
     * Create a new organization.
     */
    public function createOrganization(array $data): Organization
    {
        $organization = $this->organizationRepository->create($data);

        // Whoever creates an organization owns it.
        //
        // This used to attach them as 'org-admin' AND pass an 'id' column that
        // `organization_user` does not have — its primary key is the composite
        // (organization_id, user_id) — so every call threw
        // SQLSTATE[42S22] and POST /organizations was simply broken. That is
        // also why 'org-admin' never existed in any database despite policies
        // requiring it.
        if (Auth::check()) {
            $organization->users()->attach(Auth::id(), [
                'role' => OrganizationRole::OWNER->value,
            ]);
        }

        // Create the default workspace (contains all org resources without explicit pivot)
        Workspace::create([
            'organization_id' => $organization->id,
            'user_owner_id' => Auth::id() ?? $organization->id,
            'name' => 'All Resources',
            'slug' => $organization->slug.'-all',
            'description' => 'All resources in this organization',
            'is_active' => true,
            'is_default' => true,
            'is_system' => false,
        ]);

        return $organization;
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
