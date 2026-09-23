<?php

namespace App\Services;

use App\Enums\FileRole;
use App\Enums\ResourceState;
use App\Enums\SystemFilePurpose;
use App\Exceptions\IndexVocabularyConflict;
use App\Models\CollectionScheme;
use App\Models\Resource;
use App\Models\SearchIndex;
use App\Models\Vault;
use App\Services\Interfaces\ResourceServiceInterface;
use App\Support\IndexVocabulary;
use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\ClientBuilder;
use Elastic\Elasticsearch\ClientInterface;
use Elastic\Elasticsearch\Exception\ClientResponseException;
use Illuminate\Support\Facades\Log;

class ElasticsearchService
{
    private ClientInterface $client;

    public function __construct()
    {
        $builder = ClientBuilder::create()
            ->setHosts([config('elasticsearch.host')]);

        if ($apiKey = config('elasticsearch.api_key')) {
            $builder->setApiKey($apiKey);
        } elseif ($username = config('elasticsearch.username')) {
            $builder->setBasicAuthentication($username, config('elasticsearch.password', ''));
        }

        $this->client = $builder->build();
    }

    // =========================================================================
    // DOCUMENT INDEXING
    // =========================================================================

    /**
     * Index (or re-index) a resource document.
     * Looks up the resource's collection → search_index.index_name.
     * Silently skips if no index is configured.
     */
    public function indexResource(Resource $resource): void
    {
        $resource->load([
            'collection',
            'canonicalFile',
            'files',
            'systemFiles' => fn ($q) => $q->where('is_active', true),
            'workspaces',
            'semanticTags',
        ]);
        $indexName = $this->resolveIndexName($resource);

        if (! $indexName) {
            return;
        }

        $doc = $this->buildDocument($resource);

        try {
            $this->client->index([
                'index' => $indexName,
                'id' => $resource->id,
                'body' => $doc,
            ]);
        } catch (\Throwable $e) {
            Log::error("ES index failed for resource {$resource->id}: ".$e->getMessage());

            return;
        }

        // Write annotation child doc — separate try-catch so a child failure doesn't mask parent success
        try {
            $this->client->index([
                'index' => $indexName,
                'id' => $resource->id.'#annotations',
                'routing' => $resource->id,
                'body' => $this->buildAnnotationDocument($resource),
            ]);
        } catch (\Throwable $e) {
            Log::error("ES annotation index failed for resource {$resource->id}: ".$e->getMessage());
        }
    }

    /**
     * Delete a resource document from its index.
     */
    public function deleteResource(string $resourceId): void
    {
        // We need to find the index name from the DB before the resource is gone.
        $resource = Resource::withTrashed()->with('collection')->find($resourceId);

        if (! $resource) {
            return;
        }

        $indexName = $this->resolveIndexName($resource);

        if (! $indexName) {
            return;
        }

        try {
            $this->client->delete([
                'index' => $indexName,
                'id' => $resourceId,
            ]);
        } catch (ClientResponseException $e) {
            // 404 = document never indexed — not an error
            if ($e->getCode() !== 404) {
                Log::error("ES delete failed for resource {$resourceId}: ".$e->getMessage());
            }
        } catch (\Throwable $e) {
            Log::error("ES delete failed for resource {$resourceId}: ".$e->getMessage());
        }

        // ES does not cascade-delete child docs — explicit delete required
        try {
            $this->client->delete([
                'index' => $indexName,
                'id' => $resourceId.'#annotations',
                'routing' => $resourceId,
            ]);
        } catch (ClientResponseException $e) {
            if ($e->getCode() !== 404) {
                Log::error("ES annotation delete failed for resource {$resourceId}: ".$e->getMessage());
            }
        } catch (\Throwable $e) {
            Log::error("ES annotation delete failed for resource {$resourceId}: ".$e->getMessage());
        }

        // Delete the synthetic metadata chunk from the companion chunks index
        $chunksIndex = $this->buildChunksIndexName($indexName);
        try {
            $this->client->delete([
                'index' => $chunksIndex,
                'id' => 'meta-'.$resourceId,
            ]);
        } catch (ClientResponseException $e) {
            // 404 = no metadata chunk was ever created — not an error
            if ($e->getCode() !== 404) {
                Log::error("ES metadata chunk delete failed for resource {$resourceId}: ".$e->getMessage());
            }
        } catch (\Throwable $e) {
            Log::error("ES metadata chunk delete failed for resource {$resourceId}: ".$e->getMessage());
        }
    }

    // =========================================================================
    // SEARCH
    // =========================================================================

    /**
     * Full-text search within a collection's index.
     *
     * @param  string  $query  Free-text query (empty = match all)
     * @param  array  $facetFilters  e.g. ['language' => ['en', 'fr'], 'type' => ['image']]
     * @return array{hits: array, total: int, facets: array}
     */
    public function searchResources(
        string $indexName,
        int $collectionId,
        string $query,
        array $facetFilters,
        array $facetFields,
        int $page,
        int $perPage,
        string $sortBy = 'updated_at',
        string $sortDir = 'desc',
        string $searchMode = 'prefix',
        array $wsIdToName = [],
        array $stIdToLabel = []
    ): array {
        $must = [];

        // Scope to parent documents only — exclude annotation child docs from results
        $must[] = ['term' => ['relation' => 'resource']];

        // Scope to collection
        $must[] = ['term' => ['collection_id' => $collectionId]];
        $must[] = ['term' => ['state' => ResourceState::LIVE->value]];

        // …and to the organization, explicitly.
        //
        // A collection belongs to exactly one organization, so the clause above
        // already scoped this query transitively. That was fine while index
        // sharing was incidental. Now that an index can be advertised as
        // belonging to one organization, isolation is a promise someone will
        // rely on, and a promise should be enforced rather than inherited from
        // a join two tables away.
        if ($organizationId = currentOrganizationId()) {
            $must[] = ['term' => ['organization_id' => $organizationId]];
        }

        // Full-text — supports field-scoped syntax: tag:paris workspace:design
        if ($query !== '') {
            $parsed = $this->parseFieldScopedQuery($query);
            $remainder = $parsed['remainder'];
            $scoped = $parsed['scoped'];

            // Each scoped token becomes a required has_child must clause
            $childFieldMap = [
                'tag' => 'tag_labels',
                'workspace' => 'workspace_labels',
                'ai_description' => 'ai_description',
            ];
            foreach ($scoped as $token) {
                $childField = $childFieldMap[$token['field']] ?? null;
                if ($childField !== null) {
                    $must[] = [
                        'has_child' => [
                            'type' => 'annotations',
                            'score_mode' => 'max',
                            'query' => ['match' => [$childField => $token['value']]],
                        ],
                    ];
                }
            }

            // Unscoped remainder uses standard multi_match on parent fields
            if ($remainder !== '') {
                $multiMatch = [
                    'multi_match' => [
                        'query' => $remainder,
                        'fields' => ['name^3', 'description^2', 'extracted_text', 'metadata.*'],
                        'type' => 'best_fields',
                    ],
                ];

                if ($searchMode === 'exact') {
                    // All words must appear as a consecutive phrase in name/description or tags
                    $must[] = [
                        'bool' => [
                            'should' => [
                                ['multi_match' => ['query' => $remainder, 'fields' => ['name^3', 'description^2'], 'type' => 'phrase']],
                                ['has_child' => ['type' => 'annotations', 'score_mode' => 'max', 'query' => ['match_phrase' => ['tag_labels' => $remainder]]]],
                            ],
                            'minimum_should_match' => 1,
                        ],
                    ];
                } elseif ($searchMode === 'contains') {
                    // All words must appear somewhere in the field (any order) — match + operator:and
                    // The keyword wildcard also catches exact substrings in the resource name
                    $must[] = [
                        'bool' => [
                            'should' => [
                                ['wildcard' => ['name.keyword' => ['value' => "*{$remainder}*", 'case_insensitive' => true]]],
                                ['match' => ['name' => ['query' => $remainder, 'operator' => 'and']]],
                                ['match' => ['description' => ['query' => $remainder, 'operator' => 'and']]],
                                ['has_child' => ['type' => 'annotations', 'score_mode' => 'max', 'query' => ['match' => ['tag_labels' => ['query' => $remainder, 'operator' => 'and']]]]],
                            ],
                            'minimum_should_match' => 1,
                        ],
                    ];
                } else {
                    // prefix / Smart mode — broad multiMatch supports the BM25 pass in hybrid search
                    $must[] = [
                        'bool' => [
                            'should' => [$multiMatch, ['match_phrase_prefix' => ['name' => $remainder]]],
                            'minimum_should_match' => 1,
                        ],
                    ];
                }
            }
        }

        // Facet filters
        $filter = [];
        foreach ($facetFilters as $key => $values) {
            if (! empty($values)) {
                if ($key === 'type') {
                    $filter[] = ['terms' => ['type' => array_values($values)]];
                } elseif ($key === 'workspaces') {
                    // Values are already IDs — translated by the controller before this call
                    $filter[] = ['terms' => ['workspace_ids' => array_values($values)]];
                } elseif ($key === 'semantic_tags') {
                    // Values are already IDs — translated by the controller before this call
                    $filter[] = ['terms' => ['semantic_tag_ids' => array_values($values)]];
                } else {
                    $filter[] = ['terms' => ["metadata.{$key}" => array_values($values)]];
                }
            }
        }

        $esQuery = [
            'bool' => [
                'must' => $must,
                'filter' => $filter,
            ],
        ];

        // Build aggregations from scheme facet fields
        $aggs = [];
        foreach ($facetFields as $field) {
            $fieldName = $field['name'];
            $esField = $fieldName === 'type' ? 'type' : "metadata.{$fieldName}";
            $aggs[$fieldName] = ['terms' => ['field' => $esField, 'size' => 50]];
        }
        // Always aggregate workspaces and semantic tags (relational facets, not part of scheme fields)
        $aggs['workspaces'] = ['terms' => ['field' => 'workspace_ids',    'size' => 50]];
        $aggs['semantic_tags'] = ['terms' => ['field' => 'semantic_tag_ids', 'size' => 100]];

        // Map sort field: name requires .keyword subfield for ES sorting
        $esSortField = match ($sortBy) {
            'name' => 'name.keyword',
            default => $sortBy,
        };

        // When searching by text, score takes priority; otherwise sort purely by field
        // Use $query (not just remainder) so scoped-only queries like "tag:paris" also rank by score
        $esSort = $query !== ''
            ? [['_score' => 'desc'], [$esSortField => $sortDir]]
            : [[$esSortField => $sortDir]];

        $params = [
            'index' => $indexName,
            'body' => [
                'query' => $esQuery,
                'from' => ($page - 1) * $perPage,
                'size' => $perPage,
                'sort' => $esSort,
                'aggs' => $aggs,
            ],
        ];

        $response = $this->client->search($params);
        $body = $response->asArray();

        $ids = array_column($body['hits']['hits'] ?? [], '_id');
        $total = $body['hits']['total']['value'] ?? 0;
        $facets = $this->parseFacets($body['aggregations'] ?? [], $facetFields, $wsIdToName, $stIdToLabel);

        return compact('ids', 'total', 'facets');
    }

