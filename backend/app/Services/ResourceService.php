<?php

namespace App\Services;

use App\Enums\FileRelation;
use App\Enums\FileRole;
use App\Enums\ResourceState;
use App\Enums\SystemFilePurpose;
use App\Exceptions\InvalidResourceComposition;
use App\Jobs\ExtractEmbeddedPreview;
use App\Jobs\IndexResourceToElasticsearch;
use App\Models\File;
use App\Models\FileChunk;
use App\Models\Resource;
use App\Models\SystemFile;
use App\Repositories\Interfaces\ResourceRepositoryInterface;
use App\Services\Interfaces\ResourceServiceInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ResourceService implements ResourceServiceInterface
{
    protected ResourceRepositoryInterface $resourceRepository;

    protected ResourceEventLogger $eventLogger;

    public function __construct(
        ResourceRepositoryInterface $resourceRepository,
        ResourceEventLogger $eventLogger,
    ) {
        $this->resourceRepository = $resourceRepository;
        $this->eventLogger = $eventLogger;
    }

    /**
     * Get resources for an organization.
     */
    public function getResources(string $organizationId, array $filters, int $perPage, int $page): LengthAwarePaginator
    {
        $query = Resource::where('organization_id', $organizationId)
            ->where('state', ResourceState::LIVE->value)
            ->whereHas('collection', function ($q) {
                $q->where('is_active', true);
            });

        // Apply filters
        if (isset($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        if (isset($filters['collection_id'])) {
            $query->where('collection_id', $filters['collection_id']);
        }

        if (isset($filters['search'])) {
            $query->where(function ($q) use ($filters) {
                $q->where('name', 'like', '%'.$filters['search'].'%')
                    ->orWhere('description', 'like', '%'.$filters['search'].'%');
            });
        }

        // Include files_count for each resource — committed only, since catalogue
        // listings must not surface session-uncommitted uploads.
        $query->withCount(['files as files_count' => function ($q) {
            $q->whereNull('uncommitted_at');
        }]);

        $query->with(['snapshotFile.media', 'previewSnapshotSystemFile']);

        // `id` tiebreaker: created_at alone is not unique (bulk imports share
        // a second), and without a total order MySQL may duplicate/skip rows
        // across pages.
        return $query->orderBy('created_at', 'desc')->orderBy('id')->paginate($perPage, ['*'], 'page');
    }

    /**
     * Get resource by ID.
     */
    public function getResourceById(string $id): ?Resource
    {
        return Resource::find($id);
    }

    /**
     * Create a new resource.
     */
    public function createResource(array $data): Resource
    {
        // A resource is live unless the caller says otherwise (the wizard
        // creates drafts).
        if (! isset($data['state'])) {
            $data['state'] = ResourceState::LIVE->value;
        }

        // Set default payload if not provided
        if (! isset($data['payload'])) {
            $data['payload'] = [
                'downloadable' => true,
                'public' => false,
                'featured' => false,
            ];
        }

        return Resource::create($data);
    }

    /**
     * Update a resource.
     */
    public function updateResource(string $id, array $data): ?Resource
    {
        $resource = $this->getResourceById($id);

        if (! $resource) {
            return null;
        }

        // Snapshot pre-update values so we can log field-level change events
        // independently of the AITY-suggestion accept/dismiss flow handled below.
        $before = [
            'name' => $resource->name,
            'description' => $resource->description,
            'state' => $resource->state,
        ];

        $data = $this->applyFieldProvenance($resource, $data);

        $resource->update($data);

        // Save = reviewed: a user-initiated resource update is treated as evidence
        // that the user has had the opportunity to review every AITY suggestion on
        // this resource. Any pending suggestion the provenance pass didn't already
        // clear (because the user did not touch that field) is marked applied here,
        // so the resource is promoted to user_review_done instead of staying stuck
        // in suggestions_made when only some fields were touched.
        $this->markRemainingSuggestionsApplied($resource);

        // Emit field-change events for plain editor edits (name / description /
        // state). Suggestion accept/dismiss is logged elsewhere
        // and tells a different story — this one is "the user changed a field".
        $this->logFieldChanges($resource, $before, $data);

        // recomputeAndSaveAityStatus reads the new applied_at flags written by
        // applyFieldProvenance and markRemainingSuggestionsApplied, so it must
        // run after both passes.
        $resource->recomputeAndSaveAityStatus();

        return $resource->fresh(['files', 'categories', 'semanticTags', 'snapshotFile.media', 'previewSnapshotSystemFile']);
    }

    /**
     * Emit one *_updated event per field that actually changed in the update.
     * Reads from $before (pre-update snapshot) and the freshly-saved $resource.
     * $data carries the origin flags set by applyFieldProvenance — useful so the
     * timeline can show whether the new value came from a suggestion or was typed.
     */
    private function logFieldChanges(Resource $resource, array $before, array $data): void
    {
        if (array_key_exists('name', $data) && $before['name'] !== $resource->name) {
            $this->eventLogger->log(
                resourceId: $resource->id,
                eventType: 'name_updated',
                payload: [
                    'old_value' => $this->payloadValueExcerpt($before['name']),
                    'new_value' => $this->payloadValueExcerpt($resource->name),
                    'origin' => $data['name_origin'] ?? null,
                ],
            );
        }

        if (array_key_exists('description', $data) && $before['description'] !== $resource->description) {
            $this->eventLogger->log(
                resourceId: $resource->id,
                eventType: 'description_updated',
                payload: [
                    'old_value' => $this->payloadValueExcerpt($before['description']),
                    'new_value' => $this->payloadValueExcerpt($resource->description),
                    'origin' => $data['description_origin'] ?? null,
                ],
            );
        }

        if (array_key_exists('state', $data) && $before['state'] !== $resource->state) {
            $this->eventLogger->log(
                resourceId: $resource->id,
                eventType: 'state_changed',
                payload: [
                    'old_value' => $before['state']->value,
                    'new_value' => $resource->state->value,
                ],
            );
        }
    }

    /**
     * Mark every active, still-pending AITY suggestion on the resource as applied.
     * Idempotent — rows that already carry applied_at are left untouched. Covers
     * both per-file suggestions (source_file_id set) and resource-level generated
     * suggestions (source_file_id NULL), across name / description / tag purposes.
     *
     * Emits a *_dismissed event for each row touched, tagged with via=save_flush so
     * the timeline distinguishes these from explicit user dismissals.
     */
    private function markRemainingSuggestionsApplied(Resource $resource): void
    {
        $purposes = [
            SystemFilePurpose::AI_SUGGESTED_NAME->value,
            SystemFilePurpose::AI_SUGGESTED_DESCRIPTION->value,
            SystemFilePurpose::AI_SUGGESTED_TAGS->value,
            SystemFilePurpose::AI_SUGGESTED_METADATA->value,
            SystemFilePurpose::AI_GENERATED_NAME->value,
            SystemFilePurpose::AI_GENERATED_DESCRIPTION->value,
            SystemFilePurpose::AI_GENERATED_TAGS->value,
        ];

        $fileIds = $resource->files()->pluck('files.id');
        $pending = SystemFile::where(function ($q) use ($fileIds, $resource) {
            $q->whereIn('source_file_id', $fileIds)
                ->orWhere(function ($q2) use ($resource) {
                    $q2->where('resource_id', $resource->id)->whereNull('source_file_id');
                });
        })
            ->whereIn('purpose', $purposes)
            ->where('is_active', true)
            ->whereNull('applied_at')
            ->get();

        if ($pending->isEmpty()) {
            return;
        }

        SystemFile::whereIn('id', $pending->pluck('id'))->update(['applied_at' => now()]);

        foreach ($pending as $row) {
            $field = $this->purposeToField($row->purpose);
            if ($field === null) {
                continue;
            }
            $this->eventLogger->suggestionDismissed(
                resourceId: $resource->id,
                field: $field,
                systemFileId: $row->id,
                payload: [
                    'via' => 'save_flush',
                    'suggested_value' => $this->payloadValueExcerpt($row->metadata['value'] ?? null),
                    'source_file_id' => $row->source_file_id,
                    'purpose' => $row->purpose,
                ],
            );
        }
    }

    /**
     * Map a SystemFilePurpose value to the 'name' | 'description' | 'tags' field key
     * used by the event-type vocabulary. Returns null for non-suggestion purposes.
     * Accepts either an enum instance (the model's cast value) or the raw string.
     */
    private function purposeToField(SystemFilePurpose|string $purpose): ?string
    {
        $value = $purpose instanceof SystemFilePurpose ? $purpose->value : $purpose;

        return match ($value) {
            SystemFilePurpose::AI_SUGGESTED_NAME->value,
            SystemFilePurpose::AI_GENERATED_NAME->value => 'name',
            SystemFilePurpose::AI_SUGGESTED_DESCRIPTION->value,
            SystemFilePurpose::AI_GENERATED_DESCRIPTION->value => 'description',
            SystemFilePurpose::AI_SUGGESTED_TAGS->value,
            SystemFilePurpose::AI_GENERATED_TAGS->value => 'tags',
            default => null,
        };
    }

    /**
     * Truncate a suggestion value for safe inclusion in the event payload.
     * Strings cap at 200 chars; arrays (tag lists) get a per-entry trim and a length cap.
     */
    private function payloadValueExcerpt(mixed $value): mixed
    {
        if (is_string($value)) {
            return mb_substr($value, 0, 200);
        }
        if (is_array($value)) {
            return array_slice($value, 0, 20);
        }

        return $value;
    }

    /**
     * Record who/where each main field came from when it's actually changing.
     * Compares the incoming value against the latest AITY suggestion for the resource:
     *   - match → origin=aity_suggestion, source_file_id=the file that produced it
     *   - otherwise → origin=user
     * Only writes provenance keys for fields the caller is touching, and only if the
     * value actually differs from what's stored. Callers can override by passing the
     * provenance keys explicitly (e.g., AutoApprovalService).
     */
    private function applyFieldProvenance(Resource $resource, array $data): array
    {
        $actor = Auth::id() ?? Resource::FIELD_AGENT_AITY;

        foreach (['name', 'description'] as $field) {
            if (! array_key_exists($field, $data)) {
                continue;
            }
            if (array_key_exists("{$field}_origin", $data)) {
                continue;
            } // caller asserted provenance
            $newValue = $data[$field];
            if ($newValue === $resource->{$field}) {
                continue;
            }          // no real change

            $perFilePurpose = $field === 'name'
                ? SystemFilePurpose::AI_SUGGESTED_NAME->value
                : SystemFilePurpose::AI_SUGGESTED_DESCRIPTION->value;
            $generatedPurpose = $field === 'name'
                ? SystemFilePurpose::AI_GENERATED_NAME->value
                : SystemFilePurpose::AI_GENERATED_DESCRIPTION->value;

            // Match the new value against any active AITY-produced value for this field —
            // per-file suggestion (carries source_file_id) or resource-level generated.
            $match = $this->findMatchingAiSuggestion($resource, $perFilePurpose, $newValue);
            if (! $match) {
                $generated = SystemFile::where('resource_id', $resource->id)
                    ->whereNull('source_file_id')
                    ->where('purpose', $generatedPurpose)
                    ->where('is_active', true)
                    ->get(['metadata'])
                    ->first(fn ($sf) => ($sf->metadata['value'] ?? null) === $newValue);
                if ($generated) {
                    $data["{$field}_origin"] = Resource::FIELD_ORIGIN_AITY_GENERATED;
                    $data["{$field}_source_file_id"] = null;
                }
            }
            if (! array_key_exists("{$field}_origin", $data)) {
                $data["{$field}_origin"] = $match ? Resource::FIELD_ORIGIN_AITY_SUGGESTION : Resource::FIELD_ORIGIN_USER;
                $data["{$field}_source_file_id"] = $match['source_file_id'] ?? null;
            }
            $data["{$field}_set_by"] = $actor;
            $data["{$field}_set_at"] = now();

            // The user committed a value for this field — every pending AITY suggestion
            // of this purpose (per-file OR resource-level generated) has now been decided.
            // Mark them applied so the basic-info panel hides them and the per-file AITY
            // card draws a tick on the matched row.
            $fileIds = $resource->files()->pluck('files.id');
            $pendingRows = SystemFile::where(function ($q) use ($fileIds, $resource) {
                $q->whereIn('source_file_id', $fileIds)
                    ->orWhere(function ($q2) use ($resource) {
                        $q2->where('resource_id', $resource->id)->whereNull('source_file_id');
                    });
            })
                ->whereIn('purpose', [$perFilePurpose, $generatedPurpose])
                ->where('is_active', true)
                ->whereNull('applied_at')
                ->get();

            if ($pendingRows->isNotEmpty()) {
                SystemFile::whereIn('id', $pendingRows->pluck('id'))->update(['applied_at' => now()]);

                // Per row: ACCEPTED if its stored value matches what the user committed,
                // DISMISSED otherwise. The user's committed value is in $newValue.
                $committed = $this->payloadValueExcerpt($newValue);
                foreach ($pendingRows as $row) {
                    $rowValue = $row->metadata['value'] ?? null;
                    $isAccepted = is_string($rowValue) && is_string($newValue) && $rowValue === $newValue;
                    if ($isAccepted) {
                        $this->eventLogger->suggestionApplied(
                            resourceId: $resource->id,
                            field: $field,
                            byAity: false,
                            systemFileId: $row->id,
                            payload: [
                                'value' => $committed,
                                'source_file_id' => $row->source_file_id,
                                'purpose' => $row->purpose,
                            ],
                        );
                    } else {
                        $this->eventLogger->suggestionDismissed(
                            resourceId: $resource->id,
                            field: $field,
                            systemFileId: $row->id,
                            payload: [
                                'via' => 'value_overridden',
                                'suggested_value' => $this->payloadValueExcerpt($rowValue),
                                'committed_value' => $committed,
                                'source_file_id' => $row->source_file_id,
                                'purpose' => $row->purpose,
                            ],
                        );
                    }
                }
            }
        }

        return $data;
    }

    /**
     * Locate an active AI suggestion across the resource's files whose value matches the
     * given text. Returns the system_file row (specifically, source_file_id) on match.
     */
    private function findMatchingAiSuggestion(Resource $resource, string $purpose, ?string $value): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }

        $fileIds = $resource->files()->pluck('files.id');
        if ($fileIds->isEmpty()) {
            return null;
        }

        $row = SystemFile::whereIn('source_file_id', $fileIds)
            ->where('purpose', $purpose)
            ->where('is_active', true)
            ->get(['source_file_id', 'metadata'])
            ->first(fn ($sf) => ($sf->metadata['value'] ?? null) === $value);

        return $row ? ['source_file_id' => $row->source_file_id] : null;
    }

    /**
     * Delete a resource.
     */
    public function deleteResource(string $id): bool
    {
        $resource = $this->getResourceById($id);

        if (! $resource) {
            return false;
        }

        // Draft resources were never published — bypass trash and delete permanently
        if ($resource->state === ResourceState::DRAFT) {
            $this->permanentlyDelete($resource);

            return true;
        }

        return $resource->delete();
    }

    /**
     * Permanently remove a resource: files, media, storage folder, and DB record.
     * Must be called instead of plain forceDelete() to avoid orphaned files on disk.
     */
    public function permanentlyDelete(Resource $resource): void
    {
        $resource->files()->delete();
        $resource->clearMediaCollection('files');
        // Public disk: Spatie MediaLibrary uploads
        Storage::disk(config('media-library.disk_name', 'public'))
            ->deleteDirectory($resource->id);
        // Local disk: AITY suggestion archives ({resourceId}/archives/*.json)
        Storage::disk('local')->deleteDirectory($resource->id);
        $resource->forceDelete();
    }

    /**
     * Purge all derived artifacts of a single file before it is deleted.
     *
     * The system_files.source_file_id FK is nullOnDelete, so deleting a File leaves its
     * derived rows (extracted text, AITY suggestions) orphaned with a null source_file_id
     * and their on-disk archives ({resourceId}/archives/{fileId}_*.json[.gz]) untouched.
     * This removes the on-disk archives, the FileChunk rows, and the SystemFile rows so
     * nothing survives the file it described.
     *
     * Call this *before* $file->delete() so source_file_id is still resolvable.
     */
    public function purgeFileArtifacts(File $file): void
    {
        $systemFiles = SystemFile::where('source_file_id', $file->id)->get();

        if ($systemFiles->isEmpty()) {
            return;
        }

        foreach ($systemFiles as $systemFile) {
            try {
                Storage::disk($systemFile->disk ?? 'local')->delete($systemFile->path);
            } catch (\Throwable $e) {
                Log::warning(
                    "Failed to delete archive for system_file {$systemFile->id} ({$systemFile->path}): ".$e->getMessage()
                );
            }
        }

        $systemFileIds = $systemFiles->pluck('id');
        FileChunk::whereIn('archive_file_id', $systemFileIds)->delete();
        SystemFile::whereIn('id', $systemFileIds)->delete();
    }

    /**
     * Enforce the composition invariants before a file joins a resource
     * (docs/RESOURCE_MODEL.md, Epic 1.1): single canonical, components never
     * beside a canonical, canonical carries no relation, single snapshot.
     * Every attachment path (SPA upload, MCP, future SDK) must pass through
     * here — the invariants live in the service layer, not the controller.
     *
     * Mutates sibling files (demotions, snapshot clearing) and returns the
     * normalized relation for the incoming file.
     *
     * @param  list<string>|null  $usage
     *
     * @throws InvalidResourceComposition when a component is added to a resource that has a canonical
     */
    public function applyFileRoleInvariants(Resource $resource, FileRole $role, ?FileRelation $relation, ?array $usage): ?FileRelation
    {
        // Canonical files cannot have a relation per spec
        if ($role === FileRole::CANONICAL) {
            $relation = null;

            // Entering (or staying in) canonical+supporting mode: existing
            // components and any previous canonical become supporting.
            $resource->files()
                ->whereIn('role', [FileRole::CANONICAL->value, FileRole::COMPONENT->value])
                ->update(['role' => FileRole::SUPPORTING->value]);
        }

        if ($role === FileRole::COMPONENT) {
            $hasCanonical = $resource->files()
                ->where('role', FileRole::CANONICAL->value)
                ->where('is_active', true)
                ->exists();

            if ($hasCanonical) {
                throw new InvalidResourceComposition(
                    'Cannot upload a component file: this resource already has a canonical file. Use supporting for auxiliary files.'
                );
            }
        }

        // Snapshot exclusivity: at most one file carries the snapshot flag
        if (is_array($usage) && in_array('snapshot', $usage, true)) {
            $resource->files()->each(function (File $f) {
                $currentUsage = $f->usage ?? [];
                $updated = array_values(array_filter($currentUsage, fn ($u) => $u !== 'snapshot'));
                $f->update(['usage' => empty($updated) ? null : $updated]);
            });
        }

        return $relation;
    }

    /**
     * Set a file as the canonical file for its resource.
     *
     * Demotes any existing canonical (and any component peers) to 'supporting' — the
     * only role peers may hold alongside a canonical — then promotes $file to 'canonical'.
     * Enforces the single-canonical-per-resource constraint at the application layer
     * (MySQL has no partial unique indexes).
     *
     * After promotion, recalculates promoted metadata and triggers an ES re-index.
     */
    public function setCanonical(Resource $resource, File $file): void
    {
        DB::transaction(function () use ($resource, $file) {
            // We're entering or staying in canonical+supporting mode. Any other
            // canonical (max one in practice) and any leftover component files
            // both become supporting — that's the only role the peer set can
            // legally take alongside a canonical.
            $resource->files()
                ->whereIn('role', [FileRole::CANONICAL->value, FileRole::COMPONENT->value])
                ->where('id', '!=', $file->id)
                ->update(['role' => FileRole::SUPPORTING->value]);

            // Canonical files cannot have a relation per spec.
            // Use query builder to guarantee both columns are written regardless of model dirty state.
            File::where('id', $file->id)->update(['role' => FileRole::CANONICAL->value, 'relation' => null]);
        });

        // The composition changed — synthesized resource-level suggestions
        // describe a state that no longer exists.
        $this->invalidateResourceLevelSuggestions($resource);

        $this->refreshDerivedArtifacts($resource);

        // If the newly canonical file is a PDF or audio type and the resource has no snapshot
        // yet, dispatch preview extraction. This handles files that were uploaded as non-canonical
        // (bypassing the ExtractFileText → ExtractEmbeddedPreview chain) and later promoted.
        $needsExtraction = $file->isAudio() || $file->mime_type === 'application/pdf';
        $hasSnapshot = $resource->files()
            ->whereJsonContains('usage', 'snapshot')
            ->where('is_active', true)
            ->exists();

        if ($needsExtraction && ! $hasSnapshot) {
            // Mark the canonical PDF/audio as the snapshot immediately so the UI reflects it,
            // then dispatch preview extraction to render a PREVIEW_SNAPSHOT system file.
            $this->setSnapshot($resource, $file);
            ExtractEmbeddedPreview::dispatchSync($file->id);
        }
    }

    /**
     * Transfer the snapshot role to $file, clearing it from all other resource files.
     * When the new snapshot is an image, also deactivates any PREVIEW_SNAPSHOT SystemFile
     * that was previously rendered for a PDF/audio file.
     */
    public function setSnapshot(Resource $resource, File $file): void
    {
        DB::transaction(function () use ($resource, $file) {
            // Remove snapshot from every other file
            foreach ($resource->files()->where('id', '!=', $file->id)->get() as $other) {
                $usage = array_values(array_filter($other->usage ?? [], fn ($u) => $u !== 'snapshot'));
                File::where('id', $other->id)->update(['usage' => json_encode($usage)]);
            }
            // Add snapshot to the target file
            $usage = $file->usage ?? [];
            if (! in_array('snapshot', $usage, true)) {
                $usage[] = 'snapshot';
            }
            File::where('id', $file->id)->update(['usage' => json_encode($usage)]);

            // When switching to an image snapshot, the rendered PREVIEW_SNAPSHOT system file
            // is no longer relevant — deactivate it so preview_snapshot_url becomes null.
            if ($file->isImage()) {
                SystemFile::where('resource_id', $resource->id)
                    ->where('purpose', SystemFilePurpose::PREVIEW_SNAPSHOT->value)
                    ->where('is_active', true)
                    ->update(['is_active' => false]);
            }
        });
    }

    /**
     * Guarantee a single, valid snapshot (the "starred" file) for a resource, rendering its
     * preview if needed. Idempotent and safe to call repeatedly — designed to run at commit
     * time, after uncommitted_at has been cleared.
     *
     * Why: parallel multi-component uploads can't safely each claim the snapshot (the per-upload
     * checks race and ExtractEmbeddedPreview's guard makes the concurrent renders skip each
     * other). So component uploads no longer auto-star; instead this resolves the snapshot once,
     * deterministically, here.
     *
     * Behaviour:
     *  - Respects an existing user choice: if exactly ONE committed file is starred, it keeps it
     *    (only rendering the preview if a PDF/audio still lacks one).
     *  - Otherwise (zero, or several ambiguous stars) it collapses to a single deterministic
     *    best candidate: canonical first, then images, then PDF/audio, then anything else.
     */
    public function ensureSnapshot(Resource $resource): void
    {
        $files = $resource->files()
            ->where('is_active', true)
            ->whereNull('uncommitted_at')
            ->get();

        if ($files->isEmpty()) {
            return;
        }

        $starred = $files->filter(fn (File $f) => $f->isSnapshot())->values();

        // Exactly one star already chosen (e.g. the canonical fast-path or an explicit user
        // pick): honour it, just make sure a previewable type actually has a rendered preview.
        if ($starred->count() === 1) {
            $this->renderPreviewIfMissing($resource, $starred->first());

            return;
        }

        // Zero or multiple stars → pick the single best candidate deterministically.
        $best = $this->pickBestSnapshotCandidate($files);
        if (! $best) {
            return;
        }

        $this->setSnapshot($resource, $best);
        $this->renderPreviewIfMissing($resource, $best);
    }

    /**
     * Choose the most preview-worthy file: canonical wins outright; otherwise prefer images,
     * then PDF/audio (renderable), then any remaining file. Ties break on lowest id (the
     * earliest-uploaded file in a batch), so the result is stable across calls.
     */
    private function pickBestSnapshotCandidate(Collection $files): ?File
    {
        $canonical = $files->first(fn (File $f) => $f->isCanonical());
        if ($canonical) {
            return $canonical;
        }

        $rank = function (File $f): int {
            if ($f->isImage()) {
                return 0;
            }
            if ($f->isAudio() || $f->mime_type === 'application/pdf') {
                return 1;
            }

            return 2;
        };

        return $files
            ->sortBy(fn (File $f) => [$rank($f), $f->id])
            ->first();
    }

    /**
     * Render the embedded preview for a PDF/audio snapshot file when the active preview is
     * missing OR was rendered from a different file (e.g. a stray preview left behind when
     * parallel uploads raced). Images need no render (they are their own preview).
     */
    private function renderPreviewIfMissing(Resource $resource, File $file): void
    {
        if (! ($file->isAudio() || $file->mime_type === 'application/pdf')) {
            return;
        }

        $activePreview = SystemFile::where('resource_id', $resource->id)
            ->where('purpose', SystemFilePurpose::PREVIEW_SNAPSHOT->value)
            ->where('is_active', true)
            ->latest('updated_at')
            ->first();

        // Re-render when there's no preview, or the existing one is for a different source file
        // so the thumbnail matches the file that is actually starred.
        if (! $activePreview || (string) $activePreview->source_file_id !== (string) $file->id) {
            ExtractEmbeddedPreview::dispatchSync($file->id);
        }
    }

    /**
     * Merge tika_metadata from the resource's contributing files into
     * Resource.promoted_file_metadata.
     *
     * Contribution follows the role model (docs/RESOURCE_MODEL.md): a
     * canonical file is the sole contributor; without one, ALL active
     * component files contribute equally — each component represents the
     * whole resource. Components merge in manifest order (position, then
     * upload order) and on key conflicts the first contributor wins.
     * Supporting files never contribute.
     */
    public function recalculatePromotedMetadata(Resource $resource): void
    {
        $t0 = hrtime(true);

        $contributorIds = $this->metadataContributorIds($resource);

        if ($contributorIds === []) {
            $resource->update(['promoted_file_metadata' => null]);
            AiActivityLogger::metadataPromote($resource->id, null, [], null, null,
                (int) round((hrtime(true) - $t0) / 1e6));

            return;
        }

        $merged = [];
        $sfDebug = [];

        foreach ($contributorIds as $fileId) {
            $systemFiles = SystemFile::where('resource_id', $resource->id)
                ->where('source_file_id', $fileId)
                ->where('is_active', true)
                ->orderBy('updated_at')
                ->get();

            // Within one file, later extractions refine earlier ones
            $fileMerged = [];
            foreach ($systemFiles as $sf) {
                $hasTika = ! empty($sf->metadata['tika_metadata']);
                $sfDebug[] = [
                    'id' => $sf->id,
                    'source_file_id' => $fileId,
                    'purpose' => $sf->purpose instanceof \BackedEnum ? $sf->purpose->value : (string) $sf->purpose,
                    'has_tika' => $hasTika,
                ];
                if ($hasTika) {
                    $fileMerged = array_merge($fileMerged, $sf->metadata['tika_metadata']);
                }
            }

            // Across files, first-by-position wins conflicts (union keeps existing keys)
            $merged += $fileMerged;
        }

        $promoted = ! empty($merged) ? $merged : null;
        $resource->update(['promoted_file_metadata' => $promoted]);

        AiActivityLogger::metadataPromote(
            $resource->id,
            $contributorIds[0],
            $sfDebug,
            $promoted ? array_keys($promoted) : null,
            $promoted,
            (int) round((hrtime(true) - $t0) / 1e6),
        );
    }

    /**
     * Apply a non-promotion role/relation change to a file — the demotion
     * twin of setCanonical(). Same invariants, same derived-artifact refresh:
     * promoted metadata, resource embedding, and the ES document all follow
     * the new contributor set, whichever direction the role moved.
     *
     * Promotion to canonical must go through setCanonical() instead — the
     * controller routes it there.
     *
     * @param  array{role?: string, relation?: string|null}  $validated
     */
    public function updateFileRole(Resource $resource, File $file, array $validated): void
    {
        // Invariant: canonical files never carry a relation — covers
        // relation-only updates on the current canonical.
        $targetRole = isset($validated['role']) ? FileRole::from($validated['role']) : $file->role;
        if ($targetRole === FileRole::CANONICAL && array_key_exists('relation', $validated)) {
            $validated['relation'] = null;
        }

        $oldRole = $file->role;
        File::where('id', $file->id)->update($validated);

        if (! isset($validated['role'])) {
            return;
        }

        // Reverse transition: canonical removed → supporting peers revert to
        // component (back to multi-component mode).
        if ($oldRole === FileRole::CANONICAL && FileRole::from($validated['role']) !== FileRole::CANONICAL) {
            $resource->files()
                ->where('role', FileRole::SUPPORTING->value)
                ->where('id', '!=', $file->id)
                ->update(['role' => FileRole::COMPONENT->value]);
        }

        // Any role change alters the contributor set — stale synthesis out,
        // fresh metadata/embedding/document in.
        $this->invalidateResourceLevelSuggestions($resource);
        $this->refreshDerivedArtifacts($resource);
    }

    /**
     * Recompute every artifact derived from the contributor set — promoted
     * metadata, the resource-level mean embedding, and the ES document.
     * Called on any composition change (promotion, demotion, role moves).
     */
    private function refreshDerivedArtifacts(Resource $resource): void
    {
        $this->recalculatePromotedMetadata($resource);
        $this->recalculateResourceEmbedding($resource);
        IndexResourceToElasticsearch::dispatchSync($resource->id);
    }

    /**
     * Deactivate unapplied resource-level (null-source) AI suggestions —
     * they were synthesized under a composition that no longer exists.
     * Applied ones stay for history; per-file suggestions keep living with
     * their files. The next aity-approve run re-synthesizes.
     */
    private function invalidateResourceLevelSuggestions(Resource $resource): void
    {
        SystemFile::where('resource_id', $resource->id)
            ->whereNull('source_file_id')
            ->whereIn('purpose', [
                SystemFilePurpose::AI_GENERATED_NAME->value,
                SystemFilePurpose::AI_GENERATED_DESCRIPTION->value,
                SystemFilePurpose::AI_GENERATED_TAGS->value,
            ])
            ->where('is_active', true)
            ->whereNull('applied_at')
            ->update(['is_active' => false]);
    }

    /**
     * Recompute the resource-level embedding (ARCHITECTURE_AND_ROADMAP.md §8):
     * the mean of the contributing files' chunk vectors — the canonical's, or
     * all components' equally. Supporting chunks stay k-NN searchable but
     * never shape the resource's own vector. Persisted in MySQL; the resource
     * update re-triggers ES indexing so the document carries it.
     */
    public function recalculateResourceEmbedding(Resource $resource): void
    {
        $resource->loadMissing('collection.searchIndex');
        $indexName = $resource->collection?->searchIndex?->index_name;

        if (! $indexName) {
            return;
        }

        $contributorIds = $this->metadataContributorIds($resource);

        $es = app(ElasticsearchService::class);
        $chunksIndex = $es->buildChunksIndexName($indexName);
        $vectors = $es->fetchChunkVectors($chunksIndex, $resource->id, $contributorIds);

        // Chunkless resources (images, un-transcribed media) still have
        // meaning: fall back to the synthetic metadata chunk so the resource
        // ranks in semantic card search instead of staying invisible.
        if ($vectors === []) {
            $metaVector = $es->fetchMetaChunkVector($chunksIndex, $resource->id);
            $vectors = $metaVector !== null ? [$metaVector] : [];
        }

        $mean = $this->meanVector($vectors);

        // Avoid churn (and re-index loops) when nothing changed. Loose
        // comparison: the JSON round-trip may turn 2.0 into int 2.
        if ($mean == $resource->embedding) {
            return;
        }

        $resource->update([
            'embedding' => $mean,
            'embedding_updated_at' => $mean !== null ? now() : null,
        ]);
    }

    /**
     * Element-wise mean of equally weighted vectors (`mean` aggregation, §8).
     *
     * @param  list<list<float>>  $vectors
     * @return list<float>|null
     */
    private function meanVector(array $vectors): ?array
    {
        if ($vectors === []) {
            return null;
        }

        $dims = count($vectors[0]);
        $sum = array_fill(0, $dims, 0.0);

        foreach ($vectors as $vector) {
            if (count($vector) !== $dims) {
                continue; // mixed dimensions (model change mid-flight) — skip
            }
            foreach ($vector as $i => $value) {
                $sum[$i] += $value;
            }
        }

        $count = count($vectors);

        return array_map(fn (float $v) => $v / $count, $sum);
    }

    /**
     * The files whose metadata/text represent the resource: the active
     * canonical alone, or all active components in manifest order
     * (position asc nulls-last, then upload order). Supporting excluded.
     *
     * @return list<string>
     */
    public function metadataContributorIds(Resource $resource): array
    {
        $canonicalFileId = File::where('resource_id', $resource->id)
            ->where('role', FileRole::CANONICAL->value)
            ->where('is_active', true)
            ->whereNull('uncommitted_at')
            ->value('id');

        if ($canonicalFileId) {
            return [$canonicalFileId];
        }

        return File::where('resource_id', $resource->id)
            ->where('role', FileRole::COMPONENT->value)
            ->where('is_active', true)
            ->whereNull('uncommitted_at')
            ->orderByRaw('position IS NULL, position ASC')
            ->orderBy('created_at')
            ->pluck('id')
            ->all();
    }
}
