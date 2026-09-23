<?php

namespace App\Services;

use App\Enums\ResourceState;
use App\Models\File;
use App\Models\Resource;
use App\Models\ResourceRelation;
use App\Models\Vault;
use App\Models\VaultLink;
use App\Services\Processing\Contracts\EmbeddingServiceInterface;
use App\Services\Processing\VisionImagePreparer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * The tier-gated operation grammar (VAULT_SYSTEM.md §5) — one set of payload
 * builders shared by both address forms (/v/ slugs and /h/ hashes) and, later,
 * the vault MCP tools (Epic 2.4, REST ↔ MCP 1:1).
 *
 * Tier gates (§6.2/§6.3) live on the Vault model (allowsChunks/allowsBinary);
 * methods here return null when the vault's policy denies the tier —
 * controllers translate that into a 403.
 */
class VaultOperationService
{
    public function __construct(
        private readonly VaultLinkService $links,
        private readonly VaultSchemaResolver $schemaResolver,
    ) {}

    /** @var array<int|string, array<string, true>> per-vault card-exposed field names */
    private array $cardFieldCache = [];

    // -------------------------------------------------------------------------
    // Vault level (Tier 0)
    // -------------------------------------------------------------------------

    /**
     * Default GET on a vault — the Tier 0 index: paged identity cards,
     * navigable by tag/category.
     */
    public function vaultIndex(Vault $vault, int $page = 1, int $perPage = 20, ?string $tag = null, ?string $category = null): array
    {
        $query = $this->vaultResourceQuery($vault)->with('semanticTags');

        if ($tag !== null) {
            $query->whereHas('semanticTags', fn ($q) => $q->where('label', $tag));
        }

        if ($category !== null) {
            $query->whereHas('categories', fn ($q) => $q->where('slug', $category)->orWhere('name', $category));
        }

        $paginated = $query->orderBy('name')->paginate($perPage, ['*'], 'page', $page);
        [$cards] = $this->buildCards($vault, $paginated->getCollection());

        return [
            'type' => 'vault-index',
            'vault' => ['name' => $vault->name, 'slug' => $vault->slug, 'purpose' => $vault->purpose->value],
            'resources' => $cards,
            'pagination' => [
                'page' => $paginated->currentPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
                'has_more' => $paginated->hasMorePages(),
            ],
        ];
    }

    /**
     * The vault's projected graph (Tier 0): every projected resource as a
     * node card, every relation whose BOTH endpoints are projected as an
     * edge — cross-vault edges never leak. Obsidian-style apps render this
     * wall-to-wall; per-node neighborhoods stay on `/related`.
     */
    public function vaultGraph(Vault $vault, int $nodeCap = 300): array
    {
        $resources = $this->vaultResourceQuery($vault)
            ->with('semanticTags')
            ->orderBy('name')
            ->limit($nodeCap + 1)
            ->get();

        $truncated = $resources->count() > $nodeCap;
        $resources = $resources->take($nodeCap)->values();
        $ids = $resources->pluck('id');

        $edges = ResourceRelation::query()
            ->whereIn('subject_resource_id', $ids)
            ->whereIn('object_resource_id', $ids)
            ->get();

        // Bounded query count: buildCards batches links + snapshot loads. Edges
        // reference nodes by the same vault-scoped id the cards carry (the link
        // hash), not the raw resource UUID — otherwise the internal id would
        // leak through source/target and the client couldn't match edges.
        [$nodes, $links] = $this->buildCards($vault, $resources);
        $hashByResource = array_map(fn (VaultLink $l) => $l->hash, $links);

        return [
            'type' => 'vault-graph',
            'nodes' => $nodes,
            'edges' => $edges->map(fn (ResourceRelation $e) => [
                'source' => $hashByResource[$e->subject_resource_id] ?? null,
                'target' => $hashByResource[$e->object_resource_id] ?? null,
                'type' => $e->type->value,
                'origin' => $e->origin->value,
                'weight' => $e->weight,
            ])->filter(fn (array $edge) => $edge['source'] !== null && $edge['target'] !== null)
                ->values()
                ->all(),
            'truncated' => $truncated,
        ];
    }

