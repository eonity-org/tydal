<?php

namespace App\Http\Controllers\API;

use App\Enums\ResourceState;
use App\Http\Controllers\Controller;
use App\Http\Requests\AskCatalogueRequest;
use App\Models\Collection;
use App\Models\Resource;
use App\Models\SemanticTag;
use App\Models\Workspace;
use App\Services\ElasticsearchService;
use App\Services\Processing\Contracts\EmbeddingServiceInterface;
use App\Services\RagService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Catalogue Controller
 *
 * Serves the paginated resource list + facet aggregations for a collection.
 *
 * When the collection has a linked SearchIndex, Elasticsearch is used for
 * full-text search and facet aggregation (Phase C).
 * When no index is configured, the controller falls back to DB LIKE + GROUP BY (Phase B).
 *
 * GET /api/v1/catalogue/{id}
 *   ?page=1
 *   &limit=48
 *   &search=keyword
 *   &facets[language][]=en&facets[type][]=image
 *
 * Response:
 * {
 *   "data":         [...resources],
 *   "facets":       [{"key":"language","label":"Language","values":{"en":{"count":42}}}],
 *   "total":        50,
 *   "per_page":     48,
 *   "current_page": 1,
 *   "last_page":    2
 * }
 */
class CatalogueController extends Controller
{
    public function show(Request $request, int $id): JsonResponse
    {
        $collection = Collection::with(['scheme', 'searchIndex'])->find($id);

        if (! $collection) {
            return response()->json([
                'success' => false,
                'message' => 'Collection not found',
            ], 404);
        }

        $this->authorize('view', $collection);

        $schemeFields = $collection->scheme?->fields ?? [];
        $facetFields = array_values(array_filter($schemeFields, fn ($f) => $f['is_facet'] ?? false));

        $perPage = min((int) $request->input('limit', 48), 200);
        $page = max((int) $request->input('page', 1), 1);
        $search = (string) $request->input('search', '');
        $activeFacets = $request->input('facets', []);
        if (! is_array($activeFacets)) {
            $activeFacets = [];
        }
        $sortBy = in_array($request->input('sort_by'), ['name', 'updated_at', 'id']) ? $request->input('sort_by') : 'updated_at';
        $sortDir = $request->input('sort_dir', 'desc') === 'asc' ? 'asc' : 'desc';
        $searchMode = in_array($request->input('search_mode'), ['prefix', 'contains', 'exact'])
            ? $request->input('search_mode') : 'prefix';

        // ── Elasticsearch path (when an index is configured and query/facets are active) ──
        // Plain browsing (no search, no facets) uses DB directly to avoid ES indexing lag.
        if ($collection->searchIndex && ($search !== '' || ! empty($activeFacets))) {
            try {
                return $this->showViaElasticsearch(
                    $request, $collection, $facetFields, $search, $activeFacets, $page, $perPage, $sortBy, $sortDir, $searchMode
                );
            } catch (\Throwable $e) {
                // ES unavailable — transparently fall back to DB
                Log::warning("ES unavailable for collection {$collection->id}, falling back to DB: ".$e->getMessage());
            }
        }

        // ── DB fallback path ─────────────────────────────────────────────────
        return $this->showViaDatabase(
            $collection, $facetFields, $search, $activeFacets, $page, $perPage, $sortBy, $sortDir, $searchMode
        );
    }

    // -------------------------------------------------------------------------
    // Elasticsearch path
    // -------------------------------------------------------------------------