    /**
     * Full-text search across multiple indices, scoped to a workspace.
     *
     * Mirrors searchResources() but:
     *  - Accepts multiple index names (workspace can span several collections)
     *  - Filters by workspace_ids instead of collection_id
     *  - Omits the workspace aggregation (already in workspace context)
     *
     * @param  string[]  $indexNames
     * @return array{ids: array, total: int, facets: array}
     */
    public function searchByWorkspace(
        array $indexNames,
        int $workspaceId,
        string $query,
        array $facetFilters,
        array $facetFields,
        int $page,
        int $perPage,
        string $sortBy = 'updated_at',
        string $sortDir = 'desc',
        string $searchMode = 'prefix',
        array $wsIdToName = [],
        array $stIdToLabel = []
    ): array {
        $must = [];
        $must[] = ['term' => ['relation' => 'resource']];
        $must[] = ['term' => ['workspace_ids' => $workspaceId]];
        $must[] = ['term' => ['state' => ResourceState::LIVE->value]];

        // Explicit, for the same reason as searchResources: a workspace belongs
        // to one organization, but on a shared index that must not be the only
        // thing keeping the results separate.
        if ($organizationId = currentOrganizationId()) {
            $must[] = ['term' => ['organization_id' => $organizationId]];
        }

        if ($query !== '') {
            $parsed = $this->parseFieldScopedQuery($query);
            $remainder = $parsed['remainder'];
            $scoped = $parsed['scoped'];

            $childFieldMap = [
                'tag' => 'tag_labels',
                'workspace' => 'workspace_labels',
                'ai_description' => 'ai_description',
            ];
            foreach ($scoped as $token) {
                $childField = $childFieldMap[$token['field']] ?? null;
                if ($childField !== null) {
                    $must[] = [
                        'has_child' => [
                            'type' => 'annotations',
                            'score_mode' => 'max',
                            'query' => ['match' => [$childField => $token['value']]],
                        ],
                    ];
                }
            }

            if ($remainder !== '') {
                $multiMatch = [
                    'multi_match' => [
                        'query' => $remainder,
                        'fields' => ['name^3', 'description^2', 'extracted_text', 'metadata.*'],
                        'type' => 'best_fields',
                    ],
                ];

                if ($searchMode === 'exact') {
                    $must[] = [
                        'bool' => [
                            'should' => [
                                ['multi_match' => ['query' => $remainder, 'fields' => ['name^3', 'description^2'], 'type' => 'phrase']],
                                ['has_child' => ['type' => 'annotations', 'score_mode' => 'max', 'query' => ['match_phrase' => ['tag_labels' => $remainder]]]],
                            ],
                            'minimum_should_match' => 1,
                        ],
                    ];
                } elseif ($searchMode === 'contains') {
                    $must[] = [
                        'bool' => [
                            'should' => [
                                ['wildcard' => ['name.keyword' => ['value' => "*{$remainder}*", 'case_insensitive' => true]]],
                                ['match' => ['name' => ['query' => $remainder, 'operator' => 'and']]],
                                ['match' => ['description' => ['query' => $remainder, 'operator' => 'and']]],
                                ['has_child' => ['type' => 'annotations', 'score_mode' => 'max', 'query' => ['match' => ['tag_labels' => ['query' => $remainder, 'operator' => 'and']]]]],
                            ],
                            'minimum_should_match' => 1,
                        ],
                    ];
                } else {
                    $must[] = [
                        'bool' => [
                            'should' => [$multiMatch, ['match_phrase_prefix' => ['name' => $remainder]]],
                            'minimum_should_match' => 1,
                        ],
                    ];
                }
            }
        }

        $filter = [];
        foreach ($facetFilters as $key => $values) {
            if (! empty($values)) {
                if ($key === 'type') {
                    $filter[] = ['terms' => ['type' => array_values($values)]];
                } elseif ($key === 'semantic_tags') {
                    $filter[] = ['terms' => ['semantic_tag_ids' => array_values($values)]];
                } else {
                    $filter[] = ['terms' => ["metadata.{$key}" => array_values($values)]];
                }
            }
        }

        $aggs = [];
        foreach ($facetFields as $field) {
            $fieldName = $field['name'];
            $esField = $fieldName === 'type' ? 'type' : "metadata.{$fieldName}";
            $aggs[$fieldName] = ['terms' => ['field' => $esField, 'size' => 50]];
        }
        // Only semantic tags facet — workspace facet is omitted (already in workspace context)
        $aggs['semantic_tags'] = ['terms' => ['field' => 'semantic_tag_ids', 'size' => 100]];

        $esSortField = match ($sortBy) {
            'name' => 'name.keyword',
            default => $sortBy,
        };
        $esSort = $query !== ''
            ? [['_score' => 'desc'], [$esSortField => $sortDir]]
            : [[$esSortField => $sortDir]];

        $params = [
            'index' => implode(',', $indexNames),
            'body' => [
                'query' => ['bool' => ['must' => $must, 'filter' => $filter]],
                'from' => ($page - 1) * $perPage,
                'size' => $perPage,
                'sort' => $esSort,
                'aggs' => $aggs,
            ],
        ];

        $response = $this->client->search($params);
        $body = $response->asArray();

        $ids = array_column($body['hits']['hits'] ?? [], '_id');
        $total = $body['hits']['total']['value'] ?? 0;
        $facets = $this->parseFacets($body['aggregations'] ?? [], $facetFields, $wsIdToName, $stIdToLabel);

        return compact('ids', 'total', 'facets');
    }

    // =========================================================================
    // INDEX MANAGEMENT
    // =========================================================================

    /**
     * Create or update an ES index for a given SearchIndex record.
     *
     * The mapping is the **union** of every scheme whose collections live in
     * this index — an index is one mapping shared by every document in it, so
     * deriving it from one collection left the other schemes' fields to ES's
     * dynamic inference, silently mistyped. Incompatible declarations are
     * refused ({@see IndexVocabularyConflict}) rather than resolved by row
     * order; see {@see IndexVocabulary}.
     *
     * @param  bool  $recreate  Drop and recreate the index if it already exists.
     *                          Required when field types have changed.
     *
     * @throws IndexVocabularyConflict
     */
    public function setupIndex(SearchIndex $searchIndex, bool $recreate = false): void
    {
        $indexName = $searchIndex->index_name;

        $vocabulary = $this->vocabularyFor($searchIndex);

        if ($vocabulary->hasConflicts()) {
            throw new IndexVocabularyConflict($searchIndex, $vocabulary->conflicts());
        }

        $mappings = $this->buildMappings($vocabulary);

        $settings = [
            'number_of_shards' => config('elasticsearch.index_settings.number_of_shards', 1),
            'number_of_replicas' => config('elasticsearch.index_settings.number_of_replicas', 0),
        ];

        $exists = $this->client->indices()->exists(['index' => $indexName])->asBool();

        if ($exists && $recreate) {
            $this->client->indices()->delete(['index' => $indexName]);
            $exists = false;
        }

        if ($exists) {
            // Update mappings only (ES does not allow changing existing field types)
            $this->client->indices()->putMapping([
                'index' => $indexName,
                'body' => $mappings,
            ]);
        } else {
            $this->client->indices()->create([
                'index' => $indexName,
                'body' => ['settings' => $settings, 'mappings' => $mappings],
            ]);
        }
    }

    /**
     * Guarantee a collection's index (+ its chunks companion) exists in ES,
     * building the mapping from whatever schemes are attached to it right
     * now. A null index is a no-op — the collection just falls back to DB
     * search, same as before this existed.
     *
     * `$recreate` drops and rebuilds the index, destroying any documents
     * already indexed there — including from OTHER collections sharing it.
     * Only ever pass true right after a fresh migrate (the installer), never
     * from a caller that can run against a live, already-populated index.
     */
    public function provisionIndex(?SearchIndex $index, bool $recreate = false): void
    {
        if (! $index) {
            return;
        }

        $this->setupIndex($index, $recreate);
        $this->setupChunksIndex($index, $recreate);
    }

    /**
     * The schemes whose documents share this index — distinct, order-stable.
     */
    public function vocabularyFor(SearchIndex $searchIndex): IndexVocabulary
    {
        $schemes = $searchIndex->collections()
            ->with('scheme')
            ->orderBy('id')
            ->get()
            ->pluck('scheme')
            ->filter()
            ->unique('id')
            ->values();

        return IndexVocabulary::of($schemes);
    }

