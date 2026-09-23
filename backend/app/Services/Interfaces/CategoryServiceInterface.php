<?php

namespace App\Services\Interfaces;

use App\Models\Category;
use Illuminate\Pagination\LengthAwarePaginator;

interface CategoryServiceInterface
{
    /**
     * Get categories for an organization.
     */
    public function getCategories(string $organizationId, array $filters, int $perPage, int $page): LengthAwarePaginator;

    /**
     * Get category by ID.
     */
    public function getCategoryById(string $id): ?Category;

    /**
     * Create a new category.
     */
    public function createCategory(array $data): Category;

    /**
     * Update a category.
     */
    public function updateCategory(string $id, array $data): ?Category;

    /**
     * Delete a category.
     */
    public function deleteCategory(string $id): bool;
}
