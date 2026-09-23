<?php

namespace App\Http\Controllers\API;

use App\Enums\AityStatus;
use App\Enums\ResourceState;
use App\Http\Controllers\API\Concerns\RespondsToBulkActions;
use App\Http\Controllers\Controller;
use App\Http\Requests\AskWorkspaceRequest;
use App\Http\Requests\BulkResourceIdsRequest;
use App\Http\Requests\StoreWorkspaceRequest;
use App\Http\Requests\UpdateWorkspaceRequest;
use App\Jobs\IndexResourceToElasticsearch;
use App\Models\Collection;
use App\Models\Resource;
use App\Models\SemanticTag;
use App\Models\Workspace;
use App\Services\ElasticsearchService;
use App\Services\Interfaces\VaultServiceInterface;
use App\Services\Interfaces\WorkspaceServiceInterface;
use App\Services\RagService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WorkspaceController extends Controller
{
    use RespondsToBulkActions;

    protected WorkspaceServiceInterface $workspaceService;

    protected VaultServiceInterface $vaultService;

    public function __construct(WorkspaceServiceInterface $workspaceService, VaultServiceInterface $vaultService)
    {
        $this->workspaceService = $workspaceService;
        $this->vaultService = $vaultService;
    }

    /**
     * Display a listing of workspaces for current organization.
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = $request->get('per_page', 20);
        $page = $request->get('page', 1);

        // `?include_system=1` lets the workspace manager list the machine-managed
        // workspaces so an admin can see that they exist and what they are. It is
        // honoured only for org admins and above, and silently ignored otherwise
        // rather than refused — the flag is a listing preference, and the caller
        // still gets a valid list without it.
        $includeSystem = $request->boolean('include_system') && $this->maySeeSystemWorkspaces();

        $workspaces = $this->workspaceService->getWorkspaces(
            currentOrganizationId(),
            $perPage,
            $page,
            $includeSystem
        );

        return response()->json([
            'success' => true,
            'data' => [
                'workspaces' => $workspaces->items(),
            ],
            'meta' => [
                'pagination' => [
                    'current_page' => $workspaces->currentPage(),
                    'per_page' => $workspaces->perPage(),
                    'total' => $workspaces->total(),
                    'has_more' => $workspaces->hasMorePages(),
                ],
            ],
            'message' => 'Workspaces retrieved successfully',
        ]);
    }

    /**
     * Store a newly created workspace in storage.
     */
    public function store(StoreWorkspaceRequest $request): JsonResponse
    {
        $this->authorize('create', Workspace::class);

        $data = $request->validated();
        $data['organization_id'] = currentOrganizationId();
        $data['user_owner_id'] = auth()->id();

        // Batch upload workspaces are system-managed — hide from the normal workspace list
        if (($data['purpose'] ?? null) === 'aity_review') {
            $data['is_system'] = true;
        }

        $workspace = $this->workspaceService->createWorkspace($data);

        return response()->json([
            'success' => true,
            'data' => [
                'workspace' => $workspace,
            ],
            'message' => 'Workspace created successfully',
        ], 201);
    }

    /**
     * Display the specified workspace.
     */
    public function show(string $id): JsonResponse
    {
        $workspace = $this->workspaceService->getWorkspaceById($id);

        if (! $workspace) {
            return response()->json([
                'success' => false,
                'message' => 'Workspace not found',
            ], 404);
        }

        $this->authorize('view', $workspace);

        $workspace->load(['resources']);

        return response()->json([
            'success' => true,
            'data' => [
                'workspace' => $workspace,
            ],
            'message' => 'Workspace retrieved successfully',
        ]);
    }

    /**
     * Update the specified workspace in storage.
     */
    public function update(UpdateWorkspaceRequest $request, string $id): JsonResponse
    {
        $workspace = $this->workspaceService->getWorkspaceById($id);

        if (! $workspace) {
            return response()->json([
                'success' => false,
                'message' => 'Workspace not found',
            ], 404);
        }

        $this->authorize('update', $workspace);

        $workspace = $this->workspaceService->updateWorkspace($id, $request->validated());

        return response()->json([
            'success' => true,
            'data' => [
                'workspace' => $workspace,
            ],
            'message' => 'Workspace updated successfully',
        ]);
    }

    /**
     * Return a paginated, searchable, faceted list of resources in a workspace.
     *
     * Uses Elasticsearch when index data is available (non-default workspaces only).
     * Falls back to DB search for the default workspace or when ES is unavailable.
     */
    public function catalogue(Request $request, string $id): JsonResponse
    {
        $workspace = $this->workspaceService->getWorkspaceById($id);

        if (! $workspace) {
            return response()->json(['success' => false, 'message' => 'Workspace not found'], 404);
        }

        $this->authorize('view', $workspace);

        $perPage = min((int) $request->input('limit', 48), 200);
        $page = max((int) $request->input('page', 1), 1);
        $search = (string) $request->input('search', '');
        $activeFacets = $request->input('facets', []);
        if (! is_array($activeFacets)) {
            $activeFacets = [];
        }
        $sortBy = in_array($request->input('sort_by'), ['name', 'updated_at', 'id'])
            ? $request->input('sort_by') : 'updated_at';
        $sortDir = $request->input('sort_dir', 'desc') === 'asc' ? 'asc' : 'desc';
        $searchMode = in_array($request->input('search_mode'), ['prefix', 'contains', 'exact'])
            ? $request->input('search_mode') : 'prefix';

        // Default workspace spans all org resources — always use DB (no single index to query).
        // System workspaces are pivot-based queues; ES membership is never indexed for them.
        if (! $workspace->is_default && ! $workspace->is_system) {
            [$indexNames, $facetFields] = $this->resolveWorkspaceIndexData($workspace);

            if (! empty($indexNames)) {
                try {
                    return $this->catalogueViaElasticsearch(
                        $workspace, $indexNames, $facetFields,
                        $search, $activeFacets, $page, $perPage,
                        $sortBy, $sortDir, $searchMode
                    );
                } catch (\Throwable $e) {
                    Log::warning("ES unavailable for workspace {$workspace->id}, falling back to DB: ".$e->getMessage());
                }
            }
        }

        return $this->catalogueViaDatabase($workspace, $search, $sortBy, $sortDir, $searchMode, $page, $perPage);
    }

    /**
     * Answer a natural-language question using workspace-scoped RAG.
     *
     * POST /workspaces/{id}/ask
     * Body: { "question": "...", "k": 5 }
     */
    public function ask(AskWorkspaceRequest $request, string $id): JsonResponse
    {
        $workspace = $this->workspaceService->getWorkspaceById($id);

        if (! $workspace) {
            return response()->json(['success' => false, 'message' => 'Workspace not found'], 404);
        }

        $this->authorize('view', $workspace);

        try {
            $result = app(RagService::class)->askForWorkspace(
                $workspace,
                $request->input('question'),
                $request->integer('k', 5),
                $request->boolean('strict', false)
            );

            return response()->json(['success' => true, 'data' => $result]);
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            Log::error("RAG ask failed for workspace {$id}: ".$e->getMessage());

            return response()->json(['success' => false, 'message' => 'RAG service unavailable.'], 503);
        }
    }

    // -------------------------------------------------------------------------
    // Catalogue helpers
    // -------------------------------------------------------------------------

    /**
     * Resolve the ES index names and union of facet fields for a workspace.
     * Queries the workspace's current resource set to find which collections (and thus indices) it spans.
     *
     * @return array{0: string[], 1: array} [indexNames, facetFields]
     */
    private function resolveWorkspaceIndexData(Workspace $workspace): array
    {
        $collectionIds = $workspace->resources()
            ->distinct()
            ->pluck('collection_id');

        if ($collectionIds->isEmpty()) {
            return [[], []];
        }

        $collections = Collection::with(['searchIndex', 'scheme'])
            ->whereIn('id', $collectionIds)
            ->get();

        $indexNames = $collections
            ->filter(fn ($c) => $c->searchIndex !== null)
            ->pluck('searchIndex.index_name')
            ->unique()
            ->values()
            ->all();

        // Union of facet fields across all schemes, deduplicated by field name
        $facetFields = [];
        $seenFields = [];
        foreach ($collections as $collection) {
            foreach ($collection->scheme?->fields ?? [] as $field) {
                if (($field['is_facet'] ?? false) && ! isset($seenFields[$field['name']])) {
                    $facetFields[] = $field;
                    $seenFields[$field['name']] = true;
                }
            }
        }

        return [$indexNames, $facetFields];
    }

    private function catalogueViaElasticsearch(
        Workspace $workspace,
        array $indexNames,
        array $facetFields,
        string $search,
        array $activeFacets,
        int $page,
        int $perPage,
        string $sortBy,
        string $sortDir,
        string $searchMode
    ): JsonResponse {
        $es = app(ElasticsearchService::class);
        $orgId = $workspace->organization_id;

        // Build workspace name ↔ ID maps (used for facet translation)
        $workspaces = Workspace::where('organization_id', $orgId)->where('is_default', false)->get(['id', 'name']);
        $wsNameToId = $workspaces->pluck('id', 'name')->all();
        $wsIdToName = $workspaces->pluck('name', 'id')->all();

        // Build semantic tag maps
        $semanticTags = SemanticTag::where('organization_id', $orgId)->where('is_active', true)->get(['id', 'label', 'entity_type']);
        $stIdToLabel = $semanticTags->mapWithKeys(fn ($t) => [$t->id => ['label' => $t->label, 'entity_type' => $t->entity_type]])->all();
        $stCompositeToId = $semanticTags->mapWithKeys(fn ($t) => [$t->label.'||'.($t->entity_type ?? '') => $t->id])->all();

        // Translate incoming semantic tag composite-key filters to IDs
        if (! empty($activeFacets['semantic_tags'])) {
            $activeFacets['semantic_tags'] = array_values(array_filter(
                array_map(fn ($composite) => $stCompositeToId[$composite] ?? null, $activeFacets['semantic_tags']),
                fn ($id) => $id !== null
            ));
        }

        $result = $es->searchByWorkspace($indexNames, (int) $workspace->id, $search, $activeFacets, $facetFields, $page, $perPage, $sortBy, $sortDir, $searchMode, $wsIdToName, $stIdToLabel);

        $resourcesById = Resource::whereIn('id', $result['ids'])
            ->where('state', ResourceState::LIVE->value)
            ->withCount('files')
            ->with(['snapshotFile.media', 'categories', 'semanticTags', 'workspaces', 'previewSnapshotSystemFile'])
            ->get()
            ->keyBy('id');

        $resources = collect($result['ids'])
            ->map(fn ($id) => $resourcesById[$id] ?? null)
            ->filter()
            ->values();

        return response()->json([
            'data' => $resources,
            'facets' => $result['facets'],
            'total' => $result['total'],
            'per_page' => $perPage,
            'current_page' => $page,
            'last_page' => max(1, (int) ceil($result['total'] / $perPage)),
            'has_lexical_matches' => $result['has_lexical_matches'] ?? true,
        ]);
    }

    private function catalogueViaDatabase(
        Workspace $workspace,
        string $search,
        string $sortBy,
        string $sortDir,
        string $searchMode,
        int $page,
        int $perPage
    ): JsonResponse {
        $query = $workspace->is_default
            ? Resource::where('organization_id', $workspace->organization_id)
                ->where('state', ResourceState::LIVE->value)
                ->withCount('files')
                ->with(['snapshotFile.media', 'categories', 'semanticTags', 'previewSnapshotSystemFile'])
            : Resource::whereHas('workspaces', fn ($q) => $q->where('workspaces.id', $workspace->id))
                ->where('state', ResourceState::LIVE->value)
                ->withCount('files')
                ->with(['snapshotFile.media', 'categories', 'semanticTags', 'previewSnapshotSystemFile']);

        if ($search !== '') {
            $query->where(function ($q) use ($search, $searchMode) {
                $pattern = match ($searchMode) {
                    'exact' => $search,
                    default => "%{$search}%",  // prefix (Smart) degrades to contains in DB — no semantic engine
                };
                $op = $searchMode === 'exact' ? '=' : 'LIKE';
                $q->where('name', $op, $pattern)
                    ->orWhere('description', $op, $pattern)
                    ->orWhereHas('semanticTags', fn ($tq) => $tq->where('label', $op, $pattern));
            });
        }

        $total = $query->count();
        $resources = $query->orderBy($sortBy, $sortDir)->offset(($page - 1) * $perPage)->limit($perPage)->get();

        return response()->json([
            'data' => $resources,
            'facets' => [],
            'total' => $total,
            'per_page' => $perPage,
            'current_page' => $page,
            'last_page' => max(1, (int) ceil($total / $perPage)),
        ]);
    }

    /**
     * Add a resource to the workspace.
     */
    public function addResource(Request $request, string $id): JsonResponse
    {
        $workspace = $this->workspaceService->getWorkspaceById($id);

        if (! $workspace) {
            return response()->json(['success' => false, 'message' => 'Workspace not found'], 404);
        }

        $this->authorize('manageResources', $workspace);

        if ($workspace->is_default) {
            return response()->json(['success' => false, 'message' => 'Cannot manually add resources to the default workspace'], 422);
        }

        $request->validate([
            'resource_id' => 'required|exists:resources,id',
        ]);

        $resourceId = $request->input('resource_id');

        // Tenancy boundary (spec §2): workspace membership is what a vault
        // projects, so a foreign resource dropped into this workspace would
        // reach the outside through this org's boundary. Same rule as
        // attaching a vault — the membership edge never crosses orgs.
        $resource = Resource::select('id', 'organization_id')->find($resourceId);
        if (! $resource || $resource->organization_id !== $workspace->organization_id) {
            return response()->json([
                'success' => false,
                'message' => 'Resource and workspace belong to different organizations',
            ], 422);
        }

        $workspace->resources()->syncWithoutDetaching([$resourceId]);

        IndexResourceToElasticsearch::dispatchSync($resourceId);

        return response()->json([
            'success' => true,
            'message' => 'Resource added to workspace',
        ]);
    }

    /**
     * Remove a resource from the workspace.
     */
    public function removeResource(string $id, string $resourceId): JsonResponse
    {
        $workspace = $this->workspaceService->getWorkspaceById($id);

        if (! $workspace) {
            return response()->json(['success' => false, 'message' => 'Workspace not found'], 404);
        }

        $this->authorize('manageResources', $workspace);

        if ($workspace->is_default) {
            return response()->json(['success' => false, 'message' => 'Cannot manually remove resources from the default workspace'], 422);
        }

        $workspace->resources()->detach($resourceId);

        IndexResourceToElasticsearch::dispatchSync($resourceId);

        return response()->json([
            'success' => true,
            'message' => 'Resource removed from workspace',
        ]);
    }

    /**
     * May the caller be shown machine-managed workspaces?
     *
     * Owner/admin and platform superadmins — the same audience that can already
     * open the workspace manager. Everyone else keeps the filtered list.
     */
    private function maySeeSystemWorkspaces(): bool
    {
        return auth()->user()?->isSuperAdmin()
            || in_array(currentOrganizationRole(), ['owner', 'admin', 'org-admin'], true);
    }

    /**
     * Attach many resources to the workspace in one request.
     *
     * The dashboard basket's "add to workspace" — and, because workspace
     * membership is what a vault projects, the bulk publish gesture. Same
     * guards as addResource(); the difference is that the Elasticsearch
     * refresh is queued once for the batch instead of run inline per resource.
     */
    public function bulkAttachResources(BulkResourceIdsRequest $request, string $id): JsonResponse
    {
        return $this->bulkWorkspaceMembership($request, $id, attach: true);
    }

    /**
     * Detach many resources from the workspace in one request.
     */
    public function bulkDetachResources(BulkResourceIdsRequest $request, string $id): JsonResponse
    {
        return $this->bulkWorkspaceMembership($request, $id, attach: false);
    }

    /**
     * Shared body of the two bulk membership actions.
     *
     * Ids the caller may not act on are reported back as `skipped` rather than
     * failing the whole request: a basket gathered across collections routinely
     * holds a few resources the user cannot touch, and refusing all forty
     * because of two would be useless.
     */
    private function bulkWorkspaceMembership(BulkResourceIdsRequest $request, string $id, bool $attach): JsonResponse
    {
        $workspace = $this->workspaceService->getWorkspaceById($id);

        if (! $workspace) {
            return response()->json(['success' => false, 'message' => 'Workspace not found'], 404);
        }

        $this->authorize('manageResources', $workspace);

        if ($workspace->is_default) {
            return response()->json([
                'success' => false,
                'message' => $attach
                    ? 'Cannot manually add resources to the default workspace'
                    : 'Cannot manually remove resources from the default workspace',
            ], 422);
        }

        $requested = $request->resourceIds();

        // Tenancy boundary (spec §2), applied to the whole batch in one query:
        // membership is what a vault projects, so a foreign resource dropped in
        // here would reach the outside through this org's boundary.
        $allowed = Resource::whereIn('id', $requested)
            ->where('organization_id', $workspace->organization_id)
            ->pluck('id')
            ->all();

        $indexing = 'immediate';

        if (! empty($allowed)) {
            if ($attach) {
                $workspace->resources()->syncWithoutDetaching($allowed);
            } else {
                $workspace->resources()->detach($allowed);
            }

            // Pivot writes fire no model event, so the refresh is explicit —
            // once for the batch.
            $indexing = $this->syncResourceIndexes($allowed);
        }

        return $this->bulkResponse($requested, $allowed, 'foreign_organization', $indexing);
    }

    /**
     * List Vaults associated with a workspace.
     */
    public function listVaults(string $id): JsonResponse
    {
        $workspace = $this->workspaceService->getWorkspaceById($id);
        if (! $workspace) {
            return response()->json(['success' => false, 'message' => 'Workspace not found'], 404);
        }
        $this->authorize('view', $workspace);

        $vaults = $this->vaultService->getVaultsForWorkspace($id);

        return response()->json([
            'success' => true,
            'data' => ['vaults' => $vaults],
            'message' => 'Workspace Vaults retrieved successfully',
        ]);
    }

    /**
     * Associate a Vault with a workspace.
     */
    public function attachVault(Request $request, string $id): JsonResponse
    {
        $workspace = $this->workspaceService->getWorkspaceById($id);
        if (! $workspace) {
            return response()->json(['success' => false, 'message' => 'Workspace not found'], 404);
        }
        $this->authorize('update', $workspace);

        $request->validate(['vault_id' => 'required|exists:vaults,id']);

        $this->vaultService->attachVaultToWorkspace($id, $request->input('vault_id'));

        return response()->json(['success' => true, 'message' => 'Vault associated with workspace']);
    }

    /**
     * Remove a Vault association from a workspace.
     */
    public function detachVault(string $id, string $vaultId): JsonResponse
    {
        $workspace = $this->workspaceService->getWorkspaceById($id);
        if (! $workspace) {
            return response()->json(['success' => false, 'message' => 'Workspace not found'], 404);
        }
        $this->authorize('update', $workspace);

        $this->vaultService->detachVaultFromWorkspace($id, $vaultId);

        return response()->json(['success' => true, 'message' => 'Vault removed from workspace']);
    }

    /**
     * Return the AiTy processing readiness summary for a workspace.
     *
     * Used by the frontend to poll whether all resources have settled before
     * the auto-approve job picks them up, and to track the job's progress.
     *
     * GET /api/v1/workspaces/{id}/aity-status
     * Response: {
     *   workspace_id, total, processing, ready, is_ready,
     *   auto_approve_status, by_status: { [aity_status]: count }
     * }
     */
    public function aityStatus(string $id): JsonResponse
    {
        $orgId = currentOrganizationId();
        $workspace = Workspace::where('id', $id)->where('organization_id', $orgId)->first();

        if (! $workspace) {
            return response()->json(['success' => false, 'message' => 'Workspace not found.'], 404);
        }

        $byStatus = $workspace->resources()
            ->select('resources.aity_status', DB::raw('count(*) as cnt'))
            ->groupBy('resources.aity_status')
            ->pluck('cnt', 'resources.aity_status')
            ->toArray();

        $processingValues = [AityStatus::QUEUED->value, AityStatus::AITY_IN_PROGRESS->value];
        $processing = 0;
        foreach ($processingValues as $v) {
            $processing += (int) ($byStatus[$v] ?? 0);
        }
        $total = array_sum($byStatus);

        return response()->json([
            'success' => true,
            'data' => [
                'workspace_id' => (int) $id,
                'total' => $total,
                'processing' => $processing,
                'ready' => $total - $processing,
                'is_ready' => $processing === 0,
                'auto_approve_status' => $workspace->auto_approve_status,
                'by_status' => $byStatus,
            ],
        ]);
    }

    /**
     * List all AiTy batch workspaces for the current organization.
     *
     * Returns workspaces with purpose=aity_review ordered newest first, with
     * resource counts and aity processing progress baked in (no N+1 on the frontend).
     *
     * GET /api/v1/workspaces/aity-batches
     */
    public function aityBatches(Request $request): JsonResponse
    {
        $orgId = currentOrganizationId();
        $perPage = min((int) $request->input('per_page', 50), 200);
        $page = max((int) $request->input('page', 1), 1);

        $processingStatuses = [AityStatus::QUEUED->value, AityStatus::AITY_IN_PROGRESS->value];

        $batches = Workspace::where('organization_id', $orgId)
            ->where('purpose', 'aity_review')
            ->withCount('resources')
            ->withCount([
                'resources as aity_processing_count' => fn ($q) => $q->whereIn('resources.aity_status', $processingStatuses),
            ])
            // Total committed files across the batch's (non-trashed) resources. A canonical
            // resource is one row but N files, so this is what the user actually uploaded.
            ->addSelect(['files_count' => DB::table('files')
                ->selectRaw('COUNT(*)')
                ->join('dam_resource_workspace as rw', 'rw.resource_id', '=', 'files.resource_id')
                ->join('resources', 'resources.id', '=', 'files.resource_id')
                ->whereColumn('rw.workspace_id', 'workspaces.id')
                ->whereNull('files.uncommitted_at')
                ->whereNull('resources.deleted_at'),
            ])
            ->orderBy('created_at', 'desc')
            ->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'success' => true,
            'data' => [
                'batches' => $batches->items(),
            ],
            'meta' => [
                'pagination' => [
                    'current_page' => $batches->currentPage(),
                    'per_page' => $batches->perPage(),
                    'total' => $batches->total(),
                    'has_more' => $batches->hasMorePages(),
                ],
            ],
        ]);
    }

    /**
     * Mark an aity_review workspace as reviewed by the current user.
     * Idempotent — calling it again refreshes the timestamp.
     */
    public function markReviewed(string $id): JsonResponse
    {
        $workspace = Workspace::where('id', $id)
            ->where('organization_id', currentOrganizationId())
            ->first();

        if (! $workspace) {
            return response()->json(['success' => false, 'message' => 'Workspace not found'], 404);
        }

        $workspace->updateQuietly(['auto_approve_reviewed_at' => now()]);

        return response()->json(['success' => true, 'reviewed_at' => $workspace->auto_approve_reviewed_at]);
    }

    /**
     * Remove the specified workspace from storage.
     */
    public function destroy(string $id): JsonResponse
    {
        $workspace = $this->workspaceService->getWorkspaceById($id);

        if (! $workspace) {
            return response()->json([
                'success' => false,
                'message' => 'Workspace not found',
            ], 404);
        }

        $this->authorize('delete', $workspace);

        $deleted = $this->workspaceService->deleteWorkspace($id);

        return response()->json([
            'success' => $deleted,
            'message' => $deleted ? 'Workspace deleted successfully' : 'Failed to delete workspace',
        ]);
    }
}