    /**
     * Generate ES mappings from scheme field declarations.
     *
     * Accepts a merged {@see IndexVocabulary} (what `setupIndex` passes), a
     * single scheme, a set of schemes, or null for the base mapping alone.
     *
     * @param  IndexVocabulary|CollectionScheme|iterable<CollectionScheme>|null  $source
     */
    public function buildMappings(IndexVocabulary|CollectionScheme|iterable|null $source): array
    {
        $vocabulary = match (true) {
            $source instanceof IndexVocabulary => $source,
            $source instanceof CollectionScheme => IndexVocabulary::of([$source]),
            $source === null => IndexVocabulary::of([]),
            default => IndexVocabulary::of($source),
        };

        // Base fields always present
        $properties = [
            'id' => ['type' => 'keyword'],
            'collection_id' => ['type' => 'integer'],
            'organization_id' => ['type' => 'keyword'],
            'type' => ['type' => 'keyword'],
            'name' => ['type' => 'text', 'fields' => ['keyword' => ['type' => 'keyword']]],
            'description' => ['type' => 'text'],
            'state' => ['type' => 'keyword'],
            'created_at' => ['type' => 'date'],
            'updated_at' => ['type' => 'date'],
            'metadata' => ['type' => 'object', 'dynamic' => true, 'properties' => []],
            'workspace_ids' => ['type' => 'integer'],
            'semantic_tag_ids' => ['type' => 'integer'],
            // Phase 1 — Tika extraction
            'extracted_text' => ['type' => 'text', 'analyzer' => 'standard'],
            'tika_metadata' => ['type' => 'object', 'dynamic' => true],
            // Resource-level mean embedding (§8) — the canonical semantic vector
            'embedding' => [
                'type' => 'dense_vector',
                'dims' => (int) config('embedding.dimensions', 768),
                'index' => true,
                'similarity' => 'cosine',
            ],
            // Parent-child join — resource is parent, annotations is child
            'relation' => ['type' => 'join', 'relations' => ['resource' => 'annotations']],
            // Annotation child fields (resolved labels for field-scoped search)
            'tag_labels' => ['type' => 'text'],
            'tag_types' => ['type' => 'keyword'],
            'workspace_labels' => ['type' => 'text'],
            'ai_description' => ['type' => 'text'],
        ];

        $properties['metadata']['properties'] = $vocabulary->properties();

        // Remove empty properties key if no scheme fields
        if (empty($properties['metadata']['properties'])) {
            unset($properties['metadata']['properties']);
        }

        return ['properties' => $properties];
    }

    // =========================================================================
    // CHUNKS INDEX (Phase 2 — vector embeddings)
    // =========================================================================

    /**
     * Derive the companion chunks index name from the base resource index name.
     * e.g. "tydal_multimedia" → "tydal_multimedia_chunks"
     */
    public function buildChunksIndexName(string $baseIndexName): string
    {
        return $baseIndexName.'_chunks';
    }

    /**
     * Keyword (BM25) search over chunk content across one or more chunk
     * indices, constrained to a resource-id set — the vault-scoped
     * `search?scope=chunks` operation. Semantic (k-NN) chunk search arrives
     * with the per-vault indexes in Milestone 3.
     *
     * @param  list<string>  $chunksIndices
     * @param  list<string>  $resourceIds
     * @return list<array<string, mixed>>
     */
    public function searchChunksKeyword(array $chunksIndices, string $q, array $resourceIds, int $limit = 20): array
    {
        if ($chunksIndices === [] || $resourceIds === [] || trim($q) === '') {
            return [];
        }

        try {
            $response = $this->client->search([
                'index' => implode(',', $chunksIndices),
                'ignore_unavailable' => true,
                'body' => [
                    'query' => [
                        'bool' => [
                            'must' => [['match' => ['content' => $q]]],
                            'filter' => [['terms' => ['resource_id' => $resourceIds]]],
                        ],
                    ],
                    'size' => $limit,
                    '_source' => ['chunk_id', 'file_id', 'resource_id', 'sequence', 'page_number', 'content'],
                ],
            ]);
        } catch (\Throwable $e) {
            Log::warning('searchChunksKeyword failed: '.$e->getMessage());

            return [];
        }

        return array_map(
            fn ($hit) => array_merge($hit['_source'], ['score' => $hit['_score']]),
            $response->asArray()['hits']['hits'] ?? []
        );
    }

    /**
     * Create or update the chunks ES index for a given SearchIndex.
     * The mapping includes a dense_vector field for k-NN search.
     *
     * IMPORTANT: If you change EMBEDDING_DIMENSIONS or switch models, run
     * `search:setup-indices --recreate` and then `search:embed` to rebuild.
     */
    public function setupChunksIndex(SearchIndex $searchIndex, bool $recreate = false): void
    {
        $indexName = $this->buildChunksIndexName($searchIndex->index_name);
        $dims = (int) config('embedding.dimensions', 768);

        $mappings = [
            'properties' => [
                'chunk_id' => ['type' => 'keyword'],
                'resource_id' => ['type' => 'keyword'],
                'file_id' => ['type' => 'keyword'],
                'collection_id' => ['type' => 'integer'],
                'sequence' => ['type' => 'integer'],
                'page_number' => ['type' => 'integer'],
                'content' => ['type' => 'text', 'analyzer' => 'standard'],
                'vector' => [
                    'type' => 'dense_vector',
                    'dims' => $dims,
                    'index' => true,
                    'similarity' => 'cosine',
                ],
            ],
        ];

        $settings = [
            'number_of_shards' => config('elasticsearch.index_settings.number_of_shards', 1),
            'number_of_replicas' => config('elasticsearch.index_settings.number_of_replicas', 0),
        ];

        $exists = $this->client->indices()->exists(['index' => $indexName])->asBool();

        if ($exists && $recreate) {
            $this->client->indices()->delete(['index' => $indexName]);
            $exists = false;
        }

        if ($exists) {
            $this->client->indices()->putMapping([
                'index' => $indexName,
                'body' => $mappings,
            ]);
        } else {
            $this->client->indices()->create([
                'index' => $indexName,
                'body' => ['settings' => $settings, 'mappings' => $mappings],
            ]);
        }
    }

    /**
     * Insert or overwrite a chunk vector document.
     * The chunk UUID is used as the ES document ID for idempotent upserts.
     *
     * `$refresh` makes the document searchable before returning — needed when
     * the caller reads it straight back (the metadata chunk feeds the resource
     * mean recalculation in the same job run); leave it off for bulk content
     * writes where the near-real-time delay is harmless.
     */
    public function upsertChunkVector(string $chunksIndexName, array $chunkDoc, bool $refresh = false): void
    {
        $this->client->index([
            'index' => $chunksIndexName,
            'id' => $chunkDoc['chunk_id'],
            'body' => $chunkDoc,
            'refresh' => $refresh,
        ]);
    }

    /**
     * Remove all chunk vector documents belonging to a specific source file.
     * Called when a file is deleted or re-extracted.
     */
    public function invalidateChunksByFileId(string $chunksIndexName, string $fileId): void
    {
        try {
            $this->client->deleteByQuery([
                'index' => $chunksIndexName,
                'body' => [
                    'query' => ['term' => ['file_id' => $fileId]],
                ],
            ]);
        } catch (\Throwable $e) {
            // Index may not exist yet (e.g. embeddings not set up) — not an error
            Log::warning("ES invalidateChunksByFileId failed for file {$fileId}: ".$e->getMessage());
        }
    }

    /**
     * Check whether a chunks index exists (used to gate hybrid search).
     */
    public function chunksIndexExists(string $chunksIndexName): bool
    {
        return $this->client->indices()->exists(['index' => $chunksIndexName])->asBool();
    }

    /**
     * Read the live `dims` of the chunks index's dense_vector field from its
     * mapping. Returns null if the index or field doesn't exist. Used to detect
     * a config ↔ index drift (EMBEDDING_DIMENSIONS changed without --recreate).
     */
    public function getChunksVectorDims(string $chunksIndexName): ?int
    {
        try {
            if (! $this->chunksIndexExists($chunksIndexName)) {
                return null;
            }
            $mapping = $this->client->indices()
                ->getMapping(['index' => $chunksIndexName])
                ->asArray();

            $dims = $mapping[$chunksIndexName]['mappings']['properties']['vector']['dims'] ?? null;

            return $dims !== null ? (int) $dims : null;
        } catch (\Throwable $e) {
            Log::warning("ES getChunksVectorDims failed for {$chunksIndexName}: ".$e->getMessage());

            return null;
        }
    }

    /**
     * Approximate k-NN search over the chunks index.
     * Returns deduplicated resource IDs ranked by their highest chunk score.
     *
     * @param  float[]  $queryVector
     * @return array [['resource_id' => string, 'score' => float], ...]
     */
    public function knnSearchChunks(
        string $chunksIndexName,
        int $collectionId,
        array $queryVector,
        int $k = 20
    ): array {
        try {
            $response = $this->client->search([
                'index' => $chunksIndexName,
                'body' => [
                    'knn' => [
                        'field' => 'vector',
                        'query_vector' => $queryVector,
                        'k' => $k,
                        'num_candidates' => $k * 5,
                        'filter' => ['term' => ['collection_id' => $collectionId]],
                    ],
                    'size' => $k,
                    '_source' => ['resource_id'],
                ],
            ]);
        } catch (\Throwable $e) {
            Log::warning("k-NN search failed on {$chunksIndexName}: ".$e->getMessage());

            return [];
        }

        $hits = $response->asArray()['hits']['hits'] ?? [];

        // Deduplicate by resource_id, keeping highest score
        $byResource = [];
        foreach ($hits as $hit) {
            $rid = $hit['_source']['resource_id'];
            $score = $hit['_score'] ?? 0;
            if (! isset($byResource[$rid]) || $score > $byResource[$rid]) {
                $byResource[$rid] = $score;
            }
        }

        $results = [];
        foreach ($byResource as $rid => $score) {
            $results[] = ['resource_id' => $rid, 'score' => $score];
        }

        usort($results, fn ($a, $b) => $b['score'] <=> $a['score']);

        return $results;
    }

