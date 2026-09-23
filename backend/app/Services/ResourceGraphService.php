<?php

namespace App\Services;

use App\Enums\ResourceRelationOrigin;
use App\Enums\ResourceRelationType;
use App\Enums\ResourceState;
use App\Models\Collection;
use App\Models\Resource;
use App\Models\ResourceRelation;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;

/**
 * The resource graph (Epic 4.3): typed, weighted edges between resources,
 * traversal, and the auto-relation builders that feed clustering (Epic 4.5).
 *
 * Edge semantics:
 *  - `related` is symmetric — ONE row per pair, subject/object normalized by
 *    UUID order so A↔B can never exist twice.
 *  - `derived_from` is directed — subject was derived from object.
 *  - vault membership is NOT an edge: it is the vault projection itself.
 *
 * Origins are rebuild domains: `graph:rebuild` drops and recreates only
 * `tags` / `semantic` edges; `manual` (curator) edges are never touched.
 */
class ResourceGraphService
{
    public const MAX_DEPTH = 3;

    public const NODE_CAP = 200;

    /** Tags attached to more resources than this are too generic to imply relation. */
    public const HUB_TAG_CAP = 500;

    // -------------------------------------------------------------------------
    // Edge CRUD
    // -------------------------------------------------------------------------

    /**
     * Create or update an edge. Same-org only, no self-loops; symmetric
     * types are pair-normalized so the unique index holds both directions.
     */
    public function relate(
        Resource $subject,
        Resource $object,
        ResourceRelationType $type,
        ?float $weight = null,
        ResourceRelationOrigin $origin = ResourceRelationOrigin::MANUAL,
        ?string $userId = null,
    ): ResourceRelation {
        if ($subject->id === $object->id) {
            throw new \InvalidArgumentException('A resource cannot relate to itself.');
        }

        if ($subject->organization_id !== $object->organization_id) {
            throw new \InvalidArgumentException('Relations cannot cross organizations.');
        }

        [$subjectId, $objectId] = $type->isSymmetric() && strcmp($subject->id, $object->id) > 0
            ? [$object->id, $subject->id]
            : [$subject->id, $object->id];

        return ResourceRelation::updateOrCreate(
            [
                'subject_resource_id' => $subjectId,
                'object_resource_id' => $objectId,
                'type' => $type->value,
            ],
            [
                'organization_id' => $subject->organization_id,
                'origin' => $origin->value,
                'weight' => $weight,
                'created_by' => $userId,
            ],
        );
    }

    /**
     * Every edge touching the resource, either end.
     *
     * @return SupportCollection<int, ResourceRelation>
     */
    public function edgesFor(Resource $resource): SupportCollection
    {
        return ResourceRelation::query()
            ->where('subject_resource_id', $resource->id)
            ->orWhere('object_resource_id', $resource->id)
            ->orderByDesc('weight')
            ->get();
    }

    /**
     * Incoming edges only — who points AT this resource (`derived_from`
     * children plus symmetric edges stored with it as object).
     *
     * @return SupportCollection<int, ResourceRelation>
     */
    public function backlinks(Resource $resource): SupportCollection
    {
        return ResourceRelation::query()
            ->where('object_resource_id', $resource->id)
            ->orderByDesc('weight')
            ->get();
    }

    // -------------------------------------------------------------------------
    // Traversal
    // -------------------------------------------------------------------------

    /**
     * Multi-hop neighborhood: BFS from the root, depth-capped and
     * node-capped. Symmetric edges traverse both ways; `derived_from`
     * traverses both ways too (ancestry and derivatives are both
     * "the neighborhood") — the edge direction stays visible on the edge.
     *
     * @param  list<ResourceRelationType>|null  $types  null = all types
     * @return array{root: string, depth: int, nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}
     */
    public function graph(Resource $root, int $depth = 1, ?array $types = null, int $nodeCap = self::NODE_CAP): array
    {
        $depth = max(1, min(self::MAX_DEPTH, $depth));
        $typeValues = $types === null ? null : array_map(fn (ResourceRelationType $t) => $t->value, $types);

        $seen = [$root->id => 0];
        $frontier = [$root->id];
        $edges = collect();

        for ($hop = 1; $hop <= $depth && $frontier !== [] && count($seen) < $nodeCap; $hop++) {
            $found = ResourceRelation::query()
                ->where(fn ($q) => $q->whereIn('subject_resource_id', $frontier)
                    ->orWhereIn('object_resource_id', $frontier))
                ->when($typeValues !== null, fn ($q) => $q->whereIn('type', $typeValues))
                ->get();

            $frontier = [];
            foreach ($found as $edge) {
                $edges->put($edge->id, $edge);

                foreach ([$edge->subject_resource_id, $edge->object_resource_id] as $nodeId) {
                    if (! isset($seen[$nodeId]) && count($seen) < $nodeCap) {
                        $seen[$nodeId] = $hop;
                        $frontier[] = $nodeId;
                    }
                }
            }
        }

        $nodes = Resource::query()
            ->whereIn('id', array_keys($seen))
            ->get(['id', 'name', 'type', 'description'])
            ->map(fn (Resource $r) => [
                'id' => $r->id,
                'name' => $r->name,
                'resource_type' => $r->type,
                'distance' => $seen[$r->id],
            ])
            ->sortBy('distance')
            ->values()
            ->all();

        return [
            'root' => $root->id,
            'depth' => $depth,
            'nodes' => $nodes,
            'edges' => $edges->values()
                ->map(fn (ResourceRelation $e) => $this->edgePayload($e))
                ->all(),
        ];
    }