    /**
     * Vault self-description — what an agent may do before trying (§7.1).
     */
    public function vaultMeta(Vault $vault): array
    {
        $vault->loadMissing('organization');

        return [
            'type' => 'vault',
            'name' => $vault->name,
            'slug' => $vault->slug,
            'hash' => $vault->hash,
            'description' => $vault->description,
            'purpose' => $vault->purpose->value,
            'organization' => $vault->organization->slug,
            'state' => $vault->state->value,
            'resource_count' => $this->vaultResourceQuery($vault)->count(),
            'tiers' => [
                'identity' => true,
                'chunks' => $vault->allowsChunks(),
                'binary' => $vault->allowsBinary(),
                // Reasoning is compute, not data — apps show a chat surface
                // only when the vault answers at the boundary.
                'ask' => $vault->allowsAsk(),
            ],
            'address_roles' => $vault->addressRoles(),
            'chunk_roles' => $vault->chunkRoles(),
            // The full matrix with provenance — `tiers` above stays the stable
            // contract every current consumer reads; this says *why* each value
            // is what it is (purpose preset vs. this vault's own override).
            'capabilities' => $vault->policy()->effective(),
            // Resolved semantic mapping (Epic 3.1) — a generic client renders
            // any vault from this block, never hardcoding field names
            'presentation' => $this->schemaResolver->presentationFor($vault),
            'operations' => [
                'vault' => ['meta', 'tags', 'resources', 'search', 'embed', 'graph', 'ask'],
                'resource' => ['meta', 'tags', 'files', 'chunks', 'links', 'related', 'preview'],
                'file' => ['meta', 'chunks', 'download', 'renditions'],
            ],
            'search_modes' => ['keyword', 'semantic'],
        ];
    }

    /**
     * Aggregated semantic tags across the vault's resources (Tier 0).
     */
    public function vaultTags(Vault $vault): array
    {
        $resourceIds = $this->vaultResourceQuery($vault)->select('resources.id');

        $tags = DB::table('semantic_tag_resource')
            ->whereIn('semantic_tag_resource.resource_id', $resourceIds)
            ->join('semantic_tags', 'semantic_tags.id', '=', 'semantic_tag_resource.semantic_tag_id')
            ->groupBy('semantic_tags.id', 'semantic_tags.label')
            ->selectRaw('semantic_tags.label as name, count(*) as count')
            ->orderByDesc('count')
            ->orderBy('name')
            ->get();

        return [
            'type' => 'vault-tags',
            'tags' => $tags->map(fn ($t) => ['name' => $t->name, 'count' => (int) $t->count])->all(),
        ];
    }

    /**
     * Search over the vault's identity surface (Tier 0). When the vault's
     * projected ES index is current (Epic 3.2) it serves the query — slot-
     * mapped metadata fields match and facet slots aggregate; otherwise the
     * DB keyword path answers (same rebuildable-cache philosophy as the
     * catalogue). `mode=semantic` (Epic 4.1) ranks by k-NN over the resource
     * mean embeddings instead — it needs both the vault index and a live
     * embedder, and degrades to keyword when either is missing (the answered
     * mode is reported in the payload). `$facets` (Epic 5.1) narrows results
     * to exact metadata values — the filter side of the returned facet
     * aggregations (`facet[field]=value` on the wire).
     *
     * @param  array<string, list<string>>  $facets  field → accepted values
     */
    public function vaultSearch(Vault $vault, string $q, int $page = 1, int $perPage = 20, string $mode = 'keyword', array $facets = []): array
    {
        if ($vault->indexed_at !== null) {
            $es = app(ElasticsearchService::class);
            $presentation = $this->schemaResolver->presentationFor($vault);
            $facetFields = $es->vaultFacetFields($presentation);

            // Only aggregating (facet-slot) fields are filterable — same contract
            // as the aggregations themselves
            $facets = array_intersect_key($facets, $facetFields);

            $queryVector = $mode === 'semantic' && $q !== '' ? $this->embedQueryVector($q) : null;

            $result = $queryVector !== null
                ? $es->knnSearchVaultIndex($vault, $queryVector, $page, $perPage, $facetFields, $facets)
                : $es->searchVaultIndex($vault, $q, $page, $perPage, $facetFields, $facets);

            return [
                'type' => 'vault-search',
                'query' => $q,
                'mode' => $queryVector !== null ? 'semantic' : 'keyword',
                'results' => $result['hits'],
                'facets' => $result['facets'],
                'pagination' => [
                    'page' => $page,
                    'per_page' => $perPage,
                    'total' => $result['total'],
                    'has_more' => $page * $perPage < $result['total'],
                ],
            ];
        }

        $query = $this->vaultResourceQuery($vault)
            ->with('semanticTags')
            ->where(function (Builder $sub) use ($q) {
                $sub->where('name', 'LIKE', "%{$q}%")
                    ->orWhere('description', 'LIKE', "%{$q}%")
                    ->orWhereHas('semanticTags', fn ($tq) => $tq->where('label', 'LIKE', "%{$q}%"));
            });

        foreach ($facets as $field => $values) {
            if (preg_match('/^[A-Za-z0-9_]+$/', $field) === 1) {
                $query->whereIn("metadata->{$field}", $values);
            }
        }

        $paginated = $query->orderBy('name')->paginate($perPage, ['*'], 'page', $page);
        [$cards] = $this->buildCards($vault, $paginated->getCollection());

        return [
            'type' => 'vault-search',
            'query' => $q,
            'mode' => 'keyword',
            'results' => $cards,
            'pagination' => [
                'page' => $paginated->currentPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
                'has_more' => $paginated->hasMorePages(),
            ],
        ];
    }

