<?php

namespace App\Http\Controllers\API;

use App\Enums\ResourceState;
use App\Enums\SystemFilePurpose;
use App\Enums\TagReviewer;
use App\Enums\TagVocabulary;
use App\Http\Controllers\Controller;
use App\Jobs\IndexAnnotationsToElasticsearch;
use App\Jobs\IndexResourceToElasticsearch;
use App\Jobs\UpsertResourceMetadataChunk;
use App\Models\Resource;
use App\Models\SemanticTag;
use App\Models\SystemFile;
use App\Services\ResourceEventLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * SemanticTag Controller
 *
 * Manages org-scoped semantic tags.
 *
 * GET    /api/v1/semantic-tags          — list all tags for current org (with optional search)
 * POST   /api/v1/semantic-tags          — create a tag
 * PUT    /api/v1/semantic-tags/{id}     — update a tag
 * DELETE /api/v1/semantic-tags/{id}     — delete a tag
 *
 * entity_type is the semantic role of the tag (person, organization, place, thing, tag).
 * Color and icon are derived from entity_type on the frontend — they are NOT stored in the DB.
 */
class SemanticTagController extends Controller
{
    /** Valid entity type keys — must match the frontend ENTITY_TYPES constant. */
    private const ENTITY_TYPES = ['person', 'organization', 'place', 'thing', 'tag'];

    public function __construct(
        private readonly ResourceEventLogger $eventLogger,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $orgId = currentOrganizationId();

        if (! $orgId) {
            return response()->json(['success' => false, 'message' => 'No organization context.'], 400);
        }

        $query = SemanticTag::where('organization_id', $orgId)
            ->where('is_active', true)
            ->orderBy('label');

        if ($search = $request->input('search')) {
            $query->where('label', 'LIKE', "%{$search}%");
        }

        if ($vocabulary = $request->input('vocabulary')) {
            $query->where('vocabulary', $vocabulary);
        }

        if ($reviewer = $request->input('reviewer')) {
            $query->where('reviewer', $reviewer);
        }

        $tags = $query->withCount('resources')->get(['id', 'label', 'slug', 'description', 'entity_type', 'vocabulary', 'reviewer', 'is_active']);

        return response()->json(['success' => true, 'data' => $tags]);
    }

