<?php

namespace App\Services\Interfaces;

use App\Models\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

interface CollectionServiceInterface
{
    /**
     * Get collections for an organization.
     */
    public function getCollections(string $organizationId, array $filters, int $perPage, int $page): LengthAwarePaginator;

    /**
     * Get collection by ID.
     */
    public function getCollectionById(string $id): ?Collection;

    /**
     * Create a new collection.
     */
    public function createCollection(array $data): Collection;

    /** The best search index the organization is entitled to, most specific first. */
    public function defaultIndexIdFor(?string $organizationId): ?string;

    /**
     * Update a collection.
     */
    public function updateCollection(string $id, array $data): ?Collection;

    /**
     * Delete a collection.
     */
    public function deleteCollection(string $id): bool;
}
