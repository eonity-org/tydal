<?php

namespace App\Http\Controllers\API;

use App\Enums\Visibility;
use App\Http\Controllers\Controller;
use App\Models\SearchIndex;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SearchIndexController extends Controller
{
    /**
     * List all search indexes.
     */
    public function index(Request $request): JsonResponse
    {
        // See CollectionSchemeController::index — same rule, but this one
        // matters more: an index restricted to one organization is a claim
        // about whose documents sit together, not just a menu entry.
        $indexes = SearchIndex::query()
            ->when($request->has('active'), function ($query) use ($request) {
                return $query->where('is_active', $request->boolean('active'));
            })
            ->unless(auth()->user()?->isSuperAdmin(), fn ($query) => $query->visibleTo(currentOrganizationId()))
            ->with('organizations:id,name')
            ->get();

        return response()->json([
            'success' => true,
            'data' => ['indexes' => $indexes],
            'message' => 'Search indexes retrieved successfully',
        ]);
    }

    /**
     * Get a specific search index.
     */
    public function show(string $id): JsonResponse
    {
        $index = SearchIndex::findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => ['index' => $index],
            'message' => 'Search index retrieved successfully',
        ]);
    }

    /**
     * Store a new search index.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'index_name' => 'required|string|max:255|unique:search_indexes,index_name',
            'display_name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'index_mappings' => 'nullable|array',
            'is_active' => 'boolean',
            'visibility' => ['sometimes', Rule::enum(Visibility::class)],
            'organization_ids' => 'sometimes|array',
            'organization_ids.*' => 'uuid|exists:organizations,id',
        ]);

        $index = SearchIndex::create(collect($validated)->except('organization_ids')->all());

        if ($request->filled('organization_ids')) {
            $index->organizations()->sync($validated['organization_ids']);
        }

        return response()->json([
            'success' => true,
            'data' => ['index' => $index->load('organizations:id,name')],
            'message' => 'Search index created successfully',
        ], 201);
    }

    /**
     * Update a search index.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $index = SearchIndex::findOrFail($id);

        $validated = $request->validate([
            'index_name' => 'sometimes|string|max:255|unique:search_indexes,index_name,'.$id,
            'display_name' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'index_mappings' => 'nullable|array',
            'is_active' => 'sometimes|boolean',
            'visibility' => ['sometimes', Rule::enum(Visibility::class)],
            'organization_ids' => 'sometimes|array',
            'organization_ids.*' => 'uuid|exists:organizations,id',
        ]);

        $index->update(collect($validated)->except('organization_ids')->all());

        if ($request->has('organization_ids')) {
            $index->organizations()->sync($validated['organization_ids'] ?? []);
        }

        return response()->json([
            'success' => true,
            'data' => ['index' => $index->fresh()->load('organizations:id,name')],
            'message' => 'Search index updated successfully',
        ]);
    }

    /**
     * Delete a search index.
     */
    public function destroy(string $id): JsonResponse
    {
        $index = SearchIndex::findOrFail($id);
        $index->delete();

        return response()->json([
            'success' => true,
            'message' => 'Search index deleted successfully',
        ]);
    }
}
