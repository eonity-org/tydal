<?php

namespace App\Models;

use App\Enums\SystemFilePurpose;
use App\Models\Traits\HasUuid;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $resource_id
 * @property string|null $source_file_id
 * @property SystemFilePurpose $purpose
 * @property string $filename
 * @property string $mime_type
 * @property int $size
 * @property string $path
 * @property string $disk
 * @property array<array-key, mixed>|null $metadata
 * @property string|null $language
 * @property bool $is_active
 * @property Carbon|null $applied_at
 * @property bool|null $applied_by_aity
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, FileChunk> $chunks
 * @property-read int|null $chunks_count
 * @property-read \App\Models\Resource|null $resource
 * @property-read File|null $sourceFile
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SystemFile newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SystemFile newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SystemFile query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SystemFile whereAppliedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SystemFile whereAppliedByAity($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SystemFile whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SystemFile whereDisk($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SystemFile whereFilename($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SystemFile whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SystemFile whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SystemFile whereLanguage($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SystemFile whereMetadata($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SystemFile whereMimeType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SystemFile wherePath($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SystemFile wherePurpose($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SystemFile whereResourceId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SystemFile whereSize($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SystemFile whereSourceFileId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SystemFile whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
class SystemFile extends Model
{
    use HasUuid;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'resource_id',
        'source_file_id',
        'purpose',
        'filename',
        'mime_type',
        'size',
        'path',
        'disk',
        'metadata',
        'language',
        'is_active',
        'applied_at',
        'applied_by_aity',
    ];

    protected $casts = [
        'purpose' => SystemFilePurpose::class,
        'metadata' => 'array',
        'is_active' => 'boolean',
        'applied_at' => 'datetime',
        'applied_by_aity' => 'boolean',
    ];

    public function resource(): BelongsTo
    {
        return $this->belongsTo(Resource::class);
    }

    public function sourceFile(): BelongsTo
    {
        return $this->belongsTo(File::class, 'source_file_id');
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(FileChunk::class, 'archive_file_id');
    }
}
