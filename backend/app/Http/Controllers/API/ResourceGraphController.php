<?php

namespace App\Http\Controllers\API;

use App\Enums\ResourceRelationType;
use App\Http\Controllers\Controller;
use App\Models\Resource;
use App\Models\ResourceRelation;
use App\Services\ResourceGraphService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The resource graph API (Epic 4.3) — manual edge curation plus the
 * multi-hop neighborhood read. Auto edges (`tags`/`semantic`) are built by
 * `graph:rebuild`, not through here.
 */
class ResourceGraphController extends Controller
{
    public function __construct(private readonly ResourceGraphService $graph) {}

    /**
     * GET /resources/{id}/graph?depth=1&types=related,derived_from
     * The BFS neighborhood: nodes with hop distance + typed edges.
     */
    public function show(string $id, Request $request): JsonResponse
    {
        $resource = Resource::findOrFail($id);
        $this->authorize('view', $resource);

        $types = null;
        if ($request->filled('types')) {
            $types = collect(explode(',', (string) $request->query('types')))
                ->map(fn (string $t) => ResourceRelationType::tryFrom(trim($t)))
                ->filter()
                ->values()
                ->all();

            if ($types === []) {
                return response()->json(['error' => 'No valid relation types given'], 422);
            }
        }

        return response()->json($this->graph->graph(
            $resource,
            depth: max(1, min(ResourceGraphService::MAX_DEPTH, (int) $request->query('depth', '1'))),
            types: $types,
        ));
    }

    /**
     * GET /resources/{id}/relations — flat edge list, both directions,
     * with the other end's identity card.
     */
    public function index(string $id): JsonResponse
    {
        $resource = Resource::findOrFail($id);
        $this->authorize('view', $resource);

        $edges = $this->graph->edgesFor($resource);

        $others = Resource::whereIn('id', $edges->map(fn (ResourceRelation $e) => $e->otherEnd($resource->id)))
            ->get(['id', 'name', 'type'])
            ->keyBy('id');

        return response()->json([
            'resource_id' => $resource->id,
            'relations' => $edges->map(function (ResourceRelation $e) use ($resource, $others) {
                $otherId = $e->otherEnd($resource->id);
                $other = $others->get($otherId);

                return $this->graph->edgePayload($e) + [
                    'direction' => $e->type->isSymmetric()
                        ? 'both'
                        : ($e->subject_resource_id === $resource->id ? 'out' : 'in'),
                    'resource' => $other === null ? null : [
                        'id' => $other->id,
                        'name' => $other->name,
                        'resource_type' => $other->type,
                    ],
                ];
            })->values()->all(),
        ]);
    }

    /**
     * POST /resources/{id}/relations — curator edge. The subject needs
     * update permission; the object only needs to be visible.
     */
    public function store(string $id, Request $request): JsonResponse
    {
        $resource = Resource::findOrFail($id);
        $this->authorize('update', $resource);

        $data = $request->validate([
            'object_id' => ['required', 'string', 'different:subject_id'],
            'type' => ['required', Rule::enum(ResourceRelationType::class)],
            'weight' => ['nullable', 'numeric', 'between:0,1'],
        ]);

        $object = Resource::findOrFail($data['object_id']);
        $this->authorize('view', $object);

        try {
            $edge = $this->graph->relate(
                $resource,
                $object,
                ResourceRelationType::from($data['type']),
                weight: isset($data['weight']) ? (float) $data['weight'] : null,
                userId: $request->user()?->id,
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json($this->graph->edgePayload($edge), 201);
    }

    /**
     * DELETE /resources/{id}/relations/{relationId} — remove an edge
     * touching this resource (any origin: deleting an auto edge is a valid
     * curator judgement until the next rebuild).
     */
    public function destroy(string $id, int $relationId): JsonResponse
    {
        $resource = Resource::findOrFail($id);
        $this->authorize('update', $resource);

        $edge = ResourceRelation::query()
            ->whereKey($relationId)
            ->where(fn ($q) => $q->where('subject_resource_id', $resource->id)
                ->orWhere('object_resource_id', $resource->id))
            ->firstOrFail();

        $edge->delete();

        return response()->json(['message' => 'Relation removed']);
    }
}