    /**
     * Tier 1 — search over chunk content, vault-scoped and role-filtered.
     * Null when the vault denies chunks. `mode=semantic` (Epic 4.1) ranks by
     * k-NN over the chunk vectors; embedder down degrades to keyword (the
     * answered mode is reported in the payload).
     */
    public function vaultSearchChunks(Vault $vault, string $q, int $limit = 20, string $mode = 'keyword'): ?array
    {
        if (! $vault->allowsChunks()) {
            return null;
        }

        $resources = $this->vaultResourceQuery($vault)
            ->with('collection.searchIndex')
            ->limit(1024)
            ->get(['resources.id', 'resources.name', 'resources.collection_id']);

        $es = app(ElasticsearchService::class);
        $indices = $resources
            ->map(fn (Resource $r) => $r->collection?->searchIndex?->index_name)
            ->filter()
            ->unique()
            ->map(fn (string $name) => $es->buildChunksIndexName($name))
            ->values()
            ->all();

        $queryVector = $mode === 'semantic' && $q !== '' ? $this->embedQueryVector($q) : null;

        // Raw scored hits — no RAG relevance cutoff at the boundary. This is a
        // retrieval surface for external reasoners (MCP search_chunks, apps):
        // they see every hit with its score and choose their own threshold.
        // The built-in ask head applies the cutoff itself at context assembly.
        $hits = $queryVector !== null
            ? $es->knnSearchChunksForWorkspace($indices, $resources->pluck('id')->all(), $queryVector, $limit, applyRagFilters: false)
            : $es->searchChunksKeyword($indices, $q, $resources->pluck('id')->all(), $limit);

        // Role filter: only chunks from files whose role the vault lets contribute
        $fileIds = collect($hits)->pluck('file_id')->filter()->unique()->values();
        $rolesByFile = File::whereIn('id', $fileIds)->get(['id', 'role'])
            ->mapWithKeys(fn (File $f) => [$f->id => $f->role->value]);
        $allowedRoles = $vault->chunkRoles();
        $namesById = $resources->pluck('name', 'id');

        $results = collect($hits)
            ->filter(function (array $hit) use ($rolesByFile, $allowedRoles) {
                // Synthetic meta chunks (resource name/description/tags — no
                // source file) are Tier-0 material the vault already exposes on
                // cards, so no file role can gate them; without this branch the
                // role filter silently drops them from the ask context.
                if (($hit['file_id'] ?? '') === '' || $hit['file_id'] === null) {
                    return str_starts_with((string) ($hit['chunk_id'] ?? ''), 'meta-');
                }

                $role = $rolesByFile[$hit['file_id']] ?? null;

                return $role !== null && in_array($role, $allowedRoles, true);
            })
            ->map(fn (array $hit) => [
                'resource_id' => $hit['resource_id'],
                'resource_name' => $namesById[$hit['resource_id']] ?? null,
                'file_id' => $hit['file_id'],
                'sequence' => $hit['sequence'] ?? null,
                'page_number' => $hit['page_number'] ?? null,
                'content' => $hit['content'] ?? '',
                'score' => $hit['score'] ?? null,
            ])
            ->values()
            ->all();

        return [
            'type' => 'chunk-search',
            'query' => $q,
            'mode' => $queryVector !== null ? 'semantic' : 'keyword',
            'results' => $results,
        ];
    }

    /**
     * `/embed` — the query-embedding compute tool (Epic 4.1, diagram
     * `embedQuery`). Lets a consumer AI put its query into the vault's own
     * vector space without TYDAL doing any reasoning. Pure compute over an
     * already-resolved vault — no data leaves, so no tier gate. Null when
     * the embedder is unavailable (controller answers 503).
     */
    public function vaultEmbedQuery(string $q): ?array
    {
        $vector = $this->embedQueryVector($q);

        if ($vector === null) {
            return null;
        }

        $driver = (string) config('embedding.driver', 'ollama');

        return [
            'type' => 'embedding',
            'model' => (string) config("embedding.{$driver}.model", $driver),
            'dimensions' => count($vector),
            'vector' => $vector,
        ];
    }