    /**
     * k-NN over RESOURCE mean embeddings (M1) in the base collection
     * indices — nearest-neighbor resources, not chunks. Used by the graph
     * builder (Epic 4.3) to propose `semantic` relations.
     *
     * @param  string[]  $indexNames
     * @param  float[]  $queryVector
     * @param  string[]  $excludeIds
     * @return array<int, array{resource_id: string, score: float}>
     */
    public function knnSearchResources(
        array $indexNames,
        array $queryVector,
        int $k,
        string $organizationId,
        array $excludeIds = []
    ): array {
        if ($indexNames === [] || $queryVector === []) {
            return [];
        }

        $filter = [
            ['term' => ['organization_id' => $organizationId]],
            ['term' => ['state' => ResourceState::LIVE->value]],
        ];

        try {
            $response = $this->client->search([
                'index' => implode(',', $indexNames),
                'ignore_unavailable' => true,
                'body' => [
                    'knn' => [
                        'field' => 'embedding',
                        'query_vector' => $queryVector,
                        'k' => $k,
                        'num_candidates' => $k * 5,
                        'filter' => ['bool' => [
                            'filter' => $filter,
                            'must_not' => $excludeIds === [] ? [] : [['ids' => ['values' => $excludeIds]]],
                        ]],
                    ],
                    'size' => $k,
                    '_source' => ['id'],
                ],
            ]);
        } catch (\Throwable $e) {
            Log::warning('knnSearchResources failed: '.$e->getMessage());

            return [];
        }

        return array_map(
            fn ($hit) => ['resource_id' => $hit['_source']['id'], 'score' => (float) ($hit['_score'] ?? 0)],
            $response->asArray()['hits']['hits'] ?? []
        );
    }

    /**
     * k-NN search over the chunks index for RAG context retrieval.
     *
     * Unlike knnSearchChunks() (which deduplicates by resource for ranking),
     * this method returns individual chunks with full content — needed for RAG context.
     *
     * @param  float[]  $queryVector
     * @return array [['chunk_id', 'resource_id', 'file_id', 'page_number', 'content'], ...]
     */
    public function knnSearchChunksForRag(
        string $chunksIndexName,
        int $collectionId,
        array $queryVector,
        int $k = 5,
        ?float $minScore = null
    ): array {
        try {
            $response = $this->client->search([
                'index' => $chunksIndexName,
                'body' => [
                    'knn' => [
                        'field' => 'vector',
                        'query_vector' => $queryVector,
                        'k' => $k,
                        'num_candidates' => $k * 5,
                        'filter' => ['term' => ['collection_id' => $collectionId]],
                    ],
                    'size' => $k,
                    '_source' => ['chunk_id', 'resource_id', 'file_id', 'page_number', 'content'],
                ],
            ]);
        } catch (\Throwable $e) {
            Log::warning("knnSearchChunksForRag failed on {$chunksIndexName}: ".$e->getMessage());

            return [];
        }

        return $this->filterRagChunks($response->asArray()['hits']['hits'] ?? [], $minScore);
    }

    /**
     * k-NN search over multiple chunks indices filtered by an explicit list of resource IDs.
     * Used for workspace RAG where workspace_ids are not stored on chunk documents.
     *
     * Returns individual chunks with full content (same shape as knnSearchChunksForRag).
     *
     * @param  string[]  $chunksIndexNames
     * @param  string[]  $resourceIds
     * @param  float[]  $queryVector
     * @return array [['chunk_id', 'resource_id', 'file_id', 'page_number', 'content'], ...]
     */
    public function knnSearchChunksForWorkspace(
        array $chunksIndexNames,
        array $resourceIds,
        array $queryVector,
        int $k = 5,
        bool $applyRagFilters = true,
        ?float $minScore = null
    ): array {
        if (empty($chunksIndexNames) || empty($resourceIds)) {
            return [];
        }

        try {
            $response = $this->client->search([
                'index' => implode(',', $chunksIndexNames),
                'body' => [
                    'knn' => [
                        'field' => 'vector',
                        'query_vector' => $queryVector,
                        'k' => $k,
                        'num_candidates' => $k * 5,
                        'filter' => ['terms' => ['resource_id' => array_values($resourceIds)]],
                    ],
                    'size' => $k,
                    '_source' => ['chunk_id', 'resource_id', 'file_id', 'sequence', 'page_number', 'content'],
                ],
            ]);
        } catch (\Throwable $e) {
            Log::warning('knnSearchChunksForWorkspace failed: '.$e->getMessage());

            return [];
        }

        $rawHits = $response->asArray()['hits']['hits'] ?? [];

        // The raw form serves retrieval surfaces (vault /search?scope=chunks):
        // every scored hit goes out, the consumer picks its own cutoff. The
        // filtered form is the internal ask heads' context contract.
        if (! $applyRagFilters) {
            return array_map(fn ($h) => $h['_source'] + ['score' => $h['_score']], $rawHits);
        }

        return $this->filterRagChunks($rawHits, $minScore);
    }

    /**
     * Fetch all meta chunks (chunk_id prefix "meta-") for a collection.
     * Used to guarantee every resource's tags/description appear in RAG context
     * regardless of k-NN ranking.
     *
     * @return array [['chunk_id', 'resource_id', 'file_id', 'page_number', 'content'], ...]
     */
    public function fetchMetaChunksForCollection(string $chunksIndexName, int $collectionId): array
    {
        try {
            $response = $this->client->search([
                'index' => $chunksIndexName,
                'body' => [
                    'query' => [
                        'bool' => [
                            'filter' => [
                                ['term' => ['collection_id' => $collectionId]],
                                ['prefix' => ['chunk_id' => 'meta-']],
                            ],
                        ],
                    ],
                    'size' => 500,
                    '_source' => ['chunk_id', 'resource_id', 'file_id', 'page_number', 'content'],
                ],
            ]);
        } catch (\Throwable $e) {
            Log::warning("fetchMetaChunksForCollection failed on {$chunksIndexName}: ".$e->getMessage());

            return [];
        }

        return array_map(
            fn ($hit) => $hit['_source'],
            $response->asArray()['hits']['hits'] ?? []
        );
    }

    /**
     * Fetch all meta chunks for an explicit list of resource IDs across multiple indices.
     * Used for workspace-scoped RAG to guarantee coverage of every resource's metadata.
     *
     * @param  string[]  $chunksIndexNames
     * @param  string[]  $resourceIds
     * @return array [['chunk_id', 'resource_id', 'file_id', 'page_number', 'content'], ...]
     */
    public function fetchMetaChunksForResources(array $chunksIndexNames, array $resourceIds): array
    {
        if (empty($chunksIndexNames) || empty($resourceIds)) {
            return [];
        }

        $metaIds = array_values(array_map(fn ($id) => 'meta-'.$id, $resourceIds));

        try {
            $response = $this->client->search([
                'index' => implode(',', $chunksIndexNames),
                'body' => [
                    'query' => ['terms' => ['chunk_id' => $metaIds]],
                    'size' => count($metaIds),
                    '_source' => ['chunk_id', 'resource_id', 'file_id', 'page_number', 'content'],
                ],
            ]);
        } catch (\Throwable $e) {
            Log::warning('fetchMetaChunksForResources failed: '.$e->getMessage());

            return [];
        }

        return array_map(
            fn ($hit) => $hit['_source'],
            $response->asArray()['hits']['hits'] ?? []
        );
    }

    /**
     * List a resource's chunks in reading order — a plain term + sort query,
     * no k-NN, no vectors returned. For direct raw-text access (e.g. an AI
     * agent wanting the source text) as opposed to semantic retrieval.
     */
    public function listChunksForResource(string $chunksIndexName, string $resourceId, int $limit = 200): array
    {
        try {
            $response = $this->client->search([
                'index' => $chunksIndexName,
                'body' => [
                    'query' => ['term' => ['resource_id' => $resourceId]],
                    'sort' => ['sequence' => 'asc'],
                    'size' => $limit,
                    '_source' => ['chunk_id', 'file_id', 'sequence', 'page_number', 'content'],
                ],
            ]);
        } catch (\Throwable $e) {
            Log::warning('listChunksForResource failed: '.$e->getMessage());

            return [];
        }

        $hits = array_map(fn ($hit) => $hit['_source'], $response->asArray()['hits']['hits'] ?? []);

        // Exclude the synthetic name+description chunk (id prefixed "meta-") —
        // it's not real document content, it just makes the resource's
        // metadata searchable via k-NN.
        return array_values(array_filter(
            $hits,
            fn ($chunk) => ! str_starts_with($chunk['chunk_id'] ?? '', 'meta-')
        ));
    }