    /**
     * Ranked neighbor ids of one resource (1 hop), heaviest edges first.
     *
     * @return list<string>
     */
    public function neighborIds(Resource $resource, int $limit = 25): array
    {
        return $this->edgesFor($resource)
            ->map(fn (ResourceRelation $e) => $e->otherEnd($resource->id))
            ->unique()
            ->take($limit)
            ->values()
            ->all();
    }

    public function edgePayload(ResourceRelation $edge): array
    {
        return [
            'id' => $edge->id,
            'subject' => $edge->subject_resource_id,
            'object' => $edge->object_resource_id,
            'type' => $edge->type->value,
            'origin' => $edge->origin->value,
            'weight' => $edge->weight,
        ];
    }

    // -------------------------------------------------------------------------
    // Auto-relation builders (rebuild domains — never touch `manual`)
    // -------------------------------------------------------------------------

    /**
     * Tag co-occurrence edges: resources of the org sharing at least
     * $minShared active tags get a `related` edge weighted by Jaccard
     * similarity of their tag sets. Hub tags (attached to more than
     * HUB_TAG_CAP resources) are skipped — too generic to imply relation.
     *
     * Drops and recreates the org's `tags`-origin edges. Returns edge count.
     */
    public function rebuildTagRelations(string $organizationId, int $minShared = 2): int
    {
        $rows = DB::table('semantic_tag_resource')
            ->join('resources', 'resources.id', '=', 'semantic_tag_resource.resource_id')
            ->join('semantic_tags', 'semantic_tags.id', '=', 'semantic_tag_resource.semantic_tag_id')
            ->where('resources.organization_id', $organizationId)
            ->where('resources.state', ResourceState::LIVE->value)
            ->whereNull('resources.deleted_at')
            ->where('semantic_tags.is_active', true)
            ->get(['semantic_tag_resource.resource_id', 'semantic_tag_resource.semantic_tag_id']);

        $tagsByResource = [];
        $resourcesByTag = [];
        foreach ($rows as $row) {
            $tagsByResource[$row->resource_id][] = $row->semantic_tag_id;
            $resourcesByTag[$row->semantic_tag_id][] = $row->resource_id;
        }

        $shared = [];
        foreach ($resourcesByTag as $resourceIds) {
            if (count($resourceIds) < 2 || count($resourceIds) > self::HUB_TAG_CAP) {
                continue;
            }

            sort($resourceIds);
            $n = count($resourceIds);
            for ($i = 0; $i < $n; $i++) {
                for ($j = $i + 1; $j < $n; $j++) {
                    $key = $resourceIds[$i].'|'.$resourceIds[$j];
                    $shared[$key] = ($shared[$key] ?? 0) + 1;
                }
            }
        }

        $edges = [];
        foreach ($shared as $key => $count) {
            if ($count < $minShared) {
                continue;
            }

            [$a, $b] = explode('|', $key);
            $union = count($tagsByResource[$a]) + count($tagsByResource[$b]) - $count;
            $edges[] = ['a' => $a, 'b' => $b, 'weight' => round($count / max(1, $union), 4)];
        }

        return $this->replaceAutoEdges($organizationId, ResourceRelationOrigin::TAGS, $edges);
    }