    private function showViaElasticsearch(
        Request $request,
        Collection $collection,
        array $facetFields,
        string $search,
        array $activeFacets,
        int $page,
        int $perPage,
        string $sortBy = 'updated_at',
        string $sortDir = 'desc',
        string $searchMode = 'prefix'
    ): JsonResponse {
        $es = app(ElasticsearchService::class);
        $indexName = $collection->searchIndex->index_name;

        // Build workspace name ↔ ID lookup maps for this organisation.
        // Excludes the default workspace (it holds everything, so it partitions
        // nothing) and system workspaces (machine-managed by AITY batches and
        // vault writers — membership there is a projection artifact, not a
        // curation axis a user chose). The ES facet is built by translating
        // bucket IDs through this map and dropping unknown ones, so leaving
        // them out here is also what keeps them out of the aggregation.
        $workspaces = Workspace::where('organization_id', $collection->organization_id)
            ->where('is_default', false)
            ->where('is_system', false)
            ->get(['id', 'name']);
        $wsNameToId = $workspaces->pluck('id', 'name')->all();   // ['Marketing' => 3, ...]
        $wsIdToName = $workspaces->pluck('name', 'id')->all();   // [3 => 'Marketing', ...]

        // Build semantic tag lookup maps for this organisation
        // $stIdToLabel maps id → ['label' => '...', 'entity_type' => '...'] for composite facet key rendering
        // $stCompositeToId maps "label||entity_type" → id for filter translation
        $semanticTags = SemanticTag::where('organization_id', $collection->organization_id)
            ->where('is_active', true)
            ->get(['id', 'label', 'entity_type']);
        $stIdToLabel = $semanticTags->mapWithKeys(fn ($t) => [$t->id => ['label' => $t->label, 'entity_type' => $t->entity_type]])->all();
        $stCompositeToId = $semanticTags->mapWithKeys(fn ($t) => [$t->label.'||'.($t->entity_type ?? '') => $t->id])->all();

        // Translate incoming workspace name filters to IDs before handing off to ES
        if (! empty($activeFacets['workspaces'])) {
            $activeFacets['workspaces'] = array_values(array_filter(
                array_map(fn ($name) => $wsNameToId[$name] ?? null, $activeFacets['workspaces']),
                fn ($id) => $id !== null
            ));
        }

        // Translate incoming semantic tag composite-key filters ("label||entity_type") to IDs
        if (! empty($activeFacets['semantic_tags'])) {
            $activeFacets['semantic_tags'] = array_values(array_filter(
                array_map(fn ($composite) => $stCompositeToId[$composite] ?? null, $activeFacets['semantic_tags']),
                fn ($id) => $id !== null
            ));
        }

        // Hybrid BM25 + k-NN is only meaningful for prefix (type-ahead) searches.
        // Exact and contains modes express lexical intent — semantic ranking would only
        // dilute precision, so they go straight to pure ES.
        $useHybrid = $searchMode === 'prefix' && $this->shouldUseHybridSearch($es, $indexName, $search);

        $result = $useHybrid
            ? $this->hybridSearch($es, $indexName, $collection->id, $search, $activeFacets, $facetFields, $page, $perPage, $wsIdToName, $stIdToLabel, $searchMode)
            : $es->searchResources($indexName, $collection->id, $search, $activeFacets, $facetFields, $page, $perPage, $sortBy, $sortDir, $searchMode, $wsIdToName, $stIdToLabel);

        // Fetch full resource records, preserving the order ES returned
        $resourcesById = Resource::whereIn('id', $result['ids'])
            ->where('state', ResourceState::LIVE->value)
            ->withCount(['files as files_count' => fn ($q) => $q->whereNull('uncommitted_at')])
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

    // -------------------------------------------------------------------------
    // RAG — question answering
    // -------------------------------------------------------------------------

    public function ask(AskCatalogueRequest $request, int $id): JsonResponse
    {
        $collection = Collection::with(['searchIndex'])->find($id);

        if (! $collection) {
            return response()->json(['success' => false, 'message' => 'Collection not found'], 404);
        }

        $this->authorize('view', $collection);

        if (! $collection->searchIndex) {
            return response()->json([
                'success' => false,
                'message' => 'This collection has no search index configured.',
            ], 422);
        }

        try {
            $result = app(RagService::class)->ask(
                $collection,
                $request->input('question'),
                $request->integer('k', 5)
            );

            return response()->json(['success' => true, 'data' => $result]);
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            Log::error("RAG ask failed for collection {$id}: ".$e->getMessage());

            return response()->json(['success' => false, 'message' => 'RAG service unavailable.'], 503);
        }
    }

    // -------------------------------------------------------------------------
    // Hybrid search helpers
    // -------------------------------------------------------------------------

    /**
     * Hybrid search is used when:
     *   1. The search query is non-empty (no point embedding an empty string)
     *   2. An embedding driver is configured (EMBEDDING_DRIVER is set)
     *   3. The chunks index exists in ES
     */
    private function shouldUseHybridSearch(ElasticsearchService $es, string $indexName, string $search): bool
    {
        if ($search === '') {
            return false;
        }

        if (! config('embedding.driver')) {
            return false;
        }

        try {
            $chunksIndex = $es->buildChunksIndexName($indexName);

            return $es->chunksIndexExists($chunksIndex);
        } catch (\Throwable) {
            return false;
        }
    }

    private function hybridSearch(
        ElasticsearchService $es,
        string $indexName,
        int $collectionId,
        string $search,
        array $activeFacets,
        array $facetFields,
        int $page,
        int $perPage,
        array $wsIdToName = [],
        array $stIdToLabel = [],
        string $searchMode = 'prefix'
    ): array {
        $embedder = app(EmbeddingServiceInterface::class);
        $queryVector = $embedder->embed($search);

        return $es->hybridSearch(
            $indexName, $collectionId, $search,
            $activeFacets, $facetFields, $page, $perPage,
            $queryVector, $wsIdToName, $stIdToLabel, $searchMode
        );
    }

    // -------------------------------------------------------------------------
    // DB fallback path
    // -------------------------------------------------------------------------

    private function showViaDatabase(
        Collection $collection,
        array $facetFields,
        string $search,
        array $activeFacets,
        int $page,
        int $perPage,
        string $sortBy = 'updated_at',
        string $sortDir = 'desc',
        string $searchMode = 'prefix'
    ): JsonResponse {
        $query = Resource::where('collection_id', $collection->id)
            ->where('state', ResourceState::LIVE->value)
            ->withCount(['files as files_count' => fn ($q) => $q->whereNull('uncommitted_at')])
            ->with(['snapshotFile.media', 'categories', 'semanticTags', 'workspaces', 'previewSnapshotSystemFile']);

        if ($search !== '') {
            $query->where(function ($q) use ($search, $searchMode) {
                $pattern = match ($searchMode) {
                    'exact' => $search,
                    default => "%{$search}%",  // prefix (Smart) degrades to contains in DB — no semantic engine
                };
                $op = $searchMode === 'exact' ? '=' : 'LIKE';
                $q->where('name', $op, $pattern)
                    ->orWhere('description', $op, $pattern);
            });
        }

        foreach ($activeFacets as $facetKey => $values) {
            if (! is_array($values) || empty($values)) {
                continue;
            }

            if ($facetKey === 'type') {
                $query->whereIn('type', $values);
            } elseif ($facetKey === 'workspaces') {
                // Same exclusions as the facet that offers these values, so a
                // hand-written ?facets[workspaces][]=… cannot filter by one that
                // is deliberately not on the list.
                $query->whereHas('workspaces', fn ($q) => $q->whereIn('workspaces.name', $values)
                    ->where('workspaces.is_default', false)
                    ->where('workspaces.is_system', false)
                );
            } elseif ($facetKey === 'semantic_tags') {
                // Values are composite "label||entity_type" strings — extract just the labels for the query
                $labels = array_map(fn ($v) => explode('||', $v)[0], $values);
                $query->whereHas('semanticTags', fn ($q) => $q->whereIn('semantic_tags.label', $labels)->where('semantic_tags.is_active', true)
                );
            } else {
                $query->where(function ($q) use ($facetKey, $values) {
                    foreach ($values as $value) {
                        $q->orWhereRaw(
                            'JSON_UNQUOTE(JSON_EXTRACT(metadata, ?)) = ?',
                            ['$.'.$facetKey, $value]
                        );
                    }
                });
            }
        }

        $total = $query->count();
        $resources = $query
            ->orderBy($sortBy, $sortDir)
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get();

        $facets = $this->buildDbFacets($collection->id, $facetFields);

        return response()->json([
            'data' => $resources,
            'facets' => $facets,
            'total' => $total,
            'per_page' => $perPage,
            'current_page' => $page,
            'last_page' => max(1, (int) ceil($total / $perPage)),
        ]);
    }

    // -------------------------------------------------------------------------
    // DB facet aggregation
    // -------------------------------------------------------------------------

    private function buildDbFacets(int $collectionId, array $facetFields): array
    {
        $facets = [];

        foreach ($facetFields as $field) {
            $fieldName = $field['name'];
            $label = $field['facet_label'] ?? ($field['display_name'] ?? ucfirst(str_replace('_', ' ', $fieldName)));
            $storage = $field['storage'] ?? 'metadata';

            if ($storage === 'column') {
                $rows = DB::table('resources')
                    ->select($fieldName, DB::raw('COUNT(*) as count'))
                    ->where('collection_id', $collectionId)
                    ->where('state', ResourceState::LIVE->value)
                    ->whereNull('deleted_at')
                    ->whereNotNull($fieldName)
                    ->groupBy($fieldName)
                    ->orderByDesc('count')
                    ->get();

                $values = [];
                foreach ($rows as $row) {
                    $val = $row->{$fieldName};
                    if ($val !== null && $val !== '') {
                        $values[(string) $val] = ['count' => $row->count];
                    }
                }
            } elseif ($storage === 'metadata') {
                $jsonPath = '$.'.$fieldName;
                $rows = DB::table('resources')
                    ->select(
                        DB::raw("JSON_UNQUOTE(JSON_EXTRACT(metadata, '{$jsonPath}')) as value"),
                        DB::raw('COUNT(*) as count')
                    )
                    ->where('collection_id', $collectionId)
                    ->where('state', ResourceState::LIVE->value)
                    ->whereNull('deleted_at')
                    ->whereRaw("JSON_EXTRACT(metadata, '{$jsonPath}') IS NOT NULL")
                    ->groupBy('value')
                    ->orderByDesc('count')
                    ->get();

                $values = [];
                foreach ($rows as $row) {
                    if ($row->value !== null && $row->value !== '') {
                        $values[$row->value] = ['count' => $row->count];
                    }
                }
            } else {
                continue;
            }

            if (! empty($values)) {
                $facets[] = [
                    'key' => $fieldName,
                    'label' => $label,
                    'values' => $values,
                ];
            }
        }

        // Workspace facet: count resources per curated workspace — neither the
        // default (holds everything) nor system ones (machine-managed).
        $wsRows = DB::table('dam_resource_workspace as rw')
            ->join('workspaces as w', 'w.id', '=', 'rw.workspace_id')
            ->join('resources as r', 'r.id', '=', 'rw.resource_id')
            ->where('r.collection_id', $collectionId)
            ->where('r.state', ResourceState::LIVE->value)
            ->whereNull('r.deleted_at')
            ->where('w.is_default', false)
            ->where('w.is_system', false)
            ->select('w.name', DB::raw('COUNT(*) as count'))
            ->groupBy('w.name')
            ->orderByDesc('count')
            ->get();

        if ($wsRows->isNotEmpty()) {
            $wsValues = [];
            foreach ($wsRows as $row) {
                $wsValues[$row->name] = ['count' => $row->count];
            }
            $facets[] = ['key' => 'workspaces', 'label' => 'Workspaces', 'values' => $wsValues];
        }

        // Semantic tags facet: count resources per active tag — composite key "label||entity_type"
        $stRows = DB::table('semantic_tag_resource as str')
            ->join('semantic_tags as st', 'st.id', '=', 'str.semantic_tag_id')
            ->join('resources as r', 'r.id', '=', 'str.resource_id')
            ->where('r.collection_id', $collectionId)
            ->where('r.state', ResourceState::LIVE->value)
            ->whereNull('r.deleted_at')
            ->where('st.is_active', true)
            ->select('st.label', 'st.entity_type', DB::raw('COUNT(*) as count'))
            ->groupBy('st.label', 'st.entity_type')
            ->orderByDesc('count')
            ->get();

        if ($stRows->isNotEmpty()) {
            $stValues = [];
            foreach ($stRows as $row) {
                $compositeKey = $row->label.'||'.($row->entity_type ?? '');
                $stValues[$compositeKey] = ['count' => $row->count];
            }
            $facets[] = ['key' => 'semantic_tags', 'label' => 'Tags', 'values' => $stValues];
        }

        return $facets;
    }
}