    /**
     * Hybrid search: BM25 (resource index) + k-NN (chunks index) merged via RRF.
     *
     * Returns the same shape as searchResources() — {ids, total, facets}.
     * Pagination applies to the merged result list.
     *
     * @param  float[]  $queryVector  Embedding of the search query
     */
    public function hybridSearch(
        string $indexName,
        int $collectionId,
        string $query,
        array $facetFilters,
        array $facetFields,
        int $page,
        int $perPage,
        array $queryVector,
        array $wsIdToName = [],
        array $stIdToLabel = [],
        string $searchMode = 'prefix'
    ): array {
        // Run both searches in sequence (parallel not available via this client)
        $bm25 = $this->searchResources($indexName, $collectionId, $query, $facetFilters, $facetFields, 1, 100, 'updated_at', 'desc', $searchMode, $wsIdToName, $stIdToLabel);
        $chunksIndex = $this->buildChunksIndexName($indexName);
        $knn = $this->knnSearchChunks($chunksIndex, $collectionId, $queryVector, 50);

        // Exclude semantically distant chunks before RRF.
        // ES cosine similarity is reported as (1 + cosine) / 2, so 0.6 ≈ cosine=0.2.
        // Without this, k=50 on a tiny collection returns ALL chunks regardless of relevance,
        // and every document ends up with a non-zero RRF score.
        $minKnnScore = (float) config('elasticsearch.hybrid_min_knn_score', 0.6);
        $knn = array_values(array_filter($knn, fn ($item) => $item['score'] >= $minKnnScore));

        // Reciprocal Rank Fusion (k=60 is the standard default)
        $rrfK = 60;
        $scores = [];

        foreach (array_values($bm25['ids']) as $rank => $rid) {
            $scores[$rid] = ($scores[$rid] ?? 0) + 1 / ($rrfK + $rank + 1);
        }
        foreach (array_values($knn) as $rank => $item) {
            $rid = $item['resource_id'];
            $scores[$rid] = ($scores[$rid] ?? 0) + 1 / ($rrfK + $rank + 1);
        }

        arsort($scores);
        $allIds = array_keys($scores);

        // Build aggregations — same structure as searchResources()
        $aggs = [];
        foreach ($facetFields as $field) {
            $fieldName = $field['name'];
            $esField = $fieldName === 'type' ? 'type' : "metadata.{$fieldName}";
            $aggs[$fieldName] = ['terms' => ['field' => $esField, 'size' => 50]];
        }
        $aggs['workspaces'] = ['terms' => ['field' => 'workspace_ids',    'size' => 50]];
        $aggs['semantic_tags'] = ['terms' => ['field' => 'semantic_tag_ids', 'size' => 100]];

        // k-NN bypasses facet filters, so apply them post-RRF and compute accurate
        // facet counts over the filtered merged set — one combined ES call.
        $filteredIds = $allIds;
        $facets = $bm25['facets'];

        if (! empty($allIds)) {
            // Start with an ids filter scoped to the RRF result set
            $postFilter = [['ids' => ['values' => $allIds]]];
            // Mirror the filter-building logic from searchResources()
            foreach ($facetFilters as $key => $values) {
                if (empty($values)) {
                    continue;
                }
                if ($key === 'type') {
                    $postFilter[] = ['terms' => ['type' => array_values($values)]];
                } elseif ($key === 'workspaces') {
                    $postFilter[] = ['terms' => ['workspace_ids' => array_values($values)]];
                } elseif ($key === 'semantic_tags') {
                    $postFilter[] = ['terms' => ['semantic_tag_ids' => array_values($values)]];
                } else {
                    $postFilter[] = ['terms' => ["metadata.{$key}" => array_values($values)]];
                }
            }

            try {
                $filterAggResp = $this->client->search([
                    'index' => $indexName,
                    'body' => [
                        'query' => ['bool' => ['filter' => $postFilter]],
                        'size' => count($allIds),
                        '_source' => false,
                        'aggs' => $aggs,
                    ],
                ]);
                $body = $filterAggResp->asArray();
                $passingIds = array_column($body['hits']['hits'] ?? [], '_id');
                $passingSet = array_flip($passingIds);
                // Preserve RRF order while removing filter-failing docs
                $filteredIds = array_values(array_filter($allIds, fn ($id) => isset($passingSet[$id])));
                $facets = $this->parseFacets($body['aggregations'] ?? [], $facetFields, $wsIdToName, $stIdToLabel);
            } catch (\Throwable $e) {
                Log::warning('hybridSearch: post-hoc filter+aggregation failed: '.$e->getMessage());
            }
        }

        $total = count($filteredIds);
        $pagedIds = array_slice($filteredIds, ($page - 1) * $perPage, $perPage);

        return [
            'ids' => $pagedIds,
            'total' => $total,
            'facets' => $facets,
            'has_lexical_matches' => ! empty($bm25['ids']),
        ];
    }

    // =========================================================================
    // REINDEX
    // =========================================================================

    /**
     * Reindex all (or a specific collection's) resources from MySQL.
     *
     * @param  int|null  $collectionId  Pass null to reindex everything
     * @return array{indexed: int, skipped: int, failed: int}
     */
    public function reindexAll(?int $collectionId = null): array
    {
        $indexed = $skipped = $failed = $annotationsFailed = 0;

        $query = Resource::with([
            'collection',
            'canonicalFile',
            'files',
            'systemFiles' => fn ($q) => $q->where('is_active', true),
            'workspaces',
            'semanticTags',
        ])->where('state', ResourceState::LIVE->value);
        if ($collectionId !== null) {
            $query->where('collection_id', $collectionId);
        }

        $query->chunkById(200, function ($resources) use (&$indexed, &$skipped, &$failed, &$annotationsFailed) {
            foreach ($resources as $resource) {
                $indexName = $this->resolveIndexName($resource);
                if (! $indexName) {
                    $skipped++;

                    continue;
                }

                try {
                    $this->client->index([
                        'index' => $indexName,
                        'id' => $resource->id,
                        'body' => $this->buildDocument($resource),
                    ]);
                    $indexed++;
                } catch (\Throwable $e) {
                    Log::error("Reindex failed for {$resource->id}: ".$e->getMessage());
                    $failed++;

                    continue;
                }

                try {
                    $this->client->index([
                        'index' => $indexName,
                        'id' => $resource->id.'#annotations',
                        'routing' => $resource->id,
                        'body' => $this->buildAnnotationDocument($resource),
                    ]);
                } catch (\Throwable $e) {
                    Log::error("Reindex annotation failed for {$resource->id}: ".$e->getMessage());
                    $annotationsFailed++;
                }
            }
        });

        return compact('indexed', 'skipped', 'failed', 'annotationsFailed');
    }

    /**
     * List every resource document in an index as id → updated_at (ISO8601).
     * Annotation child docs (id contains '#') are skipped. Capped at 10 000
     * docs — the reconcile command warns when the cap is hit.
     *
     * @return array<string, string|null>
     */
    public function listIndexedResources(string $indexName): array
    {
        try {
            $response = $this->client->search([
                'index' => $indexName,
                'ignore_unavailable' => true,
                'body' => [
                    'query' => ['match_all' => new \stdClass],
                    'size' => 10000,
                    '_source' => ['updated_at'],
                ],
            ]);
        } catch (\Throwable $e) {
            Log::warning('listIndexedResources failed: '.$e->getMessage());

            return [];
        }

        $map = [];
        foreach ($response->asArray()['hits']['hits'] ?? [] as $hit) {
            if (str_contains($hit['_id'], '#')) {
                continue;
            }
            $map[$hit['_id']] = $hit['_source']['updated_at'] ?? null;
        }

        return $map;
    }

    /**
     * Chunk ids currently in a chunks index, mapped to their owning file and
     * resource — the ES side of chunk-drift reconciliation (search:reconcile).
     * Meta chunks (chunk_id "meta-…") are included; the caller separates them.
     * Same 10 000-doc listing cap as listIndexedResources.
     *
     * @return array<string, array{file_id: string, resource_id: string}>
     */
    public function listIndexedChunks(string $chunksIndexName): array
    {
        try {
            $response = $this->client->search([
                'index' => $chunksIndexName,
                'ignore_unavailable' => true,
                'body' => [
                    'query' => ['match_all' => new \stdClass],
                    'size' => 10000,
                    '_source' => ['chunk_id', 'file_id', 'resource_id'],
                ],
            ]);
        } catch (\Throwable $e) {
            Log::warning('listIndexedChunks failed: '.$e->getMessage());

            return [];
        }

        $map = [];
        foreach ($response->asArray()['hits']['hits'] ?? [] as $hit) {
            $source = $hit['_source'];
            $map[$source['chunk_id'] ?? $hit['_id']] = [
                'file_id' => $source['file_id'] ?? '',
                'resource_id' => $source['resource_id'] ?? '',
            ];
        }

        return $map;
    }

    /**
     * Fetch the synthetic metadata chunk's vector for a resource (chunk_id
     * "meta-{id}"), or null when absent. Fallback contributor for the resource
     * mean embedding when no content chunk vectors exist — a chunkless
     * resource (image, un-transcribed audio) still has its identity in vector
     * space through this chunk.
     */
    public function fetchMetaChunkVector(string $chunksIndexName, string $resourceId): ?array
    {
        try {
            $response = $this->client->search([
                'index' => $chunksIndexName,
                'ignore_unavailable' => true,
                'body' => [
                    'query' => ['term' => ['chunk_id' => 'meta-'.$resourceId]],
                    'size' => 1,
                    '_source' => ['vector'],
                ],
            ]);
        } catch (\Throwable $e) {
            Log::warning('fetchMetaChunkVector failed: '.$e->getMessage());

            return null;
        }

        return $response->asArray()['hits']['hits'][0]['_source']['vector'] ?? null;
    }

    /**
     * Fetch the stored chunk vectors of specific files of a resource —
     * the inputs of the resource-level mean embedding (§8).
     *
     * @param  list<string>  $fileIds
     * @return list<list<float>>
     */
    public function fetchChunkVectors(string $chunksIndexName, string $resourceId, array $fileIds): array
    {
        if ($fileIds === []) {
            return [];
        }

        try {
            $response = $this->client->search([
                'index' => $chunksIndexName,
                'ignore_unavailable' => true,
                'body' => [
                    'query' => [
                        'bool' => [
                            'filter' => [
                                ['term' => ['resource_id' => $resourceId]],
                                ['terms' => ['file_id' => $fileIds]],
                            ],
                        ],
                    ],
                    'size' => 10000,
                    '_source' => ['vector'],
                ],
            ]);
        } catch (\Throwable $e) {
            Log::warning('fetchChunkVectors failed: '.$e->getMessage());

            return [];
        }

        $vectors = [];
        foreach ($response->asArray()['hits']['hits'] ?? [] as $hit) {
            if (! empty($hit['_source']['vector'])) {
                $vectors[] = $hit['_source']['vector'];
            }
        }

        return $vectors;
    }

