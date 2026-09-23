<?php

namespace App\Services;

use App\Models\Category;
use App\Repositories\Interfaces\CategoryRepositoryInterface;
use App\Services\Interfaces\CategoryServiceInterface;
use Illuminate\Pagination\LengthAwarePaginator;

class CategoryService implements CategoryServiceInterface
{
    protected CategoryRepositoryInterface $categoryRepository;

    public function __construct(CategoryRepositoryInterface $categoryRepository)
    {
        $this->categoryRepository = $categoryRepository;
    }

    /**
     * Get categories for an organization.
     */
    public function getCategories(string $organizationId, array $filters, int $perPage, int $page): LengthAwarePaginator
    {
        $query = Category::where('organization_id', $organizationId);

        // Apply filters
        if (isset($filters['collection_id'])) {
            $query->where('collection_id', $filters['collection_id']);
        }

        if (isset($filters['parent_id'])) {
            $query->where('parent_id', $filters['parent_id']);
        } else {
            // Get root categories only if no parent_id filter
            $query->whereNull('parent_id');
        }

        return $query->orderBy('created_at', 'desc')->paginate($perPage, ['*'], 'page', $page);
    }

    /**
     * Get category by ID.
     */
    public function getCategoryById(string $id): ?Category
    {
        return Category::find($id);
    }

    /**
     * Create a new category.
     */
    public function createCategory(array $data): Category
    {
        return Category::create($data);
    }

    /**
     * Update a category.
     */
    public function updateCategory(string $id, array $data): ?Category
    {
        $category = $this->getCategoryById($id);

        if (! $category) {
            return null;
        }

        $category->update($data);

        return $category->fresh();
    }

    /**
     * Delete a category.
     */
    public function deleteCategory(string $id): bool
    {
        $category = $this->getCategoryById($id);

        if (! $category) {
            return false;
        }

        return $category->delete();
    }
}