    public function store(Request $request): JsonResponse
    {
        $orgId = currentOrganizationId();

        if (! $orgId) {
            return response()->json(['success' => false, 'message' => 'No organization context.'], 400);
        }

        $validated = $request->validate([
            'label' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'entity_type' => ['nullable', 'string', Rule::in(self::ENTITY_TYPES)],
            'vocabulary' => ['nullable', Rule::enum(TagVocabulary::class)],
            'reviewer' => ['nullable', Rule::enum(TagReviewer::class)],
        ]);

        $entityType = $validated['entity_type'] ?? 'tag';

        // Find-or-create: if a tag with the same (label, org, entity_type) already exists,
        // return it (reactivating if needed) instead of creating a duplicate.
        $existing = SemanticTag::where('organization_id', $orgId)
            ->where('label', $validated['label'])
            ->where('entity_type', $entityType)
            ->first();

        if ($existing) {
            if (! $existing->is_active) {
                $existing->update(['is_active' => true]);
            }

            return response()->json(['success' => true, 'data' => $existing], 200);
        }

        $slug = Str::slug($validated['label']);

        // Ensure slug uniqueness globally (database unique constraint is global)
        $base = $slug;
        $i = 1;
        while (SemanticTag::where('slug', $slug)->exists()) {
            $slug = "{$base}-{$i}";
            $i++;
        }

        $tag = SemanticTag::create([
            'organization_id' => $orgId,
            'label' => $validated['label'],
            'slug' => $slug,
            'description' => $validated['description'] ?? null,
            'entity_type' => $entityType,
            'vocabulary' => $validated['vocabulary'] ?? TagVocabulary::ORGANIZATION->value,
            'reviewer' => $validated['reviewer'] ?? TagReviewer::USER->value,
            'is_active' => true,
        ]);

        return response()->json(['success' => true, 'data' => $tag], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $orgId = currentOrganizationId();
        $tag = SemanticTag::where('organization_id', $orgId)->find($id);

        if (! $tag) {
            return response()->json(['success' => false, 'message' => 'Tag not found.'], 404);
        }

        $incomingEntityType = $request->input('entity_type', $tag->entity_type);
        $validated = $request->validate([
            'label' => [
                'sometimes', 'string', 'max:255',
                Rule::unique('semantic_tags')
                    ->where(fn ($q) => $q
                        ->where('organization_id', $orgId)
                        ->where('entity_type', $incomingEntityType))
                    ->ignore($id),
            ],
            'description' => ['nullable', 'string'],
            'entity_type' => ['nullable', 'string', Rule::in(self::ENTITY_TYPES)],
            'vocabulary' => ['nullable', Rule::enum(TagVocabulary::class)],
            'reviewer' => ['nullable', Rule::enum(TagReviewer::class)],
            'is_active' => ['sometimes', 'boolean'],
        ], [
            'label.unique' => 'A tag with this label and type already exists in your organisation.',
        ]);

        if (isset($validated['label']) && $validated['label'] !== $tag->label) {
            $slug = Str::slug($validated['label']);
            $base = $slug;
            $i = 1;
            while (SemanticTag::where('slug', $slug)->where('id', '!=', $id)->exists()) {
                $slug = "{$base}-{$i}";
                $i++;
            }
            $validated['slug'] = $slug;
        }

        $originalLabel = $tag->label;
        $originalEntityType = $tag->entity_type;

        $tag->update($validated);

        // If label or entity_type changed, re-index annotation docs for all resources using this tag
        $labelChanged = $tag->label !== $originalLabel;
        $entityTypeChanged = $tag->entity_type !== $originalEntityType;

        if ($labelChanged || $entityTypeChanged) {
            $tag->resources()
                ->where('state', ResourceState::LIVE->value)
                ->select('resources.id')
                ->chunkById(100, function ($resources) {
                    foreach ($resources as $r) {
                        IndexAnnotationsToElasticsearch::dispatch($r->id);
                    }
                });
        }

        return response()->json(['success' => true, 'data' => $tag]);
    }

    public function destroy(int $id): JsonResponse
    {
        $orgId = currentOrganizationId();
        $tag = SemanticTag::where('organization_id', $orgId)->find($id);

        if (! $tag) {
            return response()->json(['success' => false, 'message' => 'Tag not found.'], 404);
        }

        // Re-index annotation docs before detaching so we can still find the affected resources
        $tag->resources()
            ->where('state', ResourceState::LIVE->value)
            ->select('resources.id')
            ->chunkById(100, function ($resources) {
                foreach ($resources as $r) {
                    IndexAnnotationsToElasticsearch::dispatch($r->id);
                }
            });

        // Detach from all resources before deleting
        $tag->resources()->detach();
        $tag->delete();

        return response()->json(['success' => true, 'message' => 'Tag deleted.']);
    }

    /**
     * Sync the full set of semantic tags for a resource.
     * Accepts { tag_ids: [1, 2, 3] } — replaces all existing pivot rows.
     */
    public function syncResource(Request $request, string $resourceId): JsonResponse
    {
        $orgId = currentOrganizationId();
        $resource = Resource::where('organization_id', $orgId)->find($resourceId);

        if (! $resource) {
            return response()->json(['success' => false, 'message' => 'Resource not found.'], 404);
        }

        $validated = $request->validate([
            'tag_ids' => ['present', 'array'],
            'tag_ids.*' => ['integer'],
        ]);

        // Only allow tag IDs that belong to the same org
        $allowedIds = SemanticTag::where('organization_id', $orgId)
            ->whereIn('id', $validated['tag_ids'])
            ->pluck('id')
            ->all();

        // Snapshot the previous label set so we can log the diff after sync.
        $beforeLabels = $resource->semanticTags()->pluck('label')->map('strtolower')->sort()->values()->all();

        $resource->semanticTags()->sync($allowedIds);

        $this->recordTagsProvenance($resource, $allowedIds);

        // Emit a tags_updated event if the user's commit actually changed the set.
        // This captures plain tag edits (no AITY suggestion involved); suggestion
        // accept/dismiss events are emitted by recordTagsProvenance itself.
        $afterLabels = SemanticTag::whereIn('id', $allowedIds)->pluck('label')->map('strtolower')->sort()->values()->all();
        if ($beforeLabels !== $afterLabels) {
            $added = array_values(array_diff($afterLabels, $beforeLabels));
            $removed = array_values(array_diff($beforeLabels, $afterLabels));
            $this->eventLogger->log(
                resourceId: $resource->id,
                eventType: 'tags_updated',
                payload: [
                    'added' => array_slice($added, 0, 20),
                    'removed' => array_slice($removed, 0, 20),
                    'total' => count($afterLabels),
                ],
            );
        }

        IndexResourceToElasticsearch::dispatchSync($resource->id);

        // Re-embed metadata chunk so tag labels become searchable via k-NN in the RAG pipeline
        UpsertResourceMetadataChunk::dispatch($resource->id);

        $resource->load('semanticTags');

        return response()->json([
            'success' => true,
            'data' => $resource->semanticTags,
        ]);
    }

    /**
     * Record who set the tag collection. Compares the new label set against the latest
     * AITY tag suggestion across the resource's files — if it's an exact match we credit
     * the suggestion (origin=aity_suggestion + source_file_id); otherwise origin=user.
     */
    private function recordTagsProvenance(Resource $resource, array $tagIds): void
    {
        $actor = Auth::id() ?? Resource::FIELD_AGENT_AITY;

        $newLabels = SemanticTag::whereIn('id', $tagIds)->pluck('label')->map('strtolower')->sort()->values()->all();

        $origin = Resource::FIELD_ORIGIN_USER;
        $sourceFileId = null;

        if (! empty($newLabels)) {
            $fileIds = $resource->files()->pluck('files.id');
            $rows = SystemFile::whereIn('source_file_id', $fileIds)
                ->where('purpose', SystemFilePurpose::AI_SUGGESTED_TAGS->value)
                ->where('is_active', true)
                ->get(['source_file_id', 'metadata']);

            foreach ($rows as $row) {
                $sugLabels = collect($row->metadata['value'] ?? [])
                    ->map(fn ($t) => is_array($t) ? strtolower((string) ($t['label'] ?? '')) : null)
                    ->filter()
                    ->sort()
                    ->values()
                    ->all();
                if ($sugLabels === $newLabels) {
                    $origin = Resource::FIELD_ORIGIN_AITY_SUGGESTION;
                    $sourceFileId = $row->source_file_id;
                    break;
                }
            }

            // Also match against the resource-level AITY-generated tag set (dedup output).
            if ($origin === Resource::FIELD_ORIGIN_USER) {
                $genRow = SystemFile::where('resource_id', $resource->id)
                    ->whereNull('source_file_id')
                    ->where('purpose', SystemFilePurpose::AI_GENERATED_TAGS->value)
                    ->where('is_active', true)
                    ->get(['metadata'])
                    ->first(function ($sf) use ($newLabels) {
                        $sugLabels = collect($sf->metadata['value'] ?? [])
                            ->map(fn ($t) => is_array($t) ? strtolower((string) ($t['label'] ?? '')) : null)
                            ->filter()
                            ->sort()
                            ->values()
                            ->all();

                        return $sugLabels === $newLabels;
                    });
                if ($genRow) {
                    $origin = Resource::FIELD_ORIGIN_AITY_GENERATED;
                    $sourceFileId = null;
                }
            }
        }

        $resource->updateQuietly([
            'tags_origin' => $origin,
            'tags_source_file_id' => $sourceFileId,
            'tags_set_by' => $actor,
            'tags_set_at' => now(),
        ]);

        // User committed a tag set — mark every pending AITY tag suggestion (per-file
        // suggestions AND resource-level generated) as applied so the panel hides them
        // and ticks land on the matched row. Per row, decide ACCEPTED vs DISMISSED by
        // comparing each suggestion's label list against the user's committed set.
        $fileIds = $resource->files()->pluck('files.id');
        $pending = SystemFile::where(function ($q) use ($fileIds, $resource) {
            $q->whereIn('source_file_id', $fileIds)
                ->orWhere(function ($q2) use ($resource) {
                    $q2->where('resource_id', $resource->id)->whereNull('source_file_id');
                });
        })
            ->whereIn('purpose', [
                SystemFilePurpose::AI_SUGGESTED_TAGS->value,
                SystemFilePurpose::AI_GENERATED_TAGS->value,
            ])
            ->where('is_active', true)
            ->whereNull('applied_at')
            ->get();

        if ($pending->isNotEmpty()) {
            SystemFile::whereIn('id', $pending->pluck('id'))->update(['applied_at' => now()]);

            $committedLabelSet = array_fill_keys($newLabels, true);
            foreach ($pending as $row) {
                $sugLabels = collect($row->metadata['value'] ?? [])
                    ->map(fn ($t) => is_array($t) ? strtolower((string) ($t['label'] ?? '')) : null)
                    ->filter()
                    ->values()
                    ->all();

                $matched = array_values(array_intersect($sugLabels, $newLabels));
                $dismissed = array_values(array_diff($sugLabels, $newLabels));
                // Tag suggestions count as ACCEPTED whenever at least one of their labels
                // ended up on the resource. DISMISSED is reserved for suggestion rows
                // where the user kept zero of the proposed labels.
                $accepted = ! empty($matched);

                if ($accepted) {
                    $this->eventLogger->suggestionApplied(
                        resourceId: $resource->id,
                        field: 'tags',
                        byAity: false,
                        systemFileId: $row->id,
                        payload: [
                            'matched_labels' => array_slice($matched, 0, 20),
                            'dismissed_labels' => array_slice($dismissed, 0, 20),
                            'source_file_id' => $row->source_file_id,
                            'purpose' => $row->purpose,
                        ],
                    );
                } else {
                    $this->eventLogger->suggestionDismissed(
                        resourceId: $resource->id,
                        field: 'tags',
                        systemFileId: $row->id,
                        payload: [
                            'via' => 'value_overridden',
                            'dismissed_labels' => array_slice($dismissed, 0, 20),
                            'source_file_id' => $row->source_file_id,
                            'purpose' => $row->purpose,
                        ],
                    );
                }
            }
        }

        $resource->recomputeAndSaveAityStatus();
    }
}
