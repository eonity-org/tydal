<?php

namespace App\Http\Controllers\API;

use App\Enums\FileRelation;
use App\Enums\FileRole;
use App\Enums\SystemFilePurpose;
use App\Exceptions\InvalidResourceComposition;
use App\Http\Controllers\API\Concerns\RespondsToBulkActions;
use App\Http\Controllers\Controller;
use App\Http\Requests\BulkResourceStateRequest;
use App\Http\Requests\BulkResourceTagsRequest;
use App\Http\Requests\StoreResourceRequest;
use App\Http\Requests\UpdateResourceRequest;
use App\Jobs\ExtractEmbeddedPreview;
use App\Jobs\IndexResourceToElasticsearch;
use App\Jobs\UpsertResourceMetadataChunk;
use App\Models\Collection;
use App\Models\File;
use App\Models\FileChunk;
use App\Models\Resource;
use App\Models\ResourceEvent;
use App\Models\SemanticTag;
use App\Models\SystemFile;
use App\Services\AityEnrichmentService;
use App\Services\AutoApprovalService;
use App\Services\CollectionSchemaService;
use App\Services\ElasticsearchService;
use App\Services\Interfaces\ResourceServiceInterface;
use App\Services\ResourceAgentProjectionService;
use App\Services\ResourceEventLogger;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class ResourceController extends Controller
{
    use RespondsToBulkActions;

    protected ResourceServiceInterface $resourceService;

    protected CollectionSchemaService $schemaService;

    protected AityEnrichmentService $aity;

    protected ResourceAgentProjectionService $agentProjection;

    public function __construct(
        ResourceServiceInterface $resourceService,
        CollectionSchemaService $schemaService,
        AityEnrichmentService $aity,
        ResourceAgentProjectionService $agentProjection,
    ) {
        $this->resourceService = $resourceService;
        $this->schemaService = $schemaService;
        $this->aity = $aity;
        $this->agentProjection = $agentProjection;
    }

    /**
     * Display a listing of the resource.
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

        $filters = $request->only(['type', 'collection_id', 'search']);
        $perPage = $request->get('per_page', 20);
        $page = $request->get('page', 1);

        $resources = $this->resourceService->getResources(
            currentOrganizationId(),
            $filters,
            $perPage,
            $page
        );

        return response()->json([
            'success' => true,
            'data' => [
                'resources' => $resources->items(),
            ],
            'meta' => [
                'pagination' => [
                    'current_page' => $resources->currentPage(),
                    'per_page' => $resources->perPage(),
                    'total' => $resources->total(),
                    'has_more' => $resources->hasMorePages(),
                ],
            ],
            'message' => 'Resources retrieved successfully',
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreResourceRequest $request): JsonResponse
    {
        // Authorized in StoreResourceRequest::authorize(), before validation.
        $data = $request->validated();
        $data['organization_id'] = currentOrganizationId();
        $data['user_owner_id'] = Auth::id();

        // Validate metadata against the collection's schema (if the collection has one)
        $collection = Collection::find($data['collection_id']);
        if ($collection && $collection->getEffectiveSchema()) {
            try {
                $validatedMetadata = $this->schemaService->validateResourceData(
                    $collection,
                    $data['metadata'] ?? []
                );
                $data['metadata'] = $validatedMetadata;
            } catch (ValidationException $e) {
                return response()->json([
                    'success' => false,
                    'message' => 'Metadata does not match the collection schema.',
                    'errors' => $this->schemaService->formatValidationErrors($e),
                ], 422);
            }
        }

        $resource = $this->resourceService->createResource($data);

        return response()->json([
            'success' => true,
            'data' => [
                'resource' => $resource,
            ],
            'message' => 'Resource created successfully',
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id): JsonResponse
    {
        $resource = $this->resourceService->getResourceById($id);

        if (! $resource) {
            return response()->json([
                'success' => false,
                'message' => 'Resource not found',
            ], 404);
        }

        $resource->load('collection');
        $this->authorize('view', $resource);

        $resource->load([
            'files.latestTikaSystemFile',
            'files.latestAiSuggestedTagsSystemFile',
            'files.latestAiSuggestedNameSystemFile',
            'files.latestAiSuggestedDescriptionSystemFile',
            'files.latestAiSuggestedTagsSystemFileForDisplay',
            'files.latestAiSuggestedNameSystemFileForDisplay',
            'files.latestAiSuggestedDescriptionSystemFileForDisplay',
            'files.latestAiSuggestedMetadataSystemFileForDisplay',
            'latestAiGeneratedTagsSystemFile',
            'latestAiGeneratedNameSystemFile',
            'latestAiGeneratedDescriptionSystemFile',
            'latestAiGeneratedTagsSystemFileForDisplay',
            'latestAiGeneratedNameSystemFileForDisplay',
            'latestAiGeneratedDescriptionSystemFileForDisplay',
            'categories',
            'semanticTags',
            'snapshotFile.media',
            'previewSnapshotSystemFile',
            'workspaces',
        ]);

        $suggestionPurposes = [
            SystemFilePurpose::AI_SUGGESTED_TAGS->value,
            SystemFilePurpose::AI_SUGGESTED_NAME->value,
            SystemFilePurpose::AI_SUGGESTED_DESCRIPTION->value,
            SystemFilePurpose::AI_SUGGESTED_METADATA->value,
            SystemFilePurpose::AI_GENERATED_TAGS->value,
            SystemFilePurpose::AI_GENERATED_NAME->value,
            SystemFilePurpose::AI_GENERATED_DESCRIPTION->value,
        ];
        $hasPendingSuggestions = SystemFile::where('resource_id', $resource->id)
            ->whereIn('purpose', $suggestionPurposes)
            ->where('is_active', true)
            ->whereNull('applied_at')
            ->exists();
        $hasProcessedSuggestions = ! $hasPendingSuggestions && SystemFile::where('resource_id', $resource->id)
            ->whereIn('purpose', $suggestionPurposes)
            ->where(function ($q) {
                $q->where('is_active', false)
                    ->orWhereNotNull('applied_at');
            })
            ->exists();

        $resource->setAttribute('ai_suggestions_status', $hasPendingSuggestions ? 'found' : ($hasProcessedSuggestions ? 'processed' : 'none'));

        // True while this resource belongs to a workspace whose auto-approve job
        // is queued or running. The frontend uses this to suppress the bronze
        // "to review" icon prematurely — the suggestions exist but a job will
        // consume them shortly, so the user shouldn't be prompted to review yet.
        $resource->setAttribute('under_auto_approve', $resource->workspaces()
            ->whereIn('auto_approve_status', ['pending', 'running'])
            ->exists());

        return response()->json([
            'success' => true,
            'data' => [
                'resource' => $resource,
            ],
            'message' => 'Resource retrieved successfully',
        ]);
    }

    /**
     * Lean, AI-agent-facing view of the resource — files and Vault links
     * inlined, chunk/embedding availability, no pipeline-audit internals.
     * See show() for the full admin-review shape this is a diet of.
     */
    public function agentView(string $id): JsonResponse
    {
        $resource = $this->resourceService->getResourceById($id);

        if (! $resource) {
            return response()->json([
                'success' => false,
                'message' => 'Resource not found',
            ], 404);
        }

        $this->authorize('view', $resource);

        return response()->json([
            'success' => true,
            'data' => [
                'resource' => $this->agentProjection->project($resource),
            ],
            'message' => 'Resource retrieved successfully',
        ]);
    }

    /**
     * List a resource's chunks in reading order — raw extracted text with
     * page numbers, not a RAG-synthesized answer. Empty when the resource's
     * collection has no search index configured or nothing has been chunked
     * yet, never an error.
     */
    public function chunks(string $id): JsonResponse
    {
        $resource = $this->resourceService->getResourceById($id);

        if (! $resource) {
            return response()->json([
                'success' => false,
                'message' => 'Resource not found',
            ], 404);
        }

        $this->authorize('view', $resource);

        $resource->load('collection.searchIndex');
        $indexName = $resource->collection?->searchIndex?->index_name;

        if (! $indexName) {
            return response()->json([
                'success' => true,
                'data' => ['chunks' => []],
                'message' => 'No search index configured for this resource\'s collection',
            ]);
        }

        $es = app(ElasticsearchService::class);
        $chunks = $es->listChunksForResource($es->buildChunksIndexName($indexName), $resource->id);

        return response()->json([
            'success' => true,
            'data' => ['chunks' => $chunks],
            'message' => 'Chunks retrieved successfully',
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateResourceRequest $request, string $id): JsonResponse
    {
        $resource = $this->resourceService->getResourceById($id);

        if (! $resource) {
            return response()->json([
                'success' => false,
                'message' => 'Resource not found',
            ], 404);
        }

        $this->authorize('update', $resource);

        $resource = $this->resourceService->updateResource($id, $request->validated());

        // Resolve the snapshot deterministically on update. This is the hook the wizard relies
        // on: it uploads components (which no longer auto-star) and then publishes via this
        // endpoint, at which point a starred file + preview are guaranteed. Idempotent and cheap
        // for resources that already have a valid snapshot (no re-render once one exists).
        if ($resource) {
            $this->resourceService->ensureSnapshot($resource);
            $resource->refresh();
        }

        return response()->json([
            'success' => true,
            'data' => [
                'resource' => $resource,
            ],
            'message' => 'Resource updated successfully',
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id): JsonResponse
    {
        $resource = $this->resourceService->getResourceById($id);

        if (! $resource) {
            return response()->json([
                'success' => false,
                'message' => 'Resource not found',
            ], 404);
        }

        $this->authorize('delete', $resource);

        $deleted = $this->resourceService->deleteResource($id);

        return response()->json([
            'success' => $deleted,
            'message' => $deleted ? 'Resource deleted successfully' : 'Failed to delete resource',
        ]);
    }

    /**
     * Add a file to a resource.
     */
    public function addFile(Request $request, string $id): JsonResponse
    {
        $resource = $this->resourceService->getResourceById($id);

        if (! $resource) {
            return response()->json([
                'success' => false,
                'message' => 'Resource not found',
            ], 404);
        }

        $this->authorize('update', $resource);

        $request->validate([
            'File' => 'required|file|max:512000', // 500MB max
            'role' => 'nullable|in:canonical,component,supporting',
            'relation' => 'nullable|in:derived,rendition,variant,translation,transcript,extracted',
            'usage' => 'nullable|array',
            'usage.*' => 'string',
            'defer_commit' => 'nullable|boolean',
        ]);

        $file = $request->file('File');
        $role = FileRole::from($request->input('role', FileRole::CANONICAL->value));
        $relation = $request->input('relation') ? FileRelation::from($request->input('relation')) : null;
        $usage = $request->input('usage');
        $deferCommit = $request->boolean('defer_commit');

        // MIME type validation against the collection scheme's accepted_mimetypes
        $resource->loadMissing('collection.scheme');
        $scheme = $resource->collection?->scheme;
        $acceptedMimetypes = $scheme?->accepted_mimetypes ?? [];
        if ($scheme && ! $scheme->acceptsMime((string) $file->getMimeType())) {
            return response()->json([
                'success' => false,
                'message' => 'File type not allowed for this collection.',
                'errors' => [
                    'File' => ['Accepted types: '.implode(', ', $acceptedMimetypes)],
                ],
            ], 422);
        }

        // Composition invariants (single canonical, no component beside a
        // canonical, snapshot exclusivity) — enforced in the service layer
        try {
            $relation = $this->resourceService->applyFileRoleInvariants($resource, $role, $relation, $usage);
        } catch (InvalidResourceComposition $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }

        // Add to Spatie MediaLibrary — stores the file and queues conversions
        $media = $resource
            ->addMediaFromRequest('File')
            ->usingFileName($file->getClientOriginalName())
            ->withCustomProperties(['role' => $role->value])
            ->toMediaCollection('files');

        $fileRecord = $resource->files()->create([
            'filename' => $media->file_name,
            'mime_type' => $media->mime_type,
            'size' => $media->size,
            'role' => $role,
            'relation' => $relation,
            'usage' => $usage,
            'disk' => $media->disk,
            'path' => $media->getPathRelativeToRoot(),
            'media_id' => $media->id,
            'uncommitted_at' => $deferCommit ? now() : null,
            'uncommitted_by' => $deferCommit ? auth()->id() : null,
        ]);

        // Auto-snapshot logic — the first previewable file to arrive claims the snapshot so the
        // star + rendered preview show up immediately (the single-file fast path, and the
        // sequential per-file uploads the wizard does).
        //
        // The existence check counts ANY active snapshot file, committed or not (unlike
        // Resource::snapshotFile(), which ignores uncommitted ones) — so sequential uploads
        // pick exactly one. Parallel deferred uploads (several components dropped at once in the
        // edit modal) can still race here and tag more than one; that transient ambiguity is
        // collapsed to a single deterministic snapshot at commit by ResourceService::ensureSnapshot().
        $anotherSnapshotExists = $resource->files()
            ->where('id', '!=', $fileRecord->id)
            ->where('is_active', true)
            ->whereJsonContains('usage', 'snapshot')
            ->exists();

        if (! $fileRecord->isSnapshot()
            && ! $anotherSnapshotExists
            && ($fileRecord->isImage() || $fileRecord->isAudio() || $fileRecord->mime_type === 'application/pdf')
        ) {
            // Images are their own snapshot. PDF/audio get a temporary snapshot marker so the
            // star appears right away; ExtractEmbeddedPreview below renders the JPEG preview.
            $fileRecord->update(['usage' => ['snapshot']]);
        }

        $resource->touch();

        // For a freshly-snapshotted PDF/audio, render the preview synchronously so the rendered
        // preview_snapshot_url is in this response. In the parallel-upload race the guard inside
        // ExtractEmbeddedPreview makes the losers skip — ensureSnapshot re-renders the winner at
        // commit. ExtractFileText (dispatched async below) skips re-extraction because the
        // SystemFile will already be active.
        if ($fileRecord->isSnapshot() && ($fileRecord->isAudio() || $fileRecord->mime_type === 'application/pdf')) {
            ExtractEmbeddedPreview::dispatchSync($fileRecord->id);
            $resource->load(['previewSnapshotSystemFile']);
        }

        // Kick off the full AITY enrichment pipeline (extraction → chunking → LLM/vision).
        $this->aity->enrich($fileRecord);

        return response()->json([
            'success' => true,
            'data' => [
                'file' => $fileRecord->load('media'),
                'preview_snapshot_url' => $resource->preview_snapshot_url,
            ],
            'message' => 'File uploaded successfully',
        ], 201);
    }

    /**
     * Commit all of the current user's uncommitted files on a resource: clears the
     * uncommitted_at/uncommitted_by markers so the files become permanent. Triggered
     * by the edit modal's Save handler. Files belonging to other users are untouched.
     */
    public function commitFiles(string $id): JsonResponse
    {
        $resource = $this->resourceService->getResourceById($id);

        if (! $resource) {
            return response()->json([
                'success' => false,
                'message' => 'Resource not found',
            ], 404);
        }

        $this->authorize('update', $resource);

        $userId = Auth::id();

        $committed = $resource->files()
            ->whereNotNull('uncommitted_at')
            ->where('uncommitted_by', $userId)
            ->update([
                'uncommitted_at' => null,
                'uncommitted_by' => null,
            ]);

        if ($committed > 0) {
            $resource->refresh();
            // Now that files are committed, deterministically resolve the snapshot (and render
            // its preview). This collapses the transient multi-snapshot state that parallel
            // component uploads can leave behind into a single starred file with a matching
            // preview — race-free, regardless of which uploads won the per-file auto-star.
            $this->resourceService->ensureSnapshot($resource);
            $resource->refresh();
            $resource->recomputeAndSaveAityStatus();
            IndexResourceToElasticsearch::dispatch($resource->id);
        }

        return response()->json([
            'success' => true,
            'data' => ['committed' => $committed],
            'message' => "Committed {$committed} file(s)",
        ]);
    }

    /**
     * Get download URL for a resource file.
     */
    public function downloadFile(Request $request, string $resourceId, string $fileId): JsonResponse
    {
        $resource = $this->resourceService->getResourceById($resourceId);

        if (! $resource) {
            return response()->json([
                'success' => false,
                'message' => 'Resource not found',
            ], 404);
        }

        $this->authorize('view', $resource);

        $file = $resource->files()->find($fileId);

        if (! $file) {
            return response()->json([
                'success' => false,
                'message' => 'File not found',
            ], 404);
        }

        // Generate temporary signed URL (valid for 5 minutes)
        $url = $file->getTemporaryUrl(5);

        return response()->json([
            'success' => true,
            'data' => [
                'url' => $url,
                'expires_in' => 300, // 5 minutes
            ],
            'message' => 'Download URL generated successfully',
        ]);
    }

    /**
     * Remove a file from a resource.
     * Deletes the Spatie Media record (and its stored file) then the File row.
     */
    public function removeFile(string $resourceId, string $fileId): JsonResponse
    {
        $resource = $this->resourceService->getResourceById($resourceId);

        if (! $resource) {
            return response()->json(['success' => false, 'message' => 'Resource not found'], 404);
        }

        $this->authorize('update', $resource);

        $file = $resource->files()->find($fileId);

        if (! $file) {
            return response()->json(['success' => false, 'message' => 'File not found'], 404);
        }

        // Delete the Spatie Media entry (removes the actual stored file)
        if ($file->media_id) {
            $file->media?->delete();
        }

        // Invalidate chunk vectors for this file in ES (best-effort)
        $this->invalidateFileChunks($file);

        // Remove derived artifacts (extracted text + AITY suggestion archives, FileChunk
        // rows, system_files rows). The source_file_id FK is nullOnDelete, so these would
        // otherwise be orphaned on disk and in the DB once $file->delete() runs.
        $this->resourceService->purgeFileArtifacts($file);

        // Reverse transition: if a canonical file is deleted, revert supporting → component
        if ($file->role === FileRole::CANONICAL) {
            $resource->files()
                ->where('role', FileRole::SUPPORTING->value)
                ->where('id', '!=', $file->id)
                ->update(['role' => FileRole::COMPONENT->value]);
            $this->resourceService->recalculatePromotedMetadata($resource);
        }

        $file->delete();
        $resource->touch();

        return response()->json(['success' => true, 'message' => 'File removed successfully']);
    }

    /**
     * Set a file as the canonical file for its resource.
     *
     * PATCH /api/v1/resources/{resourceId}/files/{fileId}/canonical
     */
    public function setFileCanonical(Request $request, string $resourceId, string $fileId): JsonResponse
    {
        $resource = $this->resourceService->getResourceById($resourceId);

        if (! $resource) {
            return response()->json(['success' => false, 'message' => 'Resource not found'], 404);
        }

        $this->authorize('update', $resource);

        $file = $resource->files()->find($fileId);

        if (! $file) {
            return response()->json(['success' => false, 'message' => 'File not found'], 404);
        }

        $this->resourceService->setCanonical($resource, $file);

        return response()->json([
            'success' => true,
            'file' => $file->fresh(),
        ]);
    }

    /**
     * Transfer the snapshot role to the given file.
     *
     * PATCH /api/v1/resources/{resourceId}/files/{fileId}/snapshot
     */
    public function updateFile(Request $request, string $resourceId, string $fileId): JsonResponse
    {
        $resource = $this->resourceService->getResourceById($resourceId);

        if (! $resource) {
            return response()->json(['success' => false, 'message' => 'Resource not found'], 404);
        }

        $this->authorize('update', $resource);

        $file = $resource->files()->find($fileId);

        if (! $file) {
            return response()->json(['success' => false, 'message' => 'File not found'], 404);
        }

        $validated = $request->validate([
            'role' => ['sometimes', 'string', 'in:'.implode(',', array_column(FileRole::cases(), 'value'))],
            'relation' => ['sometimes', 'nullable', 'string', 'in:'.implode(',', array_column(FileRelation::cases(), 'value'))],
        ]);

        // Block component role when a canonical already exists — no auto-conversion here
        // because the intent is ambiguous (the user would need to remove the canonical first).
        if (isset($validated['role']) && FileRole::from($validated['role']) === FileRole::COMPONENT) {
            $hasCanonical = $resource->files()
                ->where('role', FileRole::CANONICAL->value)
                ->where('is_active', true)
                ->where('id', '!=', $file->id)
                ->exists();
            if ($hasCanonical) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot set role to component: resource already has a canonical file. Use supporting for auxiliary files.',
                ], 422);
            }
        }
        // Promoting to canonical is handled by setCanonical(), which converts any remaining
        // component files to supporting automatically as part of the mode transition.

        // Promote to canonical via the service to enforce the single-canonical constraint;
        // every other role/relation change goes through its demotion twin, which refreshes
        // the same derived artifacts (metadata, embedding, ES document).
        if (isset($validated['role']) && $validated['role'] === FileRole::CANONICAL->value) {
            $this->resourceService->setCanonical($resource, $file);
        } else {
            $this->resourceService->updateFileRole($resource, $file, $validated);
        }

        return response()->json([
            'success' => true,
            'file' => $file->fresh(),
        ]);
    }

    public function setFileSnapshot(Request $request, string $resourceId, string $fileId): JsonResponse
    {
        $resource = $this->resourceService->getResourceById($resourceId);

        if (! $resource) {
            return response()->json(['success' => false, 'message' => 'Resource not found'], 404);
        }

        $this->authorize('update', $resource);

        $file = $resource->files()->find($fileId);

        if (! $file) {
            return response()->json(['success' => false, 'message' => 'File not found'], 404);
        }

        // Always mark the file as the snapshot immediately so the star appears right away
        // in the UI regardless of file type.
        $this->resourceService->setSnapshot($resource, $file);

        // For non-image files (PDF, audio) run the preview extraction synchronously so that
        // the rendered preview_snapshot_url is available in the response that triggers the
        // frontend re-fetch. The job handles graceful failure if no rendering tool is installed.
        if (! $file->isImage() && ($file->isAudio() || $file->mime_type === 'application/pdf')) {
            ExtractEmbeddedPreview::dispatchSync($file->id);
        }

        $resource->load(['previewSnapshotSystemFile']);

        return response()->json([
            'success' => true,
            'file' => $file->fresh(),
            'preview_snapshot_url' => $resource->preview_snapshot_url,
        ]);
    }

    /**
     * Full AITY enrichment re-trigger for a specific file (Tika + LLM/vision).
     *
     * POST /api/v1/resources/{id}/files/{fid}/aity-enrich
     */
    public function aityEnrichFile(string $resourceId, string $fileId): JsonResponse
    {
        $resource = $this->resourceService->getResourceById($resourceId);

        if (! $resource) {
            return response()->json(['success' => false, 'message' => 'Resource not found'], 404);
        }

        $this->authorize('update', $resource);

        $file = $resource->files()->find($fileId);

        if (! $file) {
            return response()->json(['success' => false, 'message' => 'File not found'], 404);
        }

        $this->aity->enrich($file);

        return response()->json([
            'success' => true,
            'message' => 'AITY enrichment queued for file '.$file->id,
        ], 202);
    }

    /**
     * Full AITY enrichment re-trigger for all files of a resource.
     *
     * POST /api/v1/resources/{id}/aity-enrich
     */
    public function aityEnrichResource(string $resourceId): JsonResponse
    {
        $resource = $this->resourceService->getResourceById($resourceId);

        if (! $resource) {
            return response()->json(['success' => false, 'message' => 'Resource not found'], 404);
        }

        $this->authorize('update', $resource);

        $this->aity->enrichAll($resource);

        return response()->json([
            'success' => true,
            'message' => 'AITY enrichment queued for all files',
        ], 202);
    }

    /**
     * LLM-only retag for all files of a resource (skips Tika, reads existing extraction).
     *
     * POST /api/v1/resources/{id}/aity-retag
     */
    public function aityRetag(string $resourceId): JsonResponse
    {
        $resource = $this->resourceService->getResourceById($resourceId);

        if (! $resource) {
            return response()->json(['success' => false, 'message' => 'Resource not found'], 404);
        }

        $this->authorize('update', $resource);

        $this->aity->retagAll($resource);

        return response()->json([
            'success' => true,
            'message' => 'AITY retag queued for all files',
        ], 202);
    }

    /**
     * Run the auto-approval synthesis for a single resource. This is the same
     * pipeline the workspace auto-approve uses, scoped to one resource: it unifies
     * the per-file AI suggestions into resource-level metadata — for a multi-component
     * resource that means one LLM call producing a synthetic name/description and a
     * deduped tag set spanning all files (AutoApprovalService::synthesizeMultiComponent).
     *
     * apply=false (default) → persist the unified result as AI_GENERATED_* suggestions
     *   for the user to review/accept; the resource is not modified.
     * apply=true            → also write the unified name/description/tags onto the
     *   resource (matches workspace auto-approve behaviour).
     *
     * Runs synchronously: a single resource is at most one synthesis (+ optional
     * apply) LLM call, fast enough to return inline. min_frequency is forced to 1
     * because cross-resource frequency clustering is meaningless for one resource.
     */
    public function aityApproveResource(Request $request, string $id): JsonResponse
    {
        $resource = $this->resourceService->getResourceById($id);

        if (! $resource) {
            return response()->json(['success' => false, 'message' => 'Resource not found'], 404);
        }

        $this->authorize('update', $resource);

        $apply = $request->boolean('apply');

        $options = $apply
            ? ['min_frequency' => 1, 'dedup' => false]
            : ['apply_name' => false, 'apply_description' => false, 'apply_tags' => false, 'dedup' => false];

        // approve() type-hints an Eloquent collection — fetch as one rather than
        // wrapping in a Support collection.
        $result = app(AutoApprovalService::class)
            ->approve(Resource::whereKey($resource->id)->get(), $resource->organization_id, $options);

        return response()->json([
            'success' => true,
            'message' => $apply ? 'AITY metadata generated and applied' : 'AITY metadata generated',
            'applied' => $apply,
            'result' => $result,
        ]);
    }

    /**
     * Returns the AITY enrichment status for a specific file.
     *
     * Stages: null | queued | extracting | ai_analyzing | done | not_applicable | failed
     * - done:           AI ran and completed; suggestions may or may not be present.
     * - not_applicable: MIME type has no AI path (video, unsupported types, images with
     *                   vision disabled, text files with autotagging disabled).
     * - failed:         all retries exhausted.
     * Suggestion payload is only included when stage is 'done' and suggestions exist.
     *
     * GET /api/v1/resources/{id}/files/{fid}/aity-status
     */
    public function aityStatus(string $resourceId, string $fileId): JsonResponse
    {
        $resource = $this->resourceService->getResourceById($resourceId);

        if (! $resource) {
            return response()->json(['success' => false, 'message' => 'Resource not found'], 404);
        }

        $this->authorize('view', $resource);

        $file = $resource->files()->find($fileId);

        if (! $file) {
            return response()->json(['success' => false, 'message' => 'File not found'], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $this->aity->getStatus($file),
        ]);
    }

    /**
     * GET /api/v1/resources/aity-status?ids=uuid1,uuid2,…
     *
     * Bulk lightweight status endpoint for dashboard polling. Returns
     *   [{ id, aity_status, updated_at }, … ]
     * for the requested resource ids that belong to the current organisation.
     * Hard-capped at 200 ids per request to keep the URL and response small.
     */
    public function aityStatusBulk(Request $request): JsonResponse
    {
        $raw = (string) $request->query('ids', '');
        $ids = collect(explode(',', $raw))
            ->map(fn ($s) => trim($s))
            ->filter()
            ->unique()
            ->take(200)
            ->values()
            ->all();

        if (empty($ids)) {
            return response()->json(['success' => true, 'data' => []]);
        }

        $orgId = currentOrganizationId();

        $rows = \App\Models\Resource::query()
            ->where('organization_id', $orgId)
            ->whereIn('id', $ids)
            ->get(['id', 'aity_status', 'updated_at']);

        // One join-free query for the "any workspace this resource sits in is in
        // pending/running auto-approve" check, so we don't issue N queries.
        $underAutoApprove = DB::table('dam_resource_workspace')
            ->join('workspaces', 'workspaces.id', '=', 'dam_resource_workspace.workspace_id')
            ->whereIn('dam_resource_workspace.resource_id', $rows->pluck('id'))
            ->whereIn('workspaces.auto_approve_status', ['pending', 'running'])
            ->distinct()
            ->pluck('dam_resource_workspace.resource_id')
            ->all();
        $underAutoApproveSet = array_flip($underAutoApprove);

        return response()->json([
            'success' => true,
            'data' => $rows->map(fn ($r) => [
                'id' => $r->id,
                'aity_status' => $r->aity_status,
                'updated_at' => optional($r->updated_at)->toIso8601String(),
                'under_auto_approve' => isset($underAutoApproveSet[$r->id]),
            ]),
        ]);
    }

    /**
     * POST /api/v1/resources/bulk/state
     *
     * Move many resources along the lifecycle at once — the dashboard basket's
     * bulk publish/withdraw. Body: { resource_ids: [...], state: 'live' }.
     *
     * Writes quietly and queues one Elasticsearch sync for the batch: the
     * model's boot hooks index synchronously on save, which is right for a
     * single interactive edit and ruinous for two hundred. The queued job
     * decides index-or-delete per resource from its new state, so moving to
     * `archived` correctly removes the documents.
     */
    public function bulkState(BulkResourceStateRequest $request, ResourceEventLogger $eventLogger): JsonResponse
    {
        $requested = $request->resourceIds();
        $state = $request->state();

        $resources = Resource::where('organization_id', currentOrganizationId())
            ->whereIn('id', $requested)
            ->get();

        $applied = [];
        foreach ($resources as $resource) {
            if (! Auth::user()?->can('update', $resource)) {
                continue;
            }

            $previous = $resource->state;
            if ($previous !== $state) {
                $resource->updateQuietly(['state' => $state->value]);

                $eventLogger->log(
                    resourceId: $resource->id,
                    eventType: 'state_changed',
                    payload: ['old_value' => $previous->value, 'new_value' => $state->value],
                );
            }

            // Already in the target state still counts as applied — the caller
            // asked for an outcome, not for a transition.
            $applied[] = $resource->id;
        }

        return $this->bulkResponse($requested, $applied, 'forbidden', $this->syncResourceIndexes($applied));
    }

    /**
     * POST /api/v1/resources/bulk/semantic-tags
     *
     * Add or remove a set of tags across many resources. Body:
     * { resource_ids: [...], tag_ids: [...], mode: 'add'|'remove' }.
     *
     * Additive/subtractive on purpose — see BulkResourceTagsRequest. The
     * per-resource endpoint's suggestion-provenance matching is deliberately
     * not run here: it exists to decide whether a human's committed tag SET
     * matches an AITY proposal, and "add one tag to forty resources" is not a
     * commit of anyone's set. Authorship is still recorded.
     */
    public function bulkSemanticTags(BulkResourceTagsRequest $request, ResourceEventLogger $eventLogger): JsonResponse
    {
        $orgId = currentOrganizationId();
        $requested = $request->resourceIds();
        $adding = $request->isAdding();

        // Only tags belonging to this org, same rule as syncResource().
        $tagIds = SemanticTag::where('organization_id', $orgId)
            ->whereIn('id', $request->tagIds())
            ->pluck('id')
            ->all();

        if (empty($tagIds)) {
            return response()->json([
                'success' => false,
                'message' => 'None of the given tags belong to this organization.',
            ], 422);
        }

        $labels = SemanticTag::whereIn('id', $tagIds)->pluck('label')->all();
        $actor = Auth::id() ?? Resource::FIELD_AGENT_AITY;

        $resources = Resource::where('organization_id', $orgId)
            ->whereIn('id', $requested)
            ->get();

        $applied = [];
        foreach ($resources as $resource) {
            if (! Auth::user()?->can('update', $resource)) {
                continue;
            }

            if ($adding) {
                $resource->semanticTags()->syncWithoutDetaching($tagIds);
            } else {
                $resource->semanticTags()->detach($tagIds);
            }

            $resource->updateQuietly([
                'tags_origin' => Resource::FIELD_ORIGIN_USER,
                'tags_set_by' => $actor,
                'tags_set_at' => now(),
            ]);

            $eventLogger->log(
                resourceId: $resource->id,
                eventType: 'tags_updated',
                payload: [
                    'added' => $adding ? $labels : [],
                    'removed' => $adding ? [] : $labels,
                    'total' => $resource->semanticTags()->count(),
                    'bulk' => true,
                ],
            );

            // Tag labels feed the k-NN metadata chunk, same as syncResource().
            UpsertResourceMetadataChunk::dispatch($resource->id);

            $applied[] = $resource->id;
        }

        return $this->bulkResponse($requested, $applied, 'forbidden', $this->syncResourceIndexes($applied));
    }

    /**
     * GET /resources/{id}/activity
     *
     * Returns a chronological processing timeline for a resource, derived from
     * existing files, system_files, and file_chunks records — no dedicated log table needed.
     */
    public function activityLog(string $resourceId): JsonResponse
    {
        $resource = $this->resourceService->getResourceById($resourceId);

        if (! $resource) {
            return response()->json(['message' => 'Resource not found'], 404);
        }

        $this->authorize('view', $resource);

        $events = collect();
        $fileNames = $resource->files->pluck('filename', 'id')->all();

        // File upload events
        foreach ($resource->files as $file) {
            $events->push([
                'event' => 'file_uploaded',
                'file_id' => $file->id,
                'file_name' => $file->filename,
                'status' => 'completed',
                'created_at' => $file->created_at?->toIso8601String(),
                'details' => [
                    'mime_type' => $file->mime_type,
                    'role' => $file->role,
                    'size' => $file->size,
                ],
            ]);
        }

        // System file events — include inactive records so history is visible
        $systemFiles = SystemFile::where('resource_id', $resourceId)
            ->orderBy('created_at')
            ->get();

        foreach ($systemFiles as $sf) {
            $meta = $sf->metadata ?? [];
            $hasError = ! empty($meta['error_message']);

            $status = match (true) {
                ! $sf->is_active && $hasError => 'failed',
                ! $sf->is_active => 'superseded',
                default => 'completed',
            };

            $details = match ($sf->purpose) {
                SystemFilePurpose::EXTRACTED_TEXT->value => array_filter([
                    'chunk_count' => $meta['chunk_count'] ?? null,
                    'char_count' => $meta['char_count'] ?? null,
                    'extracted_at' => $meta['extracted_at'] ?? null,
                    'error' => $meta['error_message'] ?? null,
                    'embed_error' => $meta['embedding_error'] ?? null,
                ]),
                SystemFilePurpose::TIKA_METADATA->value => array_filter([
                    'key_count' => count($meta['tika_metadata'] ?? []),
                    'keys' => array_keys($meta['tika_metadata'] ?? []),
                ]),
                SystemFilePurpose::AI_SUGGESTED_TAGS->value => array_filter([
                    'count' => count($meta['value'] ?? []),
                    'labels' => array_column($meta['value'] ?? [], 'label'),
                ]),
                SystemFilePurpose::AI_SUGGESTED_NAME->value => array_filter([
                    'value' => $meta['value'] ?? null,
                ]),
                SystemFilePurpose::AI_SUGGESTED_DESCRIPTION->value => array_filter([
                    'value' => mb_substr($meta['value'] ?? '', 0, 200),
                ]),
                default => [],
            };

            $events->push([
                'event' => $sf->purpose,
                'file_id' => $sf->source_file_id,
                'file_name' => $sf->source_file_id ? ($fileNames[$sf->source_file_id] ?? null) : null,
                'status' => $status,
                'is_active' => $sf->is_active,
                'created_at' => $sf->created_at?->toIso8601String(),
                'details' => $details,
            ]);
        }

        // Chunk creation events — one entry per file (max created_at of its chunks)
        $chunkStats = FileChunk::where('resource_id', $resourceId)
            ->selectRaw('source_file_id, count(*) as chunk_count, max(created_at) as chunked_at')
            ->groupBy('source_file_id')
            ->get();

        foreach ($chunkStats as $stat) {
            $events->push([
                'event' => 'chunks_created',
                'file_id' => $stat->source_file_id,
                'file_name' => $stat->source_file_id ? ($fileNames[$stat->source_file_id] ?? null) : null,
                'status' => 'completed',
                'created_at' => $stat->chunked_at ? Carbon::parse($stat->chunked_at)->toIso8601String() : null,
                'details' => ['chunk_count' => (int) $stat->chunk_count],
            ]);
        }

        // Authoritative audit-log rows from resource_events. These capture intent
        // (accept / dismiss / auto-approve) that cannot be derived from system_files
        // alone. The synthesized rows above remain for stages that still don't have
        // dedicated event rows (file_uploaded, chunks_created, etc.).
        $auditEvents = ResourceEvent::where('resource_id', $resourceId)
            ->orderBy('created_at')
            ->get();

        foreach ($auditEvents as $ev) {
            $payload = $ev->payload ?? [];
            // file_id may come from the payload or, for file-targeted events, target_id
            $fileId = $payload['source_file_id']
                ?? ($ev->target_type === ResourceEventLogger::TARGET_FILE ? $ev->target_id : null);
            // Derive a failed status from the event-type suffix so failures render red.
            $status = str_ends_with($ev->event_type, '_failed') ? 'failed' : 'completed';
            $events->push([
                'event' => $ev->event_type,
                'file_id' => $fileId,
                'file_name' => $fileId ? ($fileNames[$fileId] ?? null) : null,
                'status' => $status,
                'actor_type' => $ev->actor_type,
                'actor_id' => $ev->actor_id,
                'target_type' => $ev->target_type,
                'target_id' => $ev->target_id,
                'created_at' => $ev->created_at?->toIso8601String(),
                'details' => $payload,
            ]);
        }

        $sorted = $events->sortBy(fn ($e) => $e['created_at'] ?? '9999-12-31')->values();

        return response()->json(['data' => $sorted]);
    }

    /**
     * Deactivate all AI suggestion SystemFiles (tags, name, description) for a specific file.
     *
     * DELETE /api/v1/resources/{resourceId}/files/{fileId}/ai-suggestions
     *
     * Called after the user reviews and saves the resource in edit mode, signalling that
     * all pending suggestions for this file have been either accepted or dismissed.
     */
    public function clearFileAiSuggestions(string $resourceId, string $fileId): JsonResponse
    {
        $resource = $this->resourceService->getResourceById($resourceId);

        if (! $resource) {
            return response()->json(['success' => false, 'message' => 'Resource not found'], 404);
        }

        $this->authorize('update', $resource);

        $file = $resource->files()->find($fileId);

        if (! $file) {
            return response()->json(['success' => false, 'message' => 'File not found'], 404);
        }

        SystemFile::where('source_file_id', $fileId)
            ->whereIn('purpose', [
                SystemFilePurpose::AI_SUGGESTED_TAGS->value,
                SystemFilePurpose::AI_SUGGESTED_NAME->value,
                SystemFilePurpose::AI_SUGGESTED_DESCRIPTION->value,
                SystemFilePurpose::AI_SUGGESTED_METADATA->value,
            ])
            ->where('is_active', true)
            ->whereNull('applied_at')
            ->update(['applied_at' => now()]);

        $resource->recomputeAndSaveAityStatus();

        return response()->json(['success' => true]);
    }

    /**
     * POST /api/v1/resources/{resourceId}/ai-suggestions/restore
     *
     * Re-activates all processed AI suggestion files for a resource so the
     * user can review them again. Also re-adds the resource to the review queue.
     */
    public function restoreAiSuggestions(string $resourceId): JsonResponse
    {
        $resource = $this->resourceService->getResourceById($resourceId);

        if (! $resource) {
            return response()->json(['success' => false, 'message' => 'Resource not found'], 404);
        }

        $this->authorize('update', $resource);

        $fileIds = $resource->files()->pluck('files.id');

        // Re-activate any legacy soft-cleared records (is_active=false) and
        // clear the applied flag so they show up as pending again.
        SystemFile::whereIn('source_file_id', $fileIds)
            ->whereIn('purpose', [
                SystemFilePurpose::AI_SUGGESTED_TAGS->value,
                SystemFilePurpose::AI_SUGGESTED_NAME->value,
                SystemFilePurpose::AI_SUGGESTED_DESCRIPTION->value,
            ])
            ->where(function ($q) {
                $q->where('is_active', false)
                    ->orWhereNotNull('applied_at');
            })
            ->update(['is_active' => true, 'applied_at' => null, 'applied_by_aity' => null]);

        $resource->recomputeAndSaveAityStatus();

        return response()->json(['success' => true]);
    }

    /**
     * List soft-deleted resources for the current organisation.
     */
    public function trashed(Request $request): JsonResponse
    {
        $user = $request->user();
        $orgId = currentOrganizationId();

        $sortBy = in_array($request->input('sort_by'), ['name', 'deleted_at', 'id']) ? $request->input('sort_by') : 'deleted_at';
        $sortDir = $request->input('sort_dir', 'desc') === 'asc' ? 'asc' : 'desc';

        $query = Resource::onlyTrashed()
            ->where('organization_id', $orgId)
            ->with(['snapshotFile.media', 'previewSnapshotSystemFile', 'collection'])
            ->orderBy($sortBy, $sortDir);

        // Non-admins only see their own deleted resources
        if (! in_array(currentOrganizationRole(), ['admin', 'owner'])) {
            $query->where('user_owner_id', $user->id);
        }

        $resources = $query->paginate($request->input('limit', 48));

        return response()->json([
            'success' => true,
            'data' => $resources->items(),
            'total' => $resources->total(),
            'per_page' => $resources->perPage(),
            'current_page' => $resources->currentPage(),
            'last_page' => $resources->lastPage(),
        ]);
    }

    /**
     * Restore all trashed resources for the current organisation.
     */
    public function restoreAll(Request $request): JsonResponse
    {
        $user = $request->user();
        $orgId = currentOrganizationId();

        $query = Resource::onlyTrashed()->where('organization_id', $orgId);

        if (! in_array(currentOrganizationRole(), ['admin', 'owner'])) {
            $query->where('user_owner_id', $user->id);
        }

        $resources = $query->get();

        foreach ($resources as $resource) {
            $resource->restore(); // model's `restored` hook re-queues ES indexing
        }

        return response()->json([
            'success' => true,
            'message' => "{$resources->count()} resource(s) restored",
            'count' => $resources->count(),
        ]);
    }

    /**
     * Permanently delete all trashed resources for the current organisation.
     * Only admins/owners can purge all; editors/viewers only purge their own.
     */
    public function purgeTrash(Request $request): JsonResponse
    {
        $user = $request->user();
        $orgId = currentOrganizationId();

        $query = Resource::onlyTrashed()->where('organization_id', $orgId);

        if (! in_array(currentOrganizationRole(), ['admin', 'owner'])) {
            $query->where('user_owner_id', $user->id);
        }

        $resources = $query->get();

        foreach ($resources as $resource) {
            $this->resourceService->permanentlyDelete($resource);
        }

        return response()->json([
            'success' => true,
            'message' => "{$resources->count()} resource(s) permanently deleted",
            'count' => $resources->count(),
        ]);
    }

    /**
     * Restore a soft-deleted resource.
     */
    public function restore(string $id): JsonResponse
    {
        $resource = Resource::onlyTrashed()->findOrFail($id);
        $this->authorize('restore', $resource);
        $resource->restore(); // model's `restored` boot hook re-queues ES indexing

        return response()->json(['success' => true, 'message' => 'Resource restored']);
    }

    /**
     * Permanently delete a soft-deleted resource.
     */
    public function forceDestroy(string $id): JsonResponse
    {
        $resource = Resource::onlyTrashed()->findOrFail($id);
        $this->authorize('forceDelete', $resource);

        $this->resourceService->permanentlyDelete($resource);

        return response()->json(['success' => true, 'message' => 'Resource permanently deleted']);
    }

    /**
     * Invalidate ES chunk vectors for a file being deleted.
     * Best-effort — failures are logged but do not block file deletion.
     */
    private function invalidateFileChunks(File $file): void
    {
        try {
            $indexName = $file->resource?->collection?->searchIndex?->index_name;
            if (! $indexName) {
                return;
            }
            $es = app(ElasticsearchService::class);
            $chunksIndex = $es->buildChunksIndexName($indexName);
            $es->invalidateChunksByFileId($chunksIndex, $file->id);
        } catch (\Throwable $e) {
            Log::warning(
                "Failed to invalidate ES chunks for file {$file->id}: ".$e->getMessage()
            );
        }
    }
}
