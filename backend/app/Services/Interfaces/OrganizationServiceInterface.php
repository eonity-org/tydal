<?php

namespace App\Services\Interfaces;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Collection;

interface OrganizationServiceInterface
{
    /**
     * Get all organizations for the authenticated user.
     */
    public function getUserOrganizations(): Collection;

    /**
     * Get all organizations.
     */
    public function getAllOrganizations(): Collection;

    /**
     * Get organization by ID.
     */
    public function getOrganizationById(string $id): ?Organization;

    /**
     * Get organization by slug.
     */
    public function getOrganizationBySlug(string $slug): ?Organization;

    /**
     * Create a new organization.
     */
    public function createOrganization(array $data): Organization;

    /**
     * Update an organization.
     */
    public function updateOrganization(string $id, array $data): ?Organization;

    /**
     * Delete an organization.
     */
    public function deleteOrganization(string $id): bool;

    /**
     * Get users in an organization.
     */
    public function getOrganizationUsers(string $organizationId): ?Collection;
}