    /**
     * Embed a search query, absorbing embedder failure into null so callers
     * can degrade (search falls back to keyword; /embed answers 503).
     *
     * @return float[]|null
     */
    private function embedQueryVector(string $q): ?array
    {
        // Embeddings are deterministic per model, so the query vector is
        // cacheable. This dedupes the two embeds the ask pipeline would
        // otherwise issue for one question (chunk search + card search) into a
        // single provider round-trip, and shares popular queries across
        // requests. Keyed by driver/model/dims so a provider switch can never
        // serve a vector from a different embedding space.
        $driver = (string) config('embedding.driver', 'ollama');
        $cacheKey = sprintf(
            'qembed:%s:%s:%d:%s',
            $driver,
            (string) config("embedding.{$driver}.model", ''),
            (int) config('embedding.dimensions', 768),
            sha1($q),
        );

        try {
            $vector = Cache::remember(
                $cacheKey,
                now()->addMinutes(30),
                fn () => app(EmbeddingServiceInterface::class)->embed($q),
            );

            return $vector === [] ? null : $vector;
        } catch (\Throwable $e) {
            Log::warning('Vault query embedding failed: '.$e->getMessage());

            return null;
        }
    }

    // -------------------------------------------------------------------------
    // Resource level
    // -------------------------------------------------------------------------

    /**
     * Default GET on a resource — the manifest rule (§5): exactly one exposed
     * file and Tier 2 allowed → the binary itself; otherwise an ordered
     * manifest. Returns a File to stream, or the manifest payload.
     */
    public function resourceEntry(Vault $vault, VaultLink $link): File|array
    {
        $resource = $link->resource;
        $exposed = $this->exposedFiles($vault, $resource);

        if ($vault->allowsBinary() && $exposed->count() === 1) {
            return $exposed->first();
        }

        return $this->resourceManifest($vault, $link, $exposed);
    }

    public function resourceMeta(Vault $vault, VaultLink $link): array
    {
        $resource = $link->resource()->with('semanticTags')->first();

        return [
            'type' => 'resource',
            ...$this->resourceCard($vault, $resource),
            'metadata' => $resource->metadata,
            'file_count' => $this->exposedFiles($vault, $resource)->count(),
            'chunks_available' => $vault->allowsChunks(),
        ];
    }

    public function resourceTags(Vault $vault, VaultLink $link): array
    {
        $resource = $link->resource()->with(['semanticTags', 'categories'])->first();

        return [
            'type' => 'resource-tags',
            'tags' => $resource->semanticTags->pluck('label')->values()->all(),
            'categories' => $resource->categories->pluck('name')->values()->all(),
        ];
    }

    public function resourceFiles(Vault $vault, VaultLink $link): array
    {
        $resource = $link->resource;

        return [
            'type' => 'resource-files',
            'files' => $this->fileEntries($vault, $resource),
        ];
    }

    /**
     * Tier 1 — chunk content, addressed through the resource (never hashed
     * separately). Filtered to the roles the vault's policy lets contribute.
     * Returns null when the vault denies Tier 1.
     */
    public function resourceChunks(Vault $vault, VaultLink $link, ?int $from = null, ?int $to = null, ?string $fileId = null): ?array
    {
        if (! $vault->allowsChunks()) {
            return null;
        }

        $resource = $link->resource()->with(['files', 'collection.searchIndex'])->first();
        $indexName = $resource->collection?->searchIndex?->index_name;

        if (! $indexName) {
            return ['type' => 'chunks', 'items' => []];
        }

        $es = app(ElasticsearchService::class);
        $chunks = $es->listChunksForResource($es->buildChunksIndexName($indexName), $resource->id);

        $rolesByFile = $resource->files->mapWithKeys(fn (File $f) => [$f->id => $f->role->value]);
        $allowedRoles = $vault->chunkRoles();

        $items = collect($chunks)
            ->filter(function (array $chunk) use ($rolesByFile, $allowedRoles, $fileId) {
                $role = $rolesByFile[$chunk['file_id'] ?? ''] ?? null;

                if ($fileId !== null && ($chunk['file_id'] ?? null) !== $fileId) {
                    return false;
                }

                return $role !== null && in_array($role, $allowedRoles, true);
            })
            ->when($from !== null, fn ($c) => $c->filter(fn (array $chunk) => ($chunk['sequence'] ?? 0) >= $from))
            ->when($to !== null, fn ($c) => $c->filter(fn (array $chunk) => ($chunk['sequence'] ?? 0) <= $to))
            ->values()
            ->all();

        return ['type' => 'chunks', 'items' => $items];
    }

