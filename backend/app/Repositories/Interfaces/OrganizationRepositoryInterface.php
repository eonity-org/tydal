<?php

namespace App\Repositories\Interfaces;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Collection;

interface OrganizationRepositoryInterface extends BaseRepositoryInterface
{
    /**
     * Find organization by slug.
     */
    public function findBySlug(string $slug): ?Organization;

    /**
     * Find active organization by ID.
     */
    public function findActive(string $id): ?Organization;

    /**
     * Get all active organizations.
     */
    public function getAllActive(): Collection;
}