    /**
     * Force a refresh so writes become searchable immediately — tests and
     * post-rebuild consistency.
     */
    public function refreshIndex(string $indexName): void
    {
        try {
            $this->client->indices()->refresh(['index' => $indexName]);
        } catch (\Throwable $e) {
            Log::warning("ES refresh failed for {$indexName}: ".$e->getMessage());
        }
    }

    /**
     * Drop an index entirely (404-tolerant) — test cleanup and index
     * lifecycle tooling.
     */
    public function deleteIndex(string $indexName): void
    {
        try {
            $this->client->indices()->delete(['index' => $indexName]);
        } catch (ClientResponseException $e) {
            if ($e->getCode() !== 404) {
                throw $e;
            }
        }
    }

    /**
     * Resolve a wildcard pattern (e.g. "tydal_*,vault_*") to concrete index
     * names and delete each individually. ES's `indices.delete` rejects
     * wildcards outright by default (`action.destructive_requires_name`) —
     * `deleteIndex()` alone can't do this. Returns the names actually deleted.
     *
     * @return list<string>
     */
    public function deleteIndicesMatching(string $pattern): array
    {
        try {
            $names = array_keys($this->client->indices()->get([
                'index' => $pattern,
                'ignore_unavailable' => true,
            ])->asArray());
        } catch (ClientResponseException $e) {
            if ($e->getCode() === 404) {
                return [];
            }
            throw $e;
        }

        foreach ($names as $name) {
            $this->deleteIndex($name);
        }

        return $names;
    }

    /**
     * Delete one document (and its annotation child) directly by index name —
     * for reconciling orphans whose MySQL row no longer exists, where
     * deleteResource() cannot resolve the index anymore.
     */
    public function purgeDocument(string $indexName, string $docId): void
    {
        foreach ([['id' => $docId], ['id' => $docId.'#annotations', 'routing' => $docId]] as $params) {
            try {
                $this->client->delete(array_merge(['index' => $indexName], $params));
            } catch (ClientResponseException $e) {
                if ($e->getCode() !== 404) {
                    Log::error("ES purge failed for {$docId} in {$indexName}: ".$e->getMessage());
                }
            } catch (\Throwable $e) {
                Log::error("ES purge failed for {$docId} in {$indexName}: ".$e->getMessage());
            }
        }
    }

    // =========================================================================
    // PER-VAULT INDEXES (Epic 3.2)
    // =========================================================================

    /**
     * ES mapping type per presentation slot (VaultSchemaResolver vocabulary).
     * The slot — not the field name — decides how a value is indexed inside
     * a vault: facet-ish slots aggregate, text-ish slots full-text match.
     * `image` and `hidden` are not indexed at all.
     */
    private const VAULT_SLOT_ES = [
        'badge' => ['type' => 'keyword'],
        'facet' => ['type' => 'keyword'],
        'property' => ['type' => 'keyword'],
        'link_source' => ['type' => 'keyword'],
        'caption' => ['type' => 'text', 'fields' => ['keyword' => ['type' => 'keyword']]],
        'node_label' => ['type' => 'text', 'fields' => ['keyword' => ['type' => 'keyword']]],
        'identity' => ['type' => 'text', 'fields' => ['keyword' => ['type' => 'keyword']]],
        'subcaption' => ['type' => 'text'],
        'detail' => ['type' => 'text'],
        // credit is searchable text AND aggregatable (browse-by-author):
        // facets run over the keyword subfield.
        'credit' => ['type' => 'text', 'fields' => ['keyword' => ['type' => 'keyword']]],
        'body' => ['type' => 'text'],
        'retrieval_text' => ['type' => 'text'],
        'context' => ['type' => 'text'],
    ];

    public function buildVaultIndexName(Vault $vault): string
    {
        return 'vault_'.strtolower($vault->id);
    }

    /**
     * ES filter clauses for exact facet-value narrowing (Epic 5.1).
     *
     * @param  array<string, list<string>>  $facetFilters
     * @param  array<string, string>  $facetFields  field → aggregatable ES path
     * @return list<array<string, mixed>>
     */
    private function vaultFacetFilterClauses(array $facetFilters, array $facetFields = []): array
    {
        $clauses = [];
        foreach ($facetFilters as $field => $values) {
            $clauses[] = ['terms' => [$facetFields[$field] ?? "metadata.{$field}" => $values]];
        }

        return $clauses;
    }

    /**
     * Facetable metadata fields of a vault, from the resolved presentation:
     * every scheme field whose slot aggregates. Keyword-mapped slots
     * aggregate on the field itself; `credit` is text with a keyword
     * subfield (searchable prose AND browse-by-author), so it aggregates
     * on `.keyword`.
     *
     * @return array<string, string> field name → aggregatable ES path
     */
    public function vaultFacetFields(array $presentation): array
    {
        $facetSlots = ['badge' => '', 'facet' => '', 'property' => '', 'credit' => '.keyword'];
        $fields = [];

        foreach ($presentation as $block) {
            foreach ($block['fields'] as $name => $slot) {
                if (isset($facetSlots[$slot]) && ! isset($fields[$name])) {
                    $fields[$name] = "metadata.{$name}{$facetSlots[$slot]}";
                }
            }
        }

        return $fields;
    }

    /**
     * Mapping projected from the vault's resolved presentation —
     * Resource → Schema → Vault Overlay → Index.
     */
    public function buildVaultMappings(array $presentation): array
    {
        $metadataProps = [];

        foreach ($presentation as $block) {
            foreach ($block['fields'] as $name => $slot) {
                if (isset(self::VAULT_SLOT_ES[$slot]) && ! isset($metadataProps[$name])) {
                    $metadataProps[$name] = self::VAULT_SLOT_ES[$slot];
                }
            }
        }

        return [
            'properties' => [
                'id' => ['type' => 'keyword'],
                'name' => ['type' => 'text', 'fields' => ['keyword' => ['type' => 'keyword']]],
                'description' => ['type' => 'text'],
                'resource_type' => ['type' => 'keyword'],
                'tags' => ['type' => 'text', 'fields' => ['keyword' => ['type' => 'keyword']]],
                'slug' => ['type' => 'keyword'],
                'url' => ['type' => 'keyword', 'index' => false],
                'path' => ['type' => 'keyword', 'index' => false],
                'preview' => ['type' => 'keyword', 'index' => false],
                'created_at' => ['type' => 'date'],
                'updated_at' => ['type' => 'date'],
                'embedding' => [
                    'type' => 'dense_vector',
                    'dims' => (int) config('embedding.dimensions', 768),
                    'index' => true,
                    'similarity' => 'cosine',
                ],
                // dynamic:false — fields a scheme edit removed are simply ignored
                'metadata' => ['type' => 'object', 'dynamic' => false, 'properties' => $metadataProps],
            ],
        ];
    }

    /**
     * One vault-index document: the Tier 0 identity card plus the slot-mapped
     * metadata values and the resource mean embedding.
     */
    public function buildVaultDocument(Vault $vault, Resource $resource, array $presentation): array
    {
        $card = app(VaultOperationService::class)->resourceCard($vault, $resource);

        $metadata = [];
        foreach ($presentation as $block) {
            foreach ($block['fields'] as $name => $slot) {
                if (isset(self::VAULT_SLOT_ES[$slot]) && isset($resource->metadata[$name])) {
                    $metadata[$name] = $resource->metadata[$name];
                }
            }
        }

        $document = [
            // Vault-scoped id (link hash) — the internal resource UUID must not
            // travel into search hits. The ES document `_id` (set by the caller)
            // stays the resource UUID for upsert/delete; only this in-body,
            // client-facing field is the boundary id.
            'id' => $card['id'],
            'name' => $resource->name,
            'description' => $resource->description,
            'resource_type' => $card['resource_type'],
            'tags' => $card['tags'],
            'slug' => $card['slug'],
            'url' => $card['url'],
            'path' => $card['path'],
            'preview' => $card['preview'],
            'created_at' => $resource->created_at?->toIso8601String(),
            'updated_at' => $resource->updated_at?->toIso8601String(),
            'metadata' => $metadata,
        ];

        if (! empty($resource->embedding)) {
            $document['embedding'] = $resource->embedding;
        }

        return $document;
    }

    /**
     * Full recomposition of a vault's index: drop, recreate from the current
     * resolved presentation, and reindex every projected resource. Returns
     * the number of documents written. Delivery vaults have no presentation
     * and get no index.
     */
    public function reindexVault(Vault $vault): int
    {
        $presentation = app(VaultSchemaResolver::class)->presentationFor($vault);
        $indexName = $this->buildVaultIndexName($vault);

        $this->deleteIndex($indexName);

        if ($presentation === []) {
            return 0;
        }

        $this->client->indices()->create([
            'index' => $indexName,
            'body' => [
                'settings' => [
                    'number_of_shards' => config('elasticsearch.index_settings.number_of_shards', 1),
                    'number_of_replicas' => config('elasticsearch.index_settings.number_of_replicas', 0),
                ],
                'mappings' => $this->buildVaultMappings($presentation),
            ],
        ]);

        $ops = app(VaultOperationService::class);
        $indexed = 0;

        $ops->vaultResourceQuery($vault)->with('semanticTags')->chunkById(100, function ($resources) use ($vault, $presentation, $indexName, &$indexed) {
            foreach ($resources as $resource) {
                try {
                    $this->client->index([
                        'index' => $indexName,
                        'id' => $resource->id,
                        'body' => $this->buildVaultDocument($vault, $resource, $presentation),
                    ]);
                    $indexed++;
                } catch (\Throwable $e) {
                    Log::error("Vault reindex failed for {$resource->id} in {$indexName}: ".$e->getMessage());
                }
            }
        });

        return $indexed;
    }