    /**
     * Tier 2 — mint hash URLs for the resource and its exposed files.
     * Returns URLs, never bytes (§5). Null when the vault denies Tier 2.
     */
    public function resourceLinks(Vault $vault, VaultLink $link): ?array
    {
        if (! $vault->allowsBinary()) {
            return null;
        }

        $resource = $link->resource;
        $link->setRelation('vault', $vault);

        return [
            'type' => 'resource-links',
            'resource' => ['slug' => $link->slug, 'url' => $this->links->buildUrl($link)],
            'files' => $this->exposedFiles($vault, $resource)->map(function (File $file) use ($vault, $resource) {
                $fileLink = $this->links->getOrCreateLink($vault, null, $resource->id, $file->id);
                $fileLink->setRelation('vault', $vault);

                return [
                    'filename' => $file->filename,
                    'slug' => $fileLink->slug,
                    'url' => $this->links->buildUrl($fileLink),
                ];
            })->values()->all(),
        ];
    }

    /**
     * Related resources (Tier 0). Graph edges first (Epic 4.3 — curated,
     * tag-co-occurrence and semantic relations, heaviest first), projected
     * into THIS vault: a neighbor outside the vault's resource set never
     * leaks. Falls back to the shared-tag heuristic when the resource has
     * no graph edges yet.
     */
    public function resourceRelated(Vault $vault, VaultLink $link, int $limit = 10): array
    {
        $resource = $link->resource()->with('semanticTags')->first();

        $edges = app(ResourceGraphService::class)->edgesFor($resource);

        if ($edges->isNotEmpty()) {
            $edgeByNeighbor = [];
            foreach ($edges as $edge) {
                $edgeByNeighbor[$edge->otherEnd($resource->id)] ??= $edge;
            }

            $inVault = $this->vaultResourceQuery($vault)
                ->with('semanticTags')
                ->whereIn('resources.id', array_keys($edgeByNeighbor))
                ->get()
                ->sortByDesc(fn (Resource $r) => $edgeByNeighbor[$r->id]->weight ?? 0)
                ->take($limit)
                ->values();

            [$cards] = $this->buildCards($vault, $inVault);

            return [
                'type' => 'resource-related',
                'source' => 'graph',
                // buildCards preserves order, so zip the relation back in by index
                'resources' => $inVault->map(function (Resource $r, int $i) use ($cards, $edgeByNeighbor) {
                    $edge = $edgeByNeighbor[$r->id];

                    return $cards[$i] + [
                        'relation' => [
                            'type' => $edge->type->value,
                            'origin' => $edge->origin->value,
                            'weight' => $edge->weight,
                        ],
                    ];
                })->values()->all(),
            ];
        }

        $tagIds = $resource->semanticTags->pluck('id');

        $related = $tagIds->isEmpty()
            ? new EloquentCollection
            : $this->vaultResourceQuery($vault)
                ->with('semanticTags')
                ->where('resources.id', '!=', $resource->id)
                ->whereHas('semanticTags', fn ($q) => $q->whereIn('semantic_tags.id', $tagIds))
                ->limit($limit)
                ->get();

        [$cards] = $this->buildCards($vault, $related);

        return [
            'type' => 'resource-related',
            'source' => 'tags',
            'resources' => $cards,
        ];
    }

    // -------------------------------------------------------------------------
    // File level
    // -------------------------------------------------------------------------

    /**
     * Default GET on a file — the binary, if Tier 2 allows. Null = denied.
     */
    public function fileEntry(Vault $vault, VaultLink $link): ?File
    {
        if (! $vault->allowsBinary()) {
            return null;
        }

        return $link->file;
    }

    public function fileMeta(Vault $vault, VaultLink $link): array
    {
        $file = $link->file;

        return [
            'type' => 'file',
            'filename' => $file->filename,
            'slug' => $link->slug,
            'mime_type' => $file->mime_type,
            'size' => $file->size,
            'role' => $file->role->value,
            'position' => $file->position,
        ];
    }

    public function fileChunks(Vault $vault, VaultLink $link, ?int $from = null, ?int $to = null): ?array
    {
        $resourceLink = $this->links->getOrCreateLink($vault, null, $link->resource_id, null);
        $resourceLink->setRelation('vault', $vault);

        return $this->resourceChunks($vault, $resourceLink, $from, $to, $link->file_id);
    }

    /**
     * Tier 2 + vault is_downloadable — the forced download. Null = denied.
     */
    public function fileDownload(Vault $vault, VaultLink $link): ?File
    {
        if (! $vault->allowsBinary() || ! $vault->is_downloadable) {
            return null;
        }

        return $link->file;
    }

