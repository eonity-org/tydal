<?php

namespace App\Models;

use App\Enums\FileRelation;
use App\Enums\FileRole;
use App\Enums\SystemFilePurpose;
use App\Models\Traits\HasUuid;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media as SpatieMedia;

/**
 * @property string $id
 * @property string $resource_id
 * @property string|null $user_owner_id
 * @property int|null $media_id
 * @property FileRole $role
 * @property FileRelation|null $relation
 * @property int|null $position Ordering inside the resource manifest
 * @property array<array-key, mixed>|null $usage
 * @property string $filename
 * @property string $mime_type
 * @property int $size
 * @property string $path
 * @property string $disk
 * @property array<array-key, mixed>|null $metadata
 * @property array<array-key, mixed>|null $processing_status
 * @property bool $is_active
 * @property Carbon|null $uncommitted_at
 * @property string|null $uncommitted_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read mixed $conversion_urls
 * @property-read SystemFile|null $latestAiSuggestedDescriptionSystemFile
 * @property-read SystemFile|null $latestAiSuggestedDescriptionSystemFileForDisplay
 * @property-read SystemFile|null $latestAiSuggestedNameSystemFile
 * @property-read SystemFile|null $latestAiSuggestedNameSystemFileForDisplay
 * @property-read SystemFile|null $latestAiSuggestedMetadataSystemFileForDisplay
 * @property-read SystemFile|null $latestAiSuggestedTagsSystemFile
 * @property-read SystemFile|null $latestAiSuggestedTagsSystemFileForDisplay
 * @property-read SystemFile|null $latestTikaSystemFile
 * @property-read SpatieMedia|null $media
 * @property-read User|null $owner
 * @property-read \App\Models\Resource|null $resource
 * @property-read mixed $url
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|File committed()
 * @method static \Database\Factories\FileFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|File newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|File newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|File query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|File uncommitted()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|File whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|File whereDisk($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|File whereFilename($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|File whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|File whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|File whereMediaId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|File whereMetadata($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|File whereMimeType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|File wherePath($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|File whereProcessingStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|File whereRelation($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|File whereResourceId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|File whereRole($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|File whereSize($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|File whereUncommittedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|File whereUncommittedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|File whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|File whereUsage($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|File whereUserOwnerId($value)
 *
 * @mixin \Eloquent
 */
