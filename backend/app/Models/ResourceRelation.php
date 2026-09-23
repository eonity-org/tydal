<?php

namespace App\Models;

use App\Enums\ResourceRelationOrigin;
use App\Enums\ResourceRelationType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One edge of the resource graph (Epic 4.3). `related` edges are symmetric
 * and stored once (subject/object normalized by UUID order — see
 * ResourceGraphService::relate); `derived_from` is directed: subject was
 * derived from object.
 *
 * @property int $id
 * @property string $organization_id
 * @property string $subject_resource_id
 * @property string $object_resource_id
 * @property ResourceRelationType $type
 * @property ResourceRelationOrigin $origin
 * @property float|null $weight
 * @property string|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read \App\Models\Resource $subject
 * @property-read \App\Models\Resource $object
 * @property-read Organization $organization
 */
class ResourceRelation extends Model
{
    protected $fillable = [
        'organization_id',
        'subject_resource_id',
        'object_resource_id',
        'type',
        'origin',
        'weight',
        'created_by',
    ];

    protected $casts = [
        'type' => ResourceRelationType::class,
        'origin' => ResourceRelationOrigin::class,
        'weight' => 'float',
    ];

    /** @return BelongsTo<\App\Models\Resource, $this> */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(Resource::class, 'subject_resource_id');
    }

    /** @return BelongsTo<\App\Models\Resource, $this> */
    public function object(): BelongsTo
    {
        return $this->belongsTo(Resource::class, 'object_resource_id');
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * The other end of the edge, seen from $resourceId.
     */
    public function otherEnd(string $resourceId): string
    {
        return $this->subject_resource_id === $resourceId
            ? $this->object_resource_id
            : $this->subject_resource_id;
    }
}
