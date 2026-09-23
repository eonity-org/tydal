<?php

namespace App\Models;

use App\Models\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $resource_id
 * @property string|null $source_file_id
 * @property string|null $archive_file_id
 * @property int $sequence
 * @property int|null $page_number
 * @property int $word_count
 * @property int $char_start
 * @property int $char_end
 * @property Carbon $created_at
 * @property-read SystemFile|null $archiveFile
 * @property-read \App\Models\Resource|null $resource
 * @property-read File|null $sourceFile
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FileChunk newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FileChunk newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FileChunk query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FileChunk whereArchiveFileId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FileChunk whereCharEnd($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FileChunk whereCharStart($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FileChunk whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FileChunk whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FileChunk wherePageNumber($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FileChunk whereResourceId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FileChunk whereSequence($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FileChunk whereSourceFileId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FileChunk whereWordCount($value)
 *
 * @mixin \Eloquent
 */
class FileChunk extends Model
{
    use HasUuid;

    public $timestamps = false;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'resource_id',
        'source_file_id',
        'archive_file_id',
        'sequence',
        'page_number',
        'word_count',
        'char_start',
        'char_end',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function resource(): BelongsTo
    {
        return $this->belongsTo(Resource::class);
    }

    public function sourceFile(): BelongsTo
    {
        return $this->belongsTo(File::class, 'source_file_id');
    }

    public function archiveFile(): BelongsTo
    {
        return $this->belongsTo(SystemFile::class, 'archive_file_id');
    }
}
