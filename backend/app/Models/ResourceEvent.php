<?php

namespace App\Models;

use App\Models\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Append-only audit log row for a single thing that happened to a Resource.
 *
 * Writes go through App\Services\ResourceEventLogger — do NOT call ::create()
 * directly so all events flow through one path that normalises payload shape
 * and resolves the current actor.
 *
 * See database/migrations/…create_resource_events_table.php for the schema
 * contract and conventions for actor_type / target_type.
 *
 * @property string $id
 * @property string $resource_id
 * @property string $event_type
 * @property string $actor_type
 * @property string|null $actor_id
 * @property string|null $target_type
 * @property string|null $target_id
 * @property array<array-key, mixed>|null $payload
 * @property Carbon $created_at
 * @property-read \App\Models\Resource|null $resource
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ResourceEvent newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ResourceEvent newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ResourceEvent query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ResourceEvent whereActorId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ResourceEvent whereActorType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ResourceEvent whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ResourceEvent whereEventType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ResourceEvent whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ResourceEvent wherePayload($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ResourceEvent whereResourceId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ResourceEvent whereTargetId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ResourceEvent whereTargetType($value)
 *
 * @mixin \Eloquent
 */
class ResourceEvent extends Model
{
    use HasUuid;

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false; // created_at only — set by the DB default / logger

    protected $fillable = [
        'id',
        'resource_id',
        'event_type',
        'actor_type',
        'actor_id',
        'target_type',
        'target_id',
        'payload',
        'created_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'created_at' => 'datetime',
    ];

    public function resource(): BelongsTo
    {
        return $this->belongsTo(Resource::class);
    }
}