    /**
     * First exposed file of a resource link — the resource-level download
     * target (grammar parity with the flat delivery form).
     */
    public function firstExposedFile(Vault $vault, VaultLink $link): ?File
    {
        return $this->exposedFiles($vault, $link->resource)->first();
    }

    /**
     * Available representations of the file (Tier 2): the original plus any
     * generated media conversions. Null = denied.
     */
    public function fileRenditions(Vault $vault, VaultLink $link): ?array
    {
        if (! $vault->allowsBinary()) {
            return null;
        }

        $file = $link->file()->with('media')->first();
        $link->loadMissing('vault');

        // Rendition addresses stay inside the vault boundary (the link's own
        // URL + ?rendition=), never the raw storage/media URLs — those bypass
        // the hash/key gate.
        $base = $this->links->buildUrl($link).'/download';

        $renditions = [
            ['name' => 'original', 'mime_type' => $file->mime_type, 'size' => $file->size, 'url' => $base],
        ];

        $media = $file->media;
        if ($media) {
            foreach (array_keys($media->getGeneratedConversions()->filter()->all()) as $conversion) {
                $renditions[] = [
                    'name' => $conversion,
                    'mime_type' => $media->mime_type,
                    'size' => null,
                    'url' => $base.'?rendition='.$conversion,
                ];
            }
        }

        return ['type' => 'file-renditions', 'renditions' => $renditions];
    }

    /**
     * Resolve one generated rendition (media conversion) of the file for
     * streaming — the target of the `?rendition=` download form above.
     * Null = denied, unknown rendition, or not yet generated.
     *
     * @return array{disk: string, path: string, mime_type: string, filename: string}|null
     */
    public function fileRendition(Vault $vault, VaultLink $link, string $name): ?array
    {
        if (! $vault->allowsBinary() || ! $vault->is_downloadable) {
            return null;
        }

        $media = $link->file()->with('media')->first()?->media;

        if (! $media || empty($media->getGeneratedConversions()[$name])) {
            return null;
        }

        $disk = $media->conversions_disk ?? $media->disk;
        $path = $media->getPathRelativeToRoot($name);

        return [
            'disk' => $disk,
            'path' => $path,
            'mime_type' => Storage::disk($disk)->mimeType($path) ?: 'application/octet-stream',
            'filename' => basename($path),
        ];
    }

    // -------------------------------------------------------------------------

    /**
     * The resources this vault projects — exactly the set validateLink()
     * accepts: any vault-linked workspace, or (has_public_workspace) any
     * same-org resource. Public: the per-vault indexer (Epic 3.2) iterates it.
     *
     * @return Builder<\App\Models\Resource>
     */
    public function vaultResourceQuery(Vault $vault): Builder
    {
        $wsIds = DB::table('workspace_vault')->where('vault_id', $vault->id)->pluck('workspace_id');

        return Resource::query()
            ->where('state', ResourceState::LIVE->value)
            // Org pinning (spec §2) now applies to BOTH branches, not just the
            // public-workspace one: the attach paths keep the workspace→vault
            // and resource→workspace edges inside a single org, and this makes
            // the boundary itself enforce that at serve time — a stray pivot
            // row can never project one org's resources through another's.
            ->where('organization_id', $vault->organization_id)
            // has_public_workspace projects the whole org, so workspace
            // membership stops being a condition at all.
            ->when(
                ! $vault->has_public_workspace,
                fn (Builder $q) => $q->whereHas('workspaces', fn ($wq) => $wq->whereIn('workspaces.id', $wsIds)),
            );
    }

    /**
     * Build identity cards for a whole collection with a bounded query count:
     * eager-load the snapshot relations the cards read, then batch-resolve the
     * links in one query. Every listing (index, search fallback, related,
     * graph) routes through here so none can regress into a per-card N+1.
     *
     * @param  EloquentCollection<int, resource>  $resources
     * @return array{0: list<array<string, mixed>>, 1: array<string, VaultLink>} cards (in order) + resourceId→link map (callers needing hashes, e.g. graph edges)
     */
    private function buildCards(Vault $vault, EloquentCollection $resources): array
    {
        if ($resources->isEmpty()) {
            return [[], []];
        }

        // loadMissing lives on the Eloquent collection; an empty base
        // collection (e.g. the no-tags fallback) is handled above.
        $resources->loadMissing(['snapshotFile.media', 'previewSnapshotSystemFile']);
        $links = $this->links->getOrCreateResourceLinks($vault, $resources->pluck('id'));
        $cards = $resources->map(fn (Resource $r) => $this->resourceCard($vault, $r, $links[$r->id] ?? null))->values()->all();

        return [$cards, $links];
    }