    /**
     * Live upkeep: refresh this resource's document in every current vault
     * index that projects it. Called from the base indexing job so vault
     * indexes never go stale on ordinary edits.
     */
    public function indexResourceIntoVaults(Resource $resource): void
    {
        $vaults = app(VaultLinkService::class)->reachableProjectionVaults($resource);
        $resolver = app(VaultSchemaResolver::class);

        $reachableIndexes = [];
        foreach ($vaults as $vault) {
            $reachableIndexes[] = $this->buildVaultIndexName($vault);
        }

        // Prune first. Reachability is granted by workspace membership, so a
        // resource that loses a workspace — detached by hand, by a bulk action,
        // or by the workspace being deleted — stops being reachable in that
        // workspace's vaults. Indexing only the vaults it CAN still be seen in
        // would leave the old document in place and the resource publicly
        // projected through a vault that no longer has any claim on it.
        $this->pruneResourceFromUnreachableVaultIndexes($resource->id, $reachableIndexes);

        foreach ($vaults as $vault) {
            if ($vault->indexed_at === null) {
                continue; // stale/absent — the queued rebuild covers it
            }

            try {
                $presentation = $resolver->presentationFor($vault);
                $this->client->index([
                    'index' => $this->buildVaultIndexName($vault),
                    'id' => $resource->id,
                    'body' => $this->buildVaultDocument($vault, $resource, $presentation),
                ]);
            } catch (\Throwable $e) {
                Log::warning("Vault index upkeep failed for {$resource->id}: ".$e->getMessage());
            }
        }
    }

    /**
     * Delete a resource's document from every vault index EXCEPT the ones
     * given — the removal half of vault index upkeep.
     *
     * An empty `$keepIndexes` means "reachable nowhere", which correctly
     * sweeps the resource out of all of them.
     *
     * @param  array<int, string>  $keepIndexes  Vault index names to leave alone.
     */
    public function pruneResourceFromUnreachableVaultIndexes(string $resourceId, array $keepIndexes): void
    {
        // Internal UUIDs address ES `_id`; the source `id` is a vault link hash.
        $must = [['ids' => ['values' => [$resourceId]]]];
        $mustNot = [];

        if (! empty($keepIndexes)) {
            $mustNot[] = ['terms' => ['_index' => array_values(array_unique($keepIndexes))]];
        }

        try {
            $this->client->deleteByQuery([
                'index' => 'vault_*',
                'ignore_unavailable' => true,
                'conflicts' => 'proceed',
                'body' => ['query' => ['bool' => ['must' => $must, 'must_not' => $mustNot]]],
            ]);
        } catch (\Throwable $e) {
            Log::warning("Vault index prune failed for {$resourceId}: ".$e->getMessage());
        }
    }

    /**
     * Remove a resource's document from every vault index (deletion path —
     * the MySQL row may already be gone, so this sweeps by pattern).
     */
    public function removeResourceFromVaultIndexes(string $resourceId): void
    {
        try {
            $this->client->deleteByQuery([
                'index' => 'vault_*',
                'ignore_unavailable' => true,
                // Match the internal document identity, not its public link hash.
                'body' => ['query' => ['ids' => ['values' => [$resourceId]]]],
            ]);
        } catch (\Throwable $e) {
            Log::warning("Vault index removal failed for {$resourceId}: ".$e->getMessage());
        }
    }

    /**
     * Keyword search inside a vault's own index, with facet aggregations
     * from the presentation's aggregating slots. `$facetFilters` narrows to
     * exact metadata values (Epic 5.1 — the filter side of the facets).
     *
     * @param  array<string, list<string>>  $facetFilters
     * @return array{hits: list<array<string, mixed>>, total: int, facets: array<string, array<string, int>>}
     */
    public function searchVaultIndex(Vault $vault, string $q, int $page, int $perPage, array $facetFields, array $facetFilters = []): array
    {
        $must = $q === ''
            ? [['match_all' => new \stdClass]]
            : [[
                'multi_match' => [
                    'query' => $q,
                    'fields' => ['name^2', 'description', 'tags', 'metadata.*'],
                    'lenient' => true,
                ],
            ]];

        $aggs = [];
        foreach ($facetFields as $field => $path) {
            $aggs[$field] = ['terms' => ['field' => $path, 'size' => 25]];
        }

        try {
            $response = $this->client->search([
                'index' => $this->buildVaultIndexName($vault),
                'body' => array_filter([
                    'query' => ['bool' => array_filter([
                        'must' => $must,
                        'filter' => $this->vaultFacetFilterClauses($facetFilters, $facetFields),
                    ])],
                    'from' => ($page - 1) * $perPage,
                    'size' => $perPage,
                    '_source' => ['excludes' => ['embedding']],
                    'aggs' => $aggs ?: null,
                ]),
            ]);
        } catch (\Throwable $e) {
            Log::warning("Vault search failed for {$vault->id}: ".$e->getMessage());

            return ['hits' => [], 'total' => 0, 'facets' => []];
        }

        $body = $response->asArray();

        $facets = [];
        foreach ($body['aggregations'] ?? [] as $field => $agg) {
            foreach ($agg['buckets'] ?? [] as $bucket) {
                $facets[$field][(string) $bucket['key']] = $bucket['doc_count'];
            }
        }

        return [
            'hits' => array_map(fn ($hit) => $hit['_source'], $body['hits']['hits'] ?? []),
            'total' => $body['hits']['total']['value'] ?? 0,
            'facets' => $facets,
        ];
    }

