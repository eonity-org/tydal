<?php

namespace App\Repositories;

use App\Models\Organization;
use App\Repositories\Interfaces\OrganizationRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

class OrganizationRepository extends BaseRepository implements OrganizationRepositoryInterface
{
    /**
     * OrganizationRepository constructor.
     */
    public function __construct(Organization $model)
    {
        parent::__construct($model);
    }

    /**
     * Find organization by slug.
     */
    public function findBySlug(string $slug): ?Organization
    {
        return Organization::where('slug', $slug)->first();
    }

    /**
     * Find active organization by ID.
     */
    public function findActive(string $id): ?Organization
    {
        return Organization::where('id', $id)
            ->where('is_active', true)
            ->first();
    }

    /**
     * Get all active organizations.
     */
    public function getAllActive(): Collection
    {
        return Organization::where('is_active', true)->get();
    }
}
