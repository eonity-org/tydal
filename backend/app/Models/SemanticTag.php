<?php

namespace App\Models;

use App\Enums\TagReviewer;
use App\Enums\TagVocabulary;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $organization_id
 * @property string $label
 * @property string $slug
 * @property string|null $description
 * @property string|null $entity_type
 * @property bool $is_active
 * @property TagVocabulary $vocabulary
 * @property TagReviewer $reviewer
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Organization $organization
 * @property-read Collection<int, \App\Models\Resource> $resources
 * @property-read int|null $resources_count
 *
 * @method static \Database\Factories\SemanticTagFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SemanticTag newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SemanticTag newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SemanticTag query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SemanticTag whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SemanticTag whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SemanticTag whereEntityType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SemanticTag whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SemanticTag whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SemanticTag whereLabel($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SemanticTag whereOrganizationId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SemanticTag whereReviewer($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SemanticTag whereSlug($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SemanticTag whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SemanticTag whereVocabulary($value)
 *
 * @mixin \Eloquent
 */
class SemanticTag extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'label',
        'slug',
        'description',
        'entity_type',
        'is_active',
        'vocabulary',
        'reviewer',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'vocabulary' => TagVocabulary::class,
        'reviewer' => TagReviewer::class,
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function resources(): BelongsToMany
    {
        return $this->belongsToMany(Resource::class, 'semantic_tag_resource');
    }
}