    /**
     * Tier 0 identity card — what listings, search results, and the index
     * are made of. Minting the resource link is lazy, like the CDN always was.
     * Public: vault index documents embed the card (Epic 3.2).
     */
    public function resourceCard(Vault $vault, Resource $resource, ?VaultLink $link = null): array
    {
        // Listings pre-resolve the link in one batched query (getOrCreateResourceLinks)
        // to avoid a per-card getOrCreateLink; single-card callers omit it.
        $link ??= $this->links->getOrCreateLink($vault, null, $resource->id, null);
        $link->setRelation('vault', $vault);

        $resource->loadMissing(['snapshotFile.media', 'previewSnapshotSystemFile']);
        $hasPreview = $resource->snapshotFile !== null || $resource->previewSnapshotSystemFile !== null;

        return [
            // Vault-scoped opaque id (the link hash — the same token that
            // travels in /h/{vaultHash}/{linkHash}). The internal resource UUID
            // never crosses the vault boundary: it would let a consumer
            // correlate the same resource across vaults or probe the private
            // API. Rotating the vault salt re-randomizes this id.
            'id' => $link->hash,
            'name' => $resource->name,
            'slug' => $link->slug,
            'description' => $resource->description,
            'resource_type' => $resource->type,
            'tags' => $resource->semanticTags->pluck('label')->values()->all(),
            'url' => $this->links->buildUrl($link),
            'path' => $this->humanPath($vault, (string) $link->slug),
            // The resource's face (designated snapshot / rendered preview) —
            // set only when one exists so renderers never probe for it.
            'preview' => $hasPreview ? $this->links->buildUrl($link).'/preview' : null,
            'preview_renditions' => $this->previewRenditions($vault, $resource, $link),
            // Slot-mapped metadata values — Tier 0 by the spec (§6.2 lists
            // facet values as identity), and what lets a listing render
            // credit/badge/detail slots without a per-card /meta round trip.
            'metadata' => $this->cardMetadata($vault, $resource) ?: null,
        ];
    }

    /** Generated renditions and on-demand preparation, all behind the preview gate. */
    private function previewRenditions(Vault $vault, Resource $resource, VaultLink $link): array
    {
        if (! $vault->allowsBinary() || (! $resource->snapshotFile && ! $resource->previewSnapshotSystemFile)) {
            return [];
        }

        $base = $this->links->buildUrl($link).'/preview';
        // '?rendition=original' stays available regardless of is_downloadable
        // (see resourcePreview) — only the bare/default preview link changes
        // behavior when downloads aren't allowed.
        $renditions = [['name' => 'original', 'url' => $base.'?rendition=original']];
        $source = $resource->snapshotFile ?? $resource->previewSnapshotSystemFile;
        if (in_array($source?->mime_type, VisionImagePreparer::SUPPORTED_MIME_TYPES, true)) {
            $renditions[] = [
                'name' => 'ai-prepared', 'url' => $base.'?rendition=ai-prepared',
                'on_demand' => true, 'max_bytes' => VisionImagePreparer::MAX_BYTES,
            ];
        }
        $media = $resource->snapshotFile?->media;
        foreach ($media?->getGeneratedConversions()->filter()->keys()->all() ?? [] as $name) {
            $renditions[] = ['name' => $name, 'url' => $base.'?rendition='.rawurlencode($name)];
        }

        return $renditions;
    }

    /**
     * The resource's metadata filtered to fields the vault's resolved
     * presentation exposes (anything slotted except `hidden`) — the same
     * boundary filter the projected search documents apply.
     *
     * @return array<string, mixed>
     */
    private function cardMetadata(Vault $vault, Resource $resource): array
    {
        $this->cardFieldCache[$vault->id] ??= (function () use ($vault): array {
            $fields = [];
            foreach ($this->schemaResolver->presentationFor($vault) as $block) {
                foreach ($block['fields'] as $name => $slot) {
                    if ($slot !== 'hidden' && $slot !== 'image' && ! isset($fields[$name])) {
                        $fields[$name] = true;
                    }
                }
            }

            return $fields;
        })();

        return array_intersect_key($resource->metadata ?? [], $this->cardFieldCache[$vault->id]);
    }