    /**
     * Semantic (k-NN) search inside a vault's own index, over the resource
     * mean embeddings indexed since Epic 3.2. Facet aggregations run over the
     * k nearest documents. Hits carry a `score` (cosine similarity mapped to
     * ES's [0,1] range). `$facetFilters` narrows the k-NN candidates to exact
     * metadata values (Epic 5.1).
     *
     * @param  float[]  $queryVector
     * @param  array<string, list<string>>  $facetFilters
     * @return array{hits: list<array<string, mixed>>, total: int, facets: array<string, array<string, int>>}
     */
    public function knnSearchVaultIndex(Vault $vault, array $queryVector, int $page, int $perPage, array $facetFields, array $facetFilters = []): array
    {
        $k = min(100, $page * $perPage);

        $aggs = [];
        foreach ($facetFields as $field => $path) {
            $aggs[$field] = ['terms' => ['field' => $path, 'size' => 25]];
        }

        try {
            $response = $this->client->search([
                'index' => $this->buildVaultIndexName($vault),
                'body' => array_filter([
                    'knn' => array_filter([
                        'field' => 'embedding',
                        'query_vector' => $queryVector,
                        'k' => $k,
                        'num_candidates' => $k * 5,
                        'filter' => $this->vaultFacetFilterClauses($facetFilters, $facetFields) ?: null,
                    ]),
                    'from' => ($page - 1) * $perPage,
                    'size' => $perPage,
                    '_source' => ['excludes' => ['embedding']],
                    'aggs' => $aggs ?: null,
                ]),
            ]);
        } catch (\Throwable $e) {
            Log::warning("Vault semantic search failed for {$vault->id}: ".$e->getMessage());

            return ['hits' => [], 'total' => 0, 'facets' => []];
        }

        $body = $response->asArray();

        $facets = [];
        foreach ($body['aggregations'] ?? [] as $field => $agg) {
            foreach ($agg['buckets'] ?? [] as $bucket) {
                $facets[$field][(string) $bucket['key']] = $bucket['doc_count'];
            }
        }

        return [
            'hits' => array_map(
                fn ($hit) => $hit['_source'] + ['score' => round($hit['_score'] ?? 0, 4)],
                $body['hits']['hits'] ?? []
            ),
            'total' => $body['hits']['total']['value'] ?? 0,
            'facets' => $facets,
        ];
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    /**
     * Map raw ES k-NN hits to flat chunk arrays (source + score) and apply the
     * RAG score filters — the internal ask paths' retrieval contract.
     *
     * Meta chunks (chunk_id starts with "meta-") are fetched separately by RagService
     * via fetchMetaChunks* and are always included, so they are never passed here.
     *
     * @param  array  $hits  Raw ES hits array ($body['hits']['hits'])
     * @return array Filtered chunk documents, each carrying its `score`
     */
    private function filterRagChunks(array $hits, ?float $minScore = null): array
    {
        return $this->applyRagScoreFilters(
            array_map(fn ($h) => $h['_source'] + ['score' => $h['_score']], $hits),
            $minScore,
        );
    }

    /**
     * RAG context score filters — absolute floor + relative ratio — shared
     * semantics for every ask head. Operates on flat chunk arrays carrying a
     * `score` key, sorted descending (as ES returns them). Public so ask heads
     * apply it at context-assembly time; the raw retrieval surfaces (vault
     * `/search?scope=chunks`, MCP `search_chunks`) intentionally do NOT — an
     * external reasoner gets every scored hit and chooses its own cutoff.
     *
     * `$minScore` overrides the instance-wide floor: the content owner knows
     * their corpus (Vault::ragMinScore() per vault on the boundary head,
     * Organization::aityRagMinScore() for the internal one). Null = config.
     */
    public function applyRagScoreFilters(array $chunks, ?float $minScore = null): array
    {
        if (empty($chunks)) {
            return [];
        }

        $minScore ??= (float) config('elasticsearch.rag_min_score', 0.72);
        $scoreRatio = (float) config('elasticsearch.rag_score_ratio', 0.88);

        // Absolute floor
        $kept = array_values(array_filter($chunks, fn ($c) => ($c['score'] ?? 0.0) >= $minScore));

        if (empty($kept)) {
            return [];
        }

        // Relative filter: keep only chunks within scoreRatio of the best match
        $ratioFloor = ($kept[0]['score'] ?? 0.0) * $scoreRatio;

        return array_values(array_filter($kept, fn ($c) => ($c['score'] ?? 0.0) >= $ratioFloor));
    }

    /**
     * Parse field-scoped query tokens from a raw query string.
     *
     * Recognised prefixes: tag:, workspace:, ai_description:
     * Quoted values are supported: tag:"marie curie"
     * Everything else is returned as the unscoped remainder for standard multi_match.
     *
     * @return array{scoped: array<array{field: string, value: string}>, remainder: string}
     */
    private function parseFieldScopedQuery(string $query): array
    {
        $scoped = [];
        $remainder = $query;

        // Match field:"quoted value" or field:single_token
        preg_match_all('/(\w+):"([^"]+)"|(\w+):(\S+)/', $query, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $field = $match[1] ?: $match[3];
            $value = $match[2] ?: $match[4];
            $scoped[] = ['field' => $field, 'value' => $value];
            $remainder = str_replace($match[0], '', $remainder);
        }

        return ['scoped' => $scoped, 'remainder' => trim($remainder)];
    }

    /**
     * Index the annotation child document for a resource.
     * Safe to call standalone (e.g. from cascade jobs on tag/workspace renames).
     */
    public function indexAnnotations(Resource $resource): void
    {
        $resource->load(['collection', 'workspaces', 'semanticTags']);

        $indexName = $this->resolveIndexName($resource);
        if (! $indexName) {
            return;
        }

        try {
            $this->client->index([
                'index' => $indexName,
                'id' => $resource->id.'#annotations',
                'routing' => $resource->id,
                'body' => $this->buildAnnotationDocument($resource),
            ]);
        } catch (\Throwable $e) {
            Log::error("ES annotation index failed for resource {$resource->id}: ".$e->getMessage());
        }
    }

    private function resolveIndexName(Resource $resource): ?string
    {
        return $resource->collection?->searchIndex?->index_name;
    }

    private function buildDocument(Resource $resource): array
    {
        $extractedText = $this->buildExtractionData($resource);

        $workspaceIds = $resource->relationLoaded('workspaces')
            ? $resource->workspaces
                ->where('is_default', false)
                ->pluck('id')
                ->values()
                ->all()
            : [];

        $semanticTagIds = $resource->relationLoaded('semanticTags')
            ? $resource->semanticTags
                ->where('is_active', true)
                ->pluck('id')
                ->values()
                ->all()
            : [];

        $document = [
            'id' => $resource->id,
            'collection_id' => $resource->collection_id,
            'organization_id' => $resource->organization_id,
            'type' => $resource->type?->value ?? $resource->getRawOriginal('type'),
            'name' => $resource->name,
            'description' => $resource->description,
            'state' => $resource->state->value,
            'metadata' => $resource->metadata ?? [],
            'workspace_ids' => $workspaceIds,
            'semantic_tag_ids' => $semanticTagIds,
            'extracted_text' => $extractedText,
            'tika_metadata' => $resource->promoted_file_metadata,
            'created_at' => $resource->created_at?->toIso8601String(),
            'updated_at' => $resource->updated_at?->toIso8601String(),
            'relation' => ['name' => 'resource'],
        ];

        // dense_vector fields reject null — only present when computed
        if (! empty($resource->embedding)) {
            $document['embedding'] = $resource->embedding;
        }

        return $document;
    }

    private function buildAnnotationDocument(Resource $resource): array
    {
        $tagLabels = $resource->relationLoaded('semanticTags')
            ? $resource->semanticTags->where('is_active', true)->pluck('label')->values()->all()
            : [];

        $tagTypes = $resource->relationLoaded('semanticTags')
            ? $resource->semanticTags->where('is_active', true)->pluck('entity_type')->filter()->values()->all()
            : [];

        $workspaceLabels = $resource->relationLoaded('workspaces')
            ? $resource->workspaces->where('is_default', false)->pluck('name')->values()->all()
            : [];

        return [
            'relation' => ['name' => 'annotations', 'parent' => $resource->id],
            'tag_labels' => $tagLabels,
            'tag_types' => $tagTypes,
            'workspace_labels' => $workspaceLabels,
            'ai_description' => '',
        ];
    }

    /**
     * Build the extracted_text payload for an ES document.
     *
     * Concatenates text from EXTRACTED_TEXT SystemFiles whose source file is the
     * canonical file (role = canonical, is_active = true).
     *
     * tika_metadata is no longer assembled here — it is pre-computed and stored on
     * Resource.promoted_file_metadata by ResourceService::recalculatePromotedMetadata().
     *
     * Uses the already-loaded canonicalFile + systemFiles relations when available,
     * otherwise queries the DB directly (single-resource indexing path).
     */
    private function buildExtractionData(Resource $resource): ?string
    {
        $contributorIds = $this->extractionContributorIds($resource);

        if ($contributorIds === []) {
            return null;
        }

        if ($resource->relationLoaded('systemFiles')) {
            $byFile = $resource->systemFiles
                ->filter(fn ($f) => $f->purpose === SystemFilePurpose::EXTRACTED_TEXT
                    && $f->is_active
                    && in_array($f->source_file_id, $contributorIds, true))
                ->groupBy('source_file_id');
        } else {
            $byFile = $resource->systemFiles()
                ->whereIn('source_file_id', $contributorIds)
                ->where('purpose', SystemFilePurpose::EXTRACTED_TEXT->value)
                ->where('is_active', true)
                ->get()
                ->groupBy('source_file_id');
        }

        // Concatenate in contributor (manifest) order — a multi-component
        // resource reads as one continuous document.
        $allContent = [];
        foreach ($contributorIds as $fileId) {
            foreach (($byFile[$fileId] ?? collect())->sortBy('updated_at') as $textFile) {
                $chunks = FileStorageService::readExtractedText($textFile->path, $textFile->disk);
                if ($chunks) {
                    foreach ($chunks as $chunk) {
                        $allContent[] = $chunk['content'];
                    }
                }
            }
        }

        return ! empty($allContent) ? implode(' ', $allContent) : null;
    }

    /**
     * Files whose text represents the resource (docs/RESOURCE_MODEL.md):
     * the committed active canonical alone, or all committed active
     * components in manifest order. Supporting files contribute nothing to
     * the resource document. Uses loaded relations on the bulk path.
     *
     * @return list<string>
     */
    private function extractionContributorIds(Resource $resource): array
    {
        if ($resource->relationLoaded('canonicalFile')) {
            // canonicalFile relation is already scoped to active + committed
            if ($resource->canonicalFile) {
                return [$resource->canonicalFile->id];
            }

            if ($resource->relationLoaded('files')) {
                return $resource->files
                    ->filter(fn ($f) => $f->role === FileRole::COMPONENT
                        && $f->is_active
                        && $f->uncommitted_at === null)
                    ->sortBy([
                        fn ($a, $b) => ($a->position ?? PHP_INT_MAX) <=> ($b->position ?? PHP_INT_MAX),
                        fn ($a, $b) => $a->created_at <=> $b->created_at,
                    ])
                    ->pluck('id')
                    ->values()
                    ->all();
            }
        }

        return app(ResourceServiceInterface::class)
            ->metadataContributorIds($resource);
    }

    private function parseFacets(array $aggregations, array $facetFields, array $wsIdToName = [], array $stIdToLabel = []): array
    {
        $facets = [];

        foreach ($facetFields as $field) {
            $fieldName = $field['name'];

            if (! isset($aggregations[$fieldName]['buckets'])) {
                continue;
            }

            $values = [];
            foreach ($aggregations[$fieldName]['buckets'] as $bucket) {
                $values[(string) $bucket['key']] = ['count' => $bucket['doc_count']];
            }

            if (! empty($values)) {
                $label = $field['facet_label'] ?? ($field['display_name'] ?? ucfirst(str_replace('_', ' ', $fieldName)));
                $facets[] = [
                    'key' => $fieldName,
                    'label' => $label,
                    'values' => $values,
                ];
            }
        }

        // Workspace facet — translate numeric IDs back to names for the API response
        if (! empty($aggregations['workspaces']['buckets'])) {
            $wsValues = [];
            foreach ($aggregations['workspaces']['buckets'] as $bucket) {
                $id = (int) $bucket['key'];
                $name = $wsIdToName[$id] ?? null;
                if ($name !== null) {
                    $wsValues[$name] = ['count' => $bucket['doc_count']];
                }
            }
            if (! empty($wsValues)) {
                $facets[] = ['key' => 'workspaces', 'label' => 'Workspaces', 'values' => $wsValues];
            }
        }

        // Semantic tags facet — composite key "label||entity_type" so the frontend can render entity type icons
        if (! empty($aggregations['semantic_tags']['buckets'])) {
            $stValues = [];
            foreach ($aggregations['semantic_tags']['buckets'] as $bucket) {
                $id = (int) $bucket['key'];
                $meta = $stIdToLabel[$id] ?? null;   // ['label' => '...', 'entity_type' => '...']
                if ($meta !== null) {
                    $compositeKey = $meta['label'].'||'.($meta['entity_type'] ?? '');
                    $stValues[$compositeKey] = ['count' => $bucket['doc_count']];
                }
            }
            if (! empty($stValues)) {
                $facets[] = ['key' => 'semantic_tags', 'label' => 'Tags', 'values' => $stValues];
            }
        }

        return $facets;
    }
}
