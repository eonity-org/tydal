<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCollectionRequest;
use App\Http\Requests\UpdateCollectionRequest;
use App\Models\Collection;
use App\Models\CollectionScheme;
use App\Services\Interfaces\CollectionServiceInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CollectionController extends Controller
{
    protected CollectionServiceInterface $collectionService;

    public function __construct(CollectionServiceInterface $collectionService)
    {
        $this->collectionService = $collectionService;
    }

    /**
     * Display a listing of collections for current organization.
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

        $filters = $request->only(['type']);
        $perPage = $request->get('per_page', 20);
        $page = $request->get('page', 1);

        $collections = $this->collectionService->getCollections(
            currentOrganizationId(),
            $filters,
            $perPage,
            $page
        );

        return response()->json([
            'success' => true,
            'data' => [
                'collections' => $collections->items(),
            ],
            'meta' => [
                'pagination' => [
                    'current_page' => $collections->currentPage(),
                    'per_page' => $collections->perPage(),
                    'total' => $collections->total(),
                    'has_more' => $collections->hasMorePages(),
                ],
            ],
            'message' => 'Collections retrieved successfully',
        ]);
    }

    /**
     * Store a newly created collection in storage.
     */
    public function store(StoreCollectionRequest $request): JsonResponse
    {
        $this->authorize('create', Collection::class);

        $organization = currentOrganization();

        if (! $organization) {
            return response()->json([
                'success' => false,
                'message' => 'No organization selected.',
            ], 400);
        }

        // A collection pins a field contract, a search index and the facets of
        // everything in it, so self-service creation is capped. Platform
        // administrators are exempt — the cap governs a tenant's own people,
        // not the people running the installation.
        if (! auth()->user()?->isSuperAdmin() && $organization->hasReachedCollectionQuota()) {
            return response()->json([
                'success' => false,
                'message' => "This organization has reached its limit of {$organization->collectionQuota()} collections. A platform administrator can raise it.",
            ], 422);
        }

        $data = $request->validated();

        // The scheme has to be one this organization is actually offered —
        // otherwise the visibility list is only a menu, not a rule.
        $scheme = CollectionScheme::find($data['scheme_id'] ?? null);
        if ($scheme && ! auth()->user()?->isSuperAdmin() && ! $scheme->isVisibleTo($organization->id)) {
            return response()->json([
                'success' => false,
                'message' => 'That scheme is not available to this organization.',
            ], 403);
        }

        $data['organization_id'] = $organization->id;
        $data['user_owner_id'] = auth()->id();

        $collection = $this->collectionService->createCollection($data);

        return response()->json([
            'success' => true,
            'data' => [
                'collection' => $collection,
            ],
            'message' => 'Collection created successfully',
        ], 201);
    }

    /**
     * Display the specified collection.
     */
    public function show(string $id): JsonResponse
    {
        $collection = $this->collectionService->getCollectionById($id);

        if (! $collection) {
            return response()->json([
                'success' => false,
                'message' => 'Collection not found',
            ], 404);
        }

        $this->authorize('view', $collection);

        $collection->load(['resources', 'categories', 'scheme']);

        return response()->json([
            'success' => true,
            'data' => [
                'collection' => $collection,
            ],
            'message' => 'Collection retrieved successfully',
        ]);
    }

    /**
     * Update the specified collection in storage.
     */
    public function update(UpdateCollectionRequest $request, string $id): JsonResponse
    {
        $collection = $this->collectionService->getCollectionById($id);

        if (! $collection) {
            return response()->json([
                'success' => false,
                'message' => 'Collection not found',
            ], 404);
        }

        $this->authorize('update', $collection);

        $collection = $this->collectionService->updateCollection($id, $request->validated());

        return response()->json([
            'success' => true,
            'data' => [
                'collection' => $collection,
            ],
            'message' => 'Collection updated successfully',
        ]);
    }

    /**
     * Remove the specified collection from storage.
     */
    public function destroy(string $id): JsonResponse
    {
        $collection = $this->collectionService->getCollectionById($id);

        if (! $collection) {
            return response()->json([
                'success' => false,
                'message' => 'Collection not found',
            ], 404);
        }

        $this->authorize('delete', $collection);

        $deleted = $this->collectionService->deleteCollection($id);

        return response()->json([
            'success' => $deleted,
            'message' => $deleted ? 'Collection deleted successfully' : 'Failed to delete collection',
        ]);
    }
}