    /**
     * Semantic edges: for every org resource with a mean embedding (M1),
     * find its k nearest org neighbors in ES (resource-level dense_vector)
     * and relate pairs scoring at least $minScore. Symmetric dedupe keeps
     * the max score. Drops and recreates the org's `semantic`-origin edges.
     */
    public function rebuildSemanticRelations(string $organizationId, int $k = 5, float $minScore = 0.75): int
    {
        $indices = Collection::query()
            ->where('organization_id', $organizationId)
            ->with('searchIndex')
            ->get()
            ->map(fn (Collection $c) => $c->searchIndex?->index_name)
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($indices === []) {
            return $this->replaceAutoEdges($organizationId, ResourceRelationOrigin::SEMANTIC, []);
        }

        $es = app(ElasticsearchService::class);
        $pairs = [];

        Resource::query()
            ->where('organization_id', $organizationId)
            ->where('state', ResourceState::LIVE->value)
            ->whereNotNull('embedding')
            ->chunkById(50, function ($resources) use ($es, $indices, $organizationId, $k, $minScore, &$pairs) {
                foreach ($resources as $resource) {
                    $neighbors = $es->knnSearchResources(
                        $indices,
                        $resource->embedding,
                        $k + 1, // self comes back as the top hit
                        $organizationId,
                        [$resource->id],
                    );

                    foreach ($neighbors as $hit) {
                        if ($hit['score'] < $minScore) {
                            continue;
                        }

                        $key = strcmp($resource->id, $hit['resource_id']) < 0
                            ? $resource->id.'|'.$hit['resource_id']
                            : $hit['resource_id'].'|'.$resource->id;

                        $pairs[$key] = max($pairs[$key] ?? 0, round($hit['score'], 4));
                    }
                }
            });

        $edges = [];
        foreach ($pairs as $key => $weight) {
            [$a, $b] = explode('|', $key);
            $edges[] = ['a' => $a, 'b' => $b, 'weight' => $weight];
        }

        return $this->replaceAutoEdges($organizationId, ResourceRelationOrigin::SEMANTIC, $edges);
    }

    /**
     * Atomically swap one origin's edge set for the org. Pairs arrive
     * pre-deduplicated; ordering is normalized here so the unique index
     * cannot collide with itself, and existing manual/other-origin edges
     * win via insertOrIgnore (the unique triple already being taken means
     * a stronger assertion exists).
     *
     * @param  list<array{a: string, b: string, weight: float}>  $edges
     */
    private function replaceAutoEdges(string $organizationId, ResourceRelationOrigin $origin, array $edges): int
    {
        return DB::transaction(function () use ($organizationId, $origin, $edges) {
            ResourceRelation::query()
                ->where('organization_id', $organizationId)
                ->where('origin', $origin->value)
                ->delete();

            $now = now();
            $rows = [];
            foreach ($edges as $edge) {
                [$s, $o] = strcmp($edge['a'], $edge['b']) > 0 ? [$edge['b'], $edge['a']] : [$edge['a'], $edge['b']];
                $rows[] = [
                    'organization_id' => $organizationId,
                    'subject_resource_id' => $s,
                    'object_resource_id' => $o,
                    'type' => ResourceRelationType::RELATED->value,
                    'origin' => $origin->value,
                    'weight' => $edge['weight'],
                    'created_by' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            $inserted = 0;
            foreach (array_chunk($rows, 500) as $chunk) {
                $inserted += DB::table('resource_relations')->insertOrIgnore($chunk);
            }

            return $inserted;
        });
    }

    // -------------------------------------------------------------------------
    // Clustering (feeds Epic 4.5 — clusters → auto-created vaults)
    // -------------------------------------------------------------------------

    /**
     * Connected components over the org's `related` edges (all origins),
     * union-find in PHP. Returns clusters of 2+ resources, largest first,
     * each with its dominant tags — the raw material Epic 4.5 turns into
     * auto-named cluster vaults.
     *
     * @return list<array{resource_ids: list<string>, size: int, names: list<string>, top_tags: list<string>}>
     */
    public function clusters(string $organizationId): array
    {
        $edges = ResourceRelation::query()
            ->where('organization_id', $organizationId)
            ->where('type', ResourceRelationType::RELATED->value)
            ->get(['subject_resource_id', 'object_resource_id']);

        $parent = [];
        $find = function (string $x) use (&$parent, &$find): string {
            $parent[$x] ??= $x;

            return $parent[$x] === $x ? $x : ($parent[$x] = $find($parent[$x]));
        };

        foreach ($edges as $edge) {
            $parent[$find($edge->subject_resource_id)] = $find($edge->object_resource_id);
        }

        $components = [];
        foreach (array_keys($parent) as $id) {
            $components[$find($id)][] = $id;
        }

        $clusters = collect($components)
            ->filter(fn (array $ids) => count($ids) >= 2)
            ->sortByDesc(fn (array $ids) => count($ids))
            ->values();

        return $clusters->map(function (array $ids) {
            $topTags = DB::table('semantic_tag_resource')
                ->join('semantic_tags', 'semantic_tags.id', '=', 'semantic_tag_resource.semantic_tag_id')
                ->whereIn('semantic_tag_resource.resource_id', $ids)
                ->where('semantic_tags.is_active', true)
                ->groupBy('semantic_tags.id', 'semantic_tags.label')
                ->selectRaw('semantic_tags.label, count(*) as uses')
                ->orderByDesc('uses')
                ->limit(5)
                ->pluck('label')
                ->all();

            return [
                'resource_ids' => $ids,
                'size' => count($ids),
                'names' => Resource::whereIn('id', $ids)->limit(5)->pluck('name')->all(),
                'top_tags' => $topTags,
            ];
        })->all();
    }
}