class File extends Model
{
    use HasFactory, HasUuid;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'resource_id',
        'user_owner_id',
        'media_id',
        'role',
        'relation',
        'position',
        'usage',
        'filename',
        'mime_type',
        'size',
        'disk',
        'path',
        'metadata',
        'processing_status',
        'is_active',
        'uncommitted_at',
        'uncommitted_by',
    ];

    protected $appends = ['url', 'conversion_urls'];

    protected function url(): Attribute
    {
        return Attribute::make(
            get: fn () => Storage::disk($this->disk)->url($this->path),
        );
    }

    protected function conversionUrls(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->getConversionUrls(),
        );
    }

    protected $casts = [
        'role' => FileRole::class,
        'relation' => FileRelation::class,
        'position' => 'integer',
        'usage' => 'array',
        'metadata' => 'array',
        'processing_status' => 'array',
        'is_active' => 'boolean',
        'uncommitted_at' => 'datetime',
    ];

    /**
     * Files committed to the resource (visible everywhere).
     */
    public function scopeCommitted($query)
    {
        return $query->whereNull('uncommitted_at');
    }

    /**
     * Files still in an uncommitted edit-modal session.
     */
    public function scopeUncommitted($query)
    {
        return $query->whereNotNull('uncommitted_at');
    }

    /**
     * Whether this file is in an uncommitted edit-modal session.
     */
    public function isUncommitted(): bool
    {
        return $this->uncommitted_at !== null;
    }

    /**
     * Merge a stage update into processing_status without overwriting unrelated fields.
     */
    public function updateProcessingStage(string $stage, array $extra = []): void
    {
        $this->update([
            'processing_status' => array_merge(
                $this->processing_status ?? [],
                array_merge(['stage' => $stage, 'updated_at' => now()->toIso8601String()], $extra)
            ),
        ]);
    }

    /**
     * Get the resource this file belongs to.
     */
    public function resource(): BelongsTo
    {
        return $this->belongsTo(Resource::class);
    }

    /**
     * Get the owner of this file.
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_owner_id');
    }

    /**
     * Get the associated media record (for image conversions).
     * Only present for media files (images, videos, audio).
     */
    public function media(): BelongsTo
    {
        return $this->belongsTo(SpatieMedia::class, 'media_id');
    }

    /**
     * Get the latest active SystemFile containing tika metadata for this file.
     * Covers both image/audio/video files (purpose=TIKA_METADATA) and documents
     * (purpose=EXTRACTED_TEXT, which also carries tika_metadata in its JSON).
     */
    public function latestTikaSystemFile(): HasOne
    {
        return $this->hasOne(SystemFile::class, 'source_file_id')
            ->ofMany(
                ['id' => 'max'],
                fn ($q) => $q->whereIn('purpose', [
                    SystemFilePurpose::TIKA_METADATA->value,
                    SystemFilePurpose::EXTRACTED_TEXT->value,
                ])->where('is_active', true)
            );
    }

    // ─── Pending suggestions (active and not yet applied) ────────────────────
    // Drive the "found suggestions" panel on the resource basic-info modal —
    // once applied (manually or via auto-approve), they stop appearing here.

    public function latestAiSuggestedTagsSystemFile(): HasOne
    {
        return $this->hasOne(SystemFile::class, 'source_file_id')
            ->ofMany(
                ['id' => 'max'],
                fn ($q) => $q->where('purpose', SystemFilePurpose::AI_SUGGESTED_TAGS->value)
                    ->where('is_active', true)
                    ->whereNull('applied_at')
            );
    }

    public function latestAiSuggestedNameSystemFile(): HasOne
    {
        return $this->hasOne(SystemFile::class, 'source_file_id')
            ->ofMany(
                ['id' => 'max'],
                fn ($q) => $q->where('purpose', SystemFilePurpose::AI_SUGGESTED_NAME->value)
                    ->where('is_active', true)
                    ->whereNull('applied_at')
            );
    }

    public function latestAiSuggestedDescriptionSystemFile(): HasOne
    {
        return $this->hasOne(SystemFile::class, 'source_file_id')
            ->ofMany(
                ['id' => 'max'],
                fn ($q) => $q->where('purpose', SystemFilePurpose::AI_SUGGESTED_DESCRIPTION->value)
                    ->where('is_active', true)
                    ->whereNull('applied_at')
            );
    }

    // ─── Display suggestions (latest active, regardless of applied state) ────
    // Power the per-file AITY card on the Files tab, which should always show
    // the analysis results even after they've been applied to the resource.

    public function latestAiSuggestedTagsSystemFileForDisplay(): HasOne
    {
        return $this->hasOne(SystemFile::class, 'source_file_id')
            ->ofMany(
                ['id' => 'max'],
                fn ($q) => $q->where('purpose', SystemFilePurpose::AI_SUGGESTED_TAGS->value)
                    ->where('is_active', true)
            );
    }

    public function latestAiSuggestedNameSystemFileForDisplay(): HasOne
    {
        return $this->hasOne(SystemFile::class, 'source_file_id')
            ->ofMany(
                ['id' => 'max'],
                fn ($q) => $q->where('purpose', SystemFilePurpose::AI_SUGGESTED_NAME->value)
                    ->where('is_active', true)
            );
    }

    public function latestAiSuggestedDescriptionSystemFileForDisplay(): HasOne
    {
        return $this->hasOne(SystemFile::class, 'source_file_id')
            ->ofMany(
                ['id' => 'max'],
                fn ($q) => $q->where('purpose', SystemFilePurpose::AI_SUGGESTED_DESCRIPTION->value)
                    ->where('is_active', true)
            );
    }

    public function latestAiSuggestedMetadataSystemFileForDisplay(): HasOne
    {
        return $this->hasOne(SystemFile::class, 'source_file_id')
            ->ofMany(
                ['id' => 'max'],
                fn ($q) => $q->where('purpose', SystemFilePurpose::AI_SUGGESTED_METADATA->value)
                    ->where('is_active', true)
            );
    }

    /**
     * Check if this file is a media file that needs conversions.
     */
    public function isMediaFile(): bool
    {
        return $this->media_id !== null;
    }

    /**
     * Check if this file is an image.
     */
    public function isImage(): bool
    {
        return str_starts_with($this->mime_type, 'image/');
    }

    /**
     * Check if this file is a video.
     */
    public function isVideo(): bool
    {
        return str_starts_with($this->mime_type, 'video/');
    }

    /**
     * Check if this file is audio.
     */
    public function isAudio(): bool
    {
        return str_starts_with($this->mime_type, 'audio/');
    }

    /**
     * Check if this file is a document.
     */
    public function isDocument(): bool
    {
        return str_starts_with($this->mime_type, 'application/') ||
               str_starts_with($this->mime_type, 'text/');
    }

    /**
     * Check if this file is the canonical file for its resource.
     */
    public function isCanonical(): bool
    {
        return $this->role === FileRole::CANONICAL;
    }

    /**
     * Check if this file has a given usage tag (e.g. 'snapshot').
     */
    public function hasUsage(string $tag): bool
    {
        return in_array($tag, $this->usage ?? [], true);
    }

    /**
     * Check if this file is the default snapshot for its resource.
     */
    public function isSnapshot(): bool
    {
        return $this->hasUsage('snapshot');
    }

    /**
     * Check if this file needs media processing (conversions/thumbnails).
     */
    public function needsMediaProcessing(): bool
    {
        return $this->isImage() || $this->isVideo() || $this->isAudio();
    }

    /**
     * Get the URL for a specific conversion (if available).
     */
    public function getConversionUrl(string $conversion = 'thumbnail'): ?string
    {
        if (! $this->media) {
            return null;
        }

        return $this->media->getUrl($conversion);
    }

    /**
     * Get all available conversion URLs.
     *
     * @return array<string, string|null>
     */
    public function getConversionUrls(): array
    {
        if (! $this->media) {
            return [
                'original' => $this->url,
                'thumbnail' => null,
                'small' => null,
                'medium' => null,
                'large' => null,
            ];
        }

        return [
            'original' => $this->media->getUrl(),
            'thumbnail' => $this->media->hasGeneratedConversion('thumbnail') ? $this->media->getUrl('thumbnail') : null,
            'small' => $this->media->hasGeneratedConversion('small') ? $this->media->getUrl('small') : null,
            'medium' => $this->media->hasGeneratedConversion('medium') ? $this->media->getUrl('medium') : null,
            'large' => $this->media->hasGeneratedConversion('large') ? $this->media->getUrl('large') : null,
        ];
    }

    /**
     * Get the storage path for a non-media file (archives, courses, etc.).
     * Files are organized by resource ID: {resource_id}/archives/{filename}
     */
    public static function getStoragePath(string $resourceId, string $filename): string
    {
        // Sanitize filename to prevent directory traversal
        $safeFilename = basename($filename);

        return "{$resourceId}/archives/{$safeFilename}";
    }

    /**
     * Generate a temporary signed URL for this file.
     * Falls back to a plain URL for local/fake disks (e.g. in tests).
     */
    public function getTemporaryUrl(int $minutes = 5, array $options = []): string
    {
        try {
            return Storage::disk($this->disk)->temporaryUrl($this->path, now()->addMinutes($minutes), $options);
        } catch (\RuntimeException $e) {
            return Storage::disk($this->disk)->url($this->path);
        }
    }
}
