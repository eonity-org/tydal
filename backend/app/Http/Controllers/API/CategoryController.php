<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCategoryRequest;
use App\Http\Requests\UpdateCategoryRequest;
use App\Models\Category;
use App\Services\Interfaces\CategoryServiceInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    protected CategoryServiceInterface $categoryService;

    public function __construct(CategoryServiceInterface $categoryService)
    {
        $this->categoryService = $categoryService;
    }

    /**
     * Display a listing of categories for current organization.
     */
    public function index(Request $request): JsonResponse
    {
        // Check if user has an organization context
        if (! currentOrganizationId()) {
            return response()->json([
                'success' => false,
                'message' => 'No organization selected. Please create or select an organization first.',
                'error' => 'no_organization_context',
            ], 400);
        }

        $filters = $request->only(['collection_id', 'parent_id']);
        $perPage = $request->get('per_page', 20);
        $page = $request->get('page', 1);

        $categories = $this->categoryService->getCategories(
            currentOrganizationId(),
            $filters,
            $perPage,
            $page
        );

        return response()->json([
            'success' => true,
            'data' => [
                'categories' => $categories->items(),
            ],
            'meta' => [
                'pagination' => [
                    'current_page' => $categories->currentPage(),
                    'per_page' => $categories->perPage(),
                    'total' => $categories->total(),
                    'has_more' => $categories->hasMorePages(),
                ],
            ],
            'message' => 'Categories retrieved successfully',
        ]);
    }

    /**
     * Store a newly created category in storage.
     */
    public function store(StoreCategoryRequest $request): JsonResponse
    {
        // Authorized in StoreCategoryRequest::authorize(), before validation.
        $data = $request->validated();
        $data['organization_id'] = currentOrganizationId();
        $data['user_owner_id'] = auth()->id();

        $category = $this->categoryService->createCategory($data);

        return response()->json([
            'success' => true,
            'data' => [
                'category' => $category,
            ],
            'message' => 'Category created successfully',
        ], 201);
    }

    /**
     * Display the specified category.
     */
    public function show(string $id): JsonResponse
    {
        $category = $this->categoryService->getCategoryById($id);

        if (! $category) {
            return response()->json([
                'success' => false,
                'message' => 'Category not found',
            ], 404);
        }

        $this->authorize('view', $category);

        $category->load(['collection', 'parent', 'children', 'resources']);

        return response()->json([
            'success' => true,
            'data' => [
                'category' => $category,
            ],
            'message' => 'Category retrieved successfully',
        ]);
    }

    /**
     * Update the specified category in storage.
     */
    public function update(UpdateCategoryRequest $request, string $id): JsonResponse
    {
        $category = $this->categoryService->getCategoryById($id);

        if (! $category) {
            return response()->json([
                'success' => false,
                'message' => 'Category not found',
            ], 404);
        }

        $this->authorize('update', $category);

        $category = $this->categoryService->updateCategory($id, $request->validated());

        return response()->json([
            'success' => true,
            'data' => [
                'category' => $category,
            ],
            'message' => 'Category updated successfully',
        ]);
    }

    /**
     * Remove the specified category from storage.
     */
    public function destroy(string $id): JsonResponse
    {
        $category = $this->categoryService->getCategoryById($id);

        if (! $category) {
            return response()->json([
                'success' => false,
                'message' => 'Category not found',
            ], 404);
        }

        $this->authorize('delete', $category);

        $deleted = $this->categoryService->deleteCategory($id);

        return response()->json([
            'success' => $deleted,
            'message' => $deleted ? 'Category deleted successfully' : 'Failed to delete category',
        ]);
    }
}