    /**
     * The resource's designated preview binary (Tier 2): the snapshot-flagged
     * file, else the generated PREVIEW_SNAPSHOT system file (PDF first page,
     * audio album art). The snapshot is the resource's *face*, so it serves
     * regardless of address-role exposure — flagging it is the curation act.
     * Null = tier denied or no preview exists.
     *
     * @return array{disk: string, path: string, mime_type: string, filename: string}|null
     */
    public function resourcePreview(Vault $vault, VaultLink $link, ?string $rendition = null): ?array
    {
        if (! $vault->allowsBinary()) {
            return null;
        }

        $resource = $link->resource;

        if (! $resource) {
            return null;
        }

        $resource->loadMissing(['snapshotFile.media', 'previewSnapshotSystemFile']);
        $media = $resource->snapshotFile?->media;

        if ($rendition !== null && $rendition !== '' && $rendition !== 'original') {
            if (! $media || empty($media->getGeneratedConversions()[$rendition])) {
                return null;
            }

            return $this->conversionPreview($media, $rendition);
        }

        // An explicit ?rendition=original is a deliberate ask for the untouched
        // file (the vault-mcp `read_image` AI path) and, like every other named
        // rendition, isn't gated by is_downloadable — only /download's
        // disposition:attachment copy is (VaultNamespaceController's 'download'
        // case). The *default*, unparented face URL (card.preview) is
        // different: nothing chose 'original' on purpose, so when the vault
        // hasn't opted into downloads it degrades to the largest capped
        // conversion instead of silently handing a print-quality file to
        // anyone who opens the card's plain preview link.
        $wantsOriginalExplicitly = $rendition === 'original';

        if (! $wantsOriginalExplicitly && ! $vault->is_downloadable && $media) {
            foreach (['large', 'medium', 'small', 'thumbnail'] as $name) {
                if (! empty($media->getGeneratedConversions()[$name])
                    && $preview = $this->conversionPreview($media, $name)) {
                    return $preview;
                }
            }
        }

        if ($file = $resource->snapshotFile) {
            return [
                'disk' => $file->disk,
                'path' => $file->path,
                'mime_type' => $file->mime_type,
                'filename' => $file->filename,
            ];
        }

        if ($sf = $resource->previewSnapshotSystemFile) {
            return [
                'disk' => $sf->disk,
                'path' => $sf->path,
                'mime_type' => $sf->mime_type,
                'filename' => basename($sf->path),
            ];
        }

        return null;
    }

    /** @return array{disk: string, path: string, mime_type: string, filename: string}|null */
    private function conversionPreview(Media $media, string $name): ?array
    {
        $disk = $media->conversions_disk ?? $media->disk;
        $path = $media->getPathRelativeToRoot($name);
        if (! Storage::disk($disk)->exists($path)) {
            return null;
        }

        return [
            'disk' => $disk,
            'path' => $path,
            'mime_type' => Storage::disk($disk)->mimeType($path) ?: 'application/octet-stream',
            'filename' => basename($path),
        ];
    }

    /**
     * Exposed files: active + role within the vault's address exposure,
     * ordered for the manifest (position, then upload order).
     */
    private function exposedFiles(Vault $vault, Resource $resource): Collection
    {
        return $resource->files
            ->filter(fn (File $f) => $f->is_active && in_array($f->role->value, $vault->addressRoles(), true))
            ->sortBy([
                fn (File $a, File $b) => ($a->position ?? PHP_INT_MAX) <=> ($b->position ?? PHP_INT_MAX),
                fn (File $a, File $b) => $a->created_at <=> $b->created_at,
            ])
            ->values();
    }

    private function resourceManifest(Vault $vault, VaultLink $link, Collection $exposed): array
    {
        $resource = $link->resource()->with('semanticTags')->first();

        return [
            'type' => 'manifest',
            'resource' => $this->resourceCard($vault, $resource),
            'files' => $this->fileEntries($vault, $resource, $exposed),
        ];
    }

    /**
     * Ordered file entries with their addresses (binary URL only if Tier 2).
     */
    private function fileEntries(Vault $vault, Resource $resource, ?Collection $exposed = null): array
    {
        $exposed ??= $this->exposedFiles($vault, $resource);

        return $exposed->map(function (File $file) use ($vault, $resource) {
            $fileLink = $this->links->getOrCreateLink($vault, null, $resource->id, $file->id);
            $fileLink->setRelation('vault', $vault);

            return [
                'position' => $file->position,
                'filename' => $file->filename,
                'slug' => $fileLink->slug,
                'mime_type' => $file->mime_type,
                'size' => $file->size,
                'role' => $file->role->value,
                'url' => $vault->allowsBinary() ? $this->links->buildUrl($fileLink) : null,
            ];
        })->values()->all();
    }

    private function humanPath(Vault $vault, string ...$segments): string
    {
        $vault->loadMissing('organization');

        $path = '/v/'.$vault->organization->slug.'/'.$vault->slug;

        return $segments === [] ? $path : $path.'/'.implode('/', $segments);
    }
}
