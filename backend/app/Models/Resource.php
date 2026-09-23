<?php

namespace App\Models;

use App\Enums\AityStatus;
use App\Enums\FileRole;
use App\Enums\ResourceState;
use App\Enums\ResourceType;
use App\Enums\SystemFilePurpose;
use App\Jobs\DeleteResourceFromElasticsearch;
use App\Jobs\IndexResourceToElasticsearch;
use App\Jobs\UpsertResourceMetadataChunk;
use App\Models\Traits\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Collections\MediaCollection;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * @property string $id
 * @property int|null $collection_id
 * @property string $organization_id
 * @property string $user_owner_id
 * @property string $name
 * @property string|null $name_origin
 * @property string|null $name_source_file_id
 * @property string|null $name_set_by
 * @property Carbon|null $name_set_at
 * @property string|null $slug
 * @property string|null $description
 * @property string|null $description_origin
 * @property string|null $description_source_file_id
 * @property string|null $description_set_by
 * @property Carbon|null $description_set_at
 * @property ResourceType $type
 * @property ResourceState $state
 * @property string $aity_status
 * @property array<array-key, mixed>|null $metadata
 * @property array<array-key, mixed>|null $promoted_file_metadata
 * @property list<float>|null $embedding Resource-level mean vector (§8)
 * @property Carbon|null $embedding_updated_at
 * @property array<array-key, mixed> $payload
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property string|null $tags_origin
 * @property string|null $tags_source_file_id
 * @property string|null $tags_set_by
 * @property Carbon|null $tags_set_at
 * @property-read File|null $canonicalFile
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Category> $categories
 * @property-read int|null $categories_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, FileChunk> $chunks
 * @property-read int|null $chunks_count
 * @property-read Collection|null $collection
 * @property-read \Illuminate\Database\Eloquent\Collection<int, File> $documentFiles
 * @property-read int|null $document_files_count
 * @property-read SystemFile|null $extractedTextFile
 * @property-read \Illuminate\Database\Eloquent\Collection<int, File> $files
 * @property-read int|null $files_count
 * @property-read SystemFile|null $latestAiGeneratedDescriptionSystemFile
 * @property-read SystemFile|null $latestAiGeneratedDescriptionSystemFileForDisplay
 * @property-read SystemFile|null $latestAiGeneratedNameSystemFile
 * @property-read SystemFile|null $latestAiGeneratedNameSystemFileForDisplay
 * @property-read SystemFile|null $latestAiGeneratedTagsSystemFile
 * @property-read SystemFile|null $latestAiGeneratedTagsSystemFileForDisplay
 * @property-read MediaCollection<int, Media> $media
 * @property-read int|null $media_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, File> $mediaFiles
 * @property-read int|null $media_files_count
 * @property-read Organization $organization
 * @property-read User|null $owner
 * @property-read SystemFile|null $previewSnapshotSystemFile
 * @property-read mixed $preview_snapshot_url
 * @property-read \Illuminate\Database\Eloquent\Collection<int, SemanticTag> $semanticTags
 * @property-read int|null $semantic_tags_count
 * @property-read File|null $snapshotFile
 * @property-read \Illuminate\Database\Eloquent\Collection<int, SystemFile> $systemFiles
 * @property-read int|null $system_files_count
 * @property-read SystemFile|null $tikaMetadataFile
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Workspace> $workspaces
 * @property-read int|null $workspaces_count
 *
 * @method static \Database\Factories\ResourceFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Resource newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Resource newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Resource onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Resource query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Resource whereActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Resource whereAityStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Resource whereCollectionId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Resource whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Resource whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Resource whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Resource whereDescriptionOrigin($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Resource whereDescriptionSetAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Resource whereDescriptionSetBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Resource whereDescriptionSourceFileId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Resource whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Resource whereMetadata($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Resource whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Resource whereNameOrigin($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Resource whereNameSetAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Resource whereNameSetBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Resource whereNameSourceFileId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Resource whereOrganizationId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Resource wherePayload($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Resource wherePromotedFileMetadata($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Resource wherePublishedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Resource whereSlug($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Resource whereTagsOrigin($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Resource whereTagsSetAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Resource whereTagsSetBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Resource whereTagsSourceFileId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Resource whereType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Resource whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Resource whereUserOwnerId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Resource whereVisibility($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Resource withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Resource withoutTrashed()
 *
 * @mixin \Eloquent
 */
class Resource extends Model implements HasMedia
{
    use HasFactory, HasUuid, InteractsWithMedia, SoftDeletes;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'organization_id',
        'collection_id',
        'user_owner_id',
        'type',
        'name',
        'slug',
        'description',
        'state',
        'payload',
        'metadata',
        'promoted_file_metadata',
        'embedding',
        'embedding_updated_at',
        'aity_status',
        'name_origin', 'name_source_file_id', 'name_set_by', 'name_set_at',
        'description_origin', 'description_source_file_id', 'description_set_by', 'description_set_at',
        'tags_origin', 'tags_source_file_id', 'tags_set_by', 'tags_set_at',
    ];

    protected $appends = ['preview_snapshot_url'];

    protected $casts = [
        'type' => ResourceType::class,
        'state' => ResourceState::class,
        'payload' => 'array',
        'metadata' => 'array',
        'promoted_file_metadata' => 'array',
        'embedding' => 'array',
        'embedding_updated_at' => 'datetime',
        'name_set_at' => 'datetime',
        'description_set_at' => 'datetime',
        'tags_set_at' => 'datetime',
    ];

    public const FIELD_AGENT_AITY = 'aity';

    public const FIELD_ORIGIN_AITY_SUGGESTION = 'aity_suggestion';

    public const FIELD_ORIGIN_AITY_GENERATED = 'aity_generated';

    public const FIELD_ORIGIN_USER = 'user';

    /**
     * URL of the rendered preview image for PDF/audio resources.
     * Sourced from the PREVIEW_SNAPSHOT SystemFile (internal, not user-visible).
     * Returns null when the relation is not loaded or no preview has been rendered yet.
     */
    protected function previewSnapshotUrl(): Attribute
    {
        return Attribute::make(
            get: function () {
                if (! $this->relationLoaded('previewSnapshotSystemFile')) {
                    return null;
                }
                $sf = $this->previewSnapshotSystemFile;
                if (! $sf) {
                    return null;
                }
                $url = Storage::disk($sf->disk)->url($sf->path);

                return $url.'?v='.$sf->updated_at->timestamp;
            }
        );
    }

    /**
     * Derive the resource-level AI processing status from all its files and
     * suggestion SystemFiles, then persist it without triggering model events.
     *
     * Priority: queued > aity_in_progress > suggestions_made > user_review_done > automatic_review_done > not_applicable
     *
     * Terminal split: if every applied suggestion was applied by AITY (applied_by_aity=true),
     * the resource is AUTOMATIC_REVIEW_DONE; any user-applied (false/null) suggestion
     * promotes it to USER_REVIEW_DONE.
     */
    public function recomputeAndSaveAityStatus(): void
    {
        $suggestionPurposes = [
            SystemFilePurpose::AI_SUGGESTED_TAGS->value,
            SystemFilePurpose::AI_SUGGESTED_NAME->value,
            SystemFilePurpose::AI_SUGGESTED_DESCRIPTION->value,
            SystemFilePurpose::AI_SUGGESTED_METADATA->value,
            SystemFilePurpose::AI_GENERATED_TAGS->value,
            SystemFilePurpose::AI_GENERATED_NAME->value,
            SystemFilePurpose::AI_GENERATED_DESCRIPTION->value,
        ];

        // Uncommitted files (session-scoped edit-modal uploads) and their derived
        // suggestion system_files must not influence the resource-level aity_status.
        // Excluding source_file_id IS NULL would drop resource-scoped synthesis rows,
        // so the suggestion filter uses whereNotExists: only rows whose source file
        // is uncommitted are dropped; resource-only rows survive.
        $excludeUncommittedSuggestions = function ($q) {
            $q->whereNotExists(function ($sub) {
                $sub->select(\DB::raw(1))
                    ->from('files')
                    ->whereColumn('files.id', 'system_files.source_file_id')
                    ->whereNotNull('files.uncommitted_at');
            });
        };

        foreach ($this->files()->committed()->select('processing_status')->get() as $file) {
            $stage = $file->processing_status['stage'] ?? null;
            if (in_array($stage, ['queued', 'extracting'])) {
                $this->updateQuietly(['aity_status' => AityStatus::QUEUED->value]);

                return;
            }
        }

        foreach ($this->files()->committed()->select('processing_status')->get() as $file) {
            if (($file->processing_status['stage'] ?? null) === 'ai_analyzing') {
                $this->updateQuietly(['aity_status' => AityStatus::AITY_IN_PROGRESS->value]);

                return;
            }
        }

        $hasPending = SystemFile::where('resource_id', $this->id)
            ->whereIn('purpose', $suggestionPurposes)
            ->where('is_active', true)
            ->whereNull('applied_at')
            ->tap($excludeUncommittedSuggestions)
            ->exists();

        if ($hasPending) {
            $this->updateQuietly(['aity_status' => AityStatus::SUGGESTIONS_MADE->value]);

            return;
        }

        $hasAny = SystemFile::where('resource_id', $this->id)
            ->whereIn('purpose', $suggestionPurposes)
            ->tap($excludeUncommittedSuggestions)
            ->exists();

        if (! $hasAny) {
            $this->updateQuietly(['aity_status' => AityStatus::NOT_APPLICABLE->value]);

            return;
        }

        $hasUserApplied = SystemFile::where('resource_id', $this->id)
            ->whereIn('purpose', $suggestionPurposes)
            ->whereNotNull('applied_at')
            ->where(fn ($q) => $q->where('applied_by_aity', false)->orWhereNull('applied_by_aity'))
            ->tap($excludeUncommittedSuggestions)
            ->exists();

        $this->updateQuietly([
            'aity_status' => $hasUserApplied
                ? AityStatus::USER_REVIEW_DONE->value
                : AityStatus::AUTOMATIC_REVIEW_DONE->value,
        ]);
    }

    /**
     * The one filter for "should anyone see this": drafts are unfinished and
     * archived ones are withdrawn. Every listing, search and projection goes
     * through it, so the answer cannot drift between surfaces the way the old
     * `active` / `visibility != draft` pair did.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeVisible(Builder $query): Builder
    {
        return $query->where('state', ResourceState::LIVE->value);
    }

    /**
     * Boot the model and register Elasticsearch indexing events.
     */
    protected static function boot()
    {
        parent::boot();

        static::created(function ($resource) {
            if ($resource->state->isVisible()) {
                IndexResourceToElasticsearch::dispatchSync($resource->id);
            }
        });

        static::updated(function ($resource) {
            if ($resource->state->isVisible()) {
                IndexResourceToElasticsearch::dispatchSync($resource->id);
                // Re-embed the metadata chunk whenever the human-readable fields change.
                // wasChanged() reads the post-save dirty map, so it's safe here.
                if ($resource->wasChanged(['name', 'description'])) {
                    UpsertResourceMetadataChunk::dispatch($resource->id);
                }
            } else {
                DeleteResourceFromElasticsearch::dispatchSync($resource->id);
            }
        });

        static::softDeleted(function ($resource) {
            DeleteResourceFromElasticsearch::dispatchSync($resource->id);
        });

        static::deleting(function ($resource) {
            if ($resource->isForceDeleting()) {
                DeleteResourceFromElasticsearch::dispatchSync($resource->id);
            }
        });

        static::restored(function ($resource) {
            if ($resource->state->isVisible()) {
                IndexResourceToElasticsearch::dispatchSync($resource->id);
            }
        });
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function collection(): BelongsTo
    {
        return $this->belongsTo(Collection::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_owner_id');
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'category_resource');
    }

    public function workspaces(): BelongsToMany
    {
        return $this->belongsToMany(Workspace::class, 'dam_resource_workspace');
    }

    public function semanticTags(): BelongsToMany
    {
        return $this->belongsToMany(SemanticTag::class, 'semantic_tag_resource');
    }

    // ─── Resource-level AITY-generated suggestions (multi-component synthesis,
    //     workspace tag dedup). source_file_id is NULL on these rows. ────────

    public function latestAiGeneratedNameSystemFile(): HasOne
    {
        return $this->hasOne(SystemFile::class, 'resource_id')
            ->ofMany(
                ['id' => 'max'],
                fn ($q) => $q->where('purpose', SystemFilePurpose::AI_GENERATED_NAME->value)
                    ->where('is_active', true)
                    ->whereNull('applied_at')
                    ->whereNull('source_file_id')
            );
    }

    public function latestAiGeneratedDescriptionSystemFile(): HasOne
    {
        return $this->hasOne(SystemFile::class, 'resource_id')
            ->ofMany(
                ['id' => 'max'],
                fn ($q) => $q->where('purpose', SystemFilePurpose::AI_GENERATED_DESCRIPTION->value)
                    ->where('is_active', true)
                    ->whereNull('applied_at')
                    ->whereNull('source_file_id')
            );
    }

    public function latestAiGeneratedTagsSystemFile(): HasOne
    {
        return $this->hasOne(SystemFile::class, 'resource_id')
            ->ofMany(
                ['id' => 'max'],
                fn ($q) => $q->where('purpose', SystemFilePurpose::AI_GENERATED_TAGS->value)
                    ->where('is_active', true)
                    ->whereNull('applied_at')
                    ->whereNull('source_file_id')
            );
    }

    public function latestAiGeneratedNameSystemFileForDisplay(): HasOne
    {
        return $this->hasOne(SystemFile::class, 'resource_id')
            ->ofMany(
                ['id' => 'max'],
                fn ($q) => $q->where('purpose', SystemFilePurpose::AI_GENERATED_NAME->value)
                    ->where('is_active', true)
                    ->whereNull('source_file_id')
            );
    }

    public function latestAiGeneratedDescriptionSystemFileForDisplay(): HasOne
    {
        return $this->hasOne(SystemFile::class, 'resource_id')
            ->ofMany(
                ['id' => 'max'],
                fn ($q) => $q->where('purpose', SystemFilePurpose::AI_GENERATED_DESCRIPTION->value)
                    ->where('is_active', true)
                    ->whereNull('source_file_id')
            );
    }

    public function latestAiGeneratedTagsSystemFileForDisplay(): HasOne
    {
        return $this->hasOne(SystemFile::class, 'resource_id')
            ->ofMany(
                ['id' => 'max'],
                fn ($q) => $q->where('purpose', SystemFilePurpose::AI_GENERATED_TAGS->value)
                    ->where('is_active', true)
                    ->whereNull('source_file_id')
            );
    }

    /** @return HasMany<File, $this> */
    public function files(): HasMany
    {
        return $this->hasMany(File::class)
            ->with('media')
            ->orderByRaw("FIELD(role, 'canonical', 'component', 'supporting')")
            ->orderBy('created_at');
    }

    /**
     * Get the canonical file for this resource (the authoritative source of truth).
     * There is exactly one canonical file per resource.
     */
    public function canonicalFile(): HasOne
    {
        return $this->hasOne(File::class)
            ->where('role', FileRole::CANONICAL->value)
            ->where('is_active', true)
            ->whereNull('uncommitted_at');
    }

    /**
     * Get the snapshot file — the default preview image for this resource.
     * Identified by usage containing 'snapshot'. Uncommitted session uploads
     * are excluded so the catalogue thumbnail doesn't reflect unsaved state.
     */
    public function snapshotFile(): HasOne
    {
        return $this->hasOne(File::class)
            ->whereJsonContains('usage', 'snapshot')
            ->where('is_active', true)
            ->whereNull('uncommitted_at')
            ->latest();
    }

    /**
     * Get media files only (images, videos, audio).
     */
    public function mediaFiles(): HasMany
    {
        return $this->hasMany(File::class)
            ->whereNotNull('media_id')
            ->with('media');
    }

    /**
     * Get document files only (non-media: PDFs, ZIPs, etc.).
     */
    public function documentFiles(): HasMany
    {
        return $this->hasMany(File::class)->whereNull('media_id');
    }

    public function systemFiles(): HasMany
    {
        return $this->hasMany(SystemFile::class);
    }

    public function extractedTextFile(): HasOne
    {
        // latestOfMany() is broken here for the same reason as previewSnapshotSystemFile (its
        // MAX subquery ignores the chained where()s and picks the resource's globally-latest
        // SystemFile, which the purpose filter then rejects → null even when active rows exist,
        // e.g. a multi-component resource with several extracted_text rows). Use a plain ordered
        // HasOne to return the most recently updated active extracted-text file.
        return $this->hasOne(SystemFile::class)
            ->where('purpose', SystemFilePurpose::EXTRACTED_TEXT)
            ->where('is_active', true)
            ->latest('updated_at');
    }

    public function tikaMetadataFile(): HasOne
    {
        // Same latestOfMany() pitfall as above — see extractedTextFile()/previewSnapshotSystemFile().
        return $this->hasOne(SystemFile::class)
            ->where('purpose', SystemFilePurpose::TIKA_METADATA)
            ->where('is_active', true)
            ->latest('updated_at');
    }

    /**
     * Get the rendered preview snapshot system file for PDF/audio resources.
     * This is internal (not user-visible) and is used to build preview_snapshot_url.
     */
    public function previewSnapshotSystemFile(): HasOne
    {
        // NOTE: do NOT use latestOfMany() here. Laravel's ofMany inner MAX-aggregate subquery
        // ignores where() clauses chained before it, so it picks the resource's globally-latest
        // SystemFile (typically an ai_suggested_* row created after the preview) and the outer
        // purpose filter then rejects it → the relation resolves to null and the card shows no
        // preview. Only one PREVIEW_SNAPSHOT is ever active per resource (savePreview deactivates
        // the rest), so a plain ordered HasOne is both correct and unambiguous.
        return $this->hasOne(SystemFile::class)
            ->where('purpose', SystemFilePurpose::PREVIEW_SNAPSHOT)
            ->where('is_active', true)
            ->latest('updated_at');
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(FileChunk::class)->orderBy('sequence');
    }

    /**
     * NOTE on format: media-library defaults every conversion to `->format('jpg')`
     * (see Conversion::__construct), which flattens the alpha channel — a
     * transparent PNG comes back with its transparent regions filled solid black,
     * baked into the pixels where no amount of frontend styling can recover it.
     * WebP is chosen over keepOriginalImageFormat() because it preserves alpha
     * *and* stays smaller than PNG on the photographic assets that dominate the
     * collections; support is universal in the browsers the SPA targets.
     */
    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('thumbnail')
            // Bounding box, not a crop: TYDAL never crops resource imagery —
            // consumers (the dashboard card, vault renderers) fit the result
            // into their own box and letterbox/pillarbox it themselves.
            // Fit::Max also skips upscaling sources smaller than 200px.
            ->fit(Fit::Max, 200, 200)
            ->format('webp')
            ->performOnCollections('files');

        $this->addMediaConversion('small')
            ->width(400)
            ->format('webp')
            ->performOnCollections('files');

        $this->addMediaConversion('medium')
            ->width(800)
            ->format('webp')
            ->performOnCollections('files');

        $this->addMediaConversion('large')
            ->width(1600)
            ->format('webp')
            ->performOnCollections('files');
    }
}
