<?php

namespace App\Models;

use App\Enums\Visibility;
use App\Models\Traits\HasOrganizationVisibility;
use App\Models\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Search Index Model
 *
 * Represents an Elasticsearch index. Multiple collections (from different orgs)
 * can share one index, or each org can have its own.
 * Replaces SolrCoreSchema (stripped of everything schema-related).
 *
 * The base ES field mappings are generated from the CollectionScheme::fields.
 * index_mappings stores optional ES-specific overrides on top of the scheme-driven base.
 *
 * @property string $id
 * @property string $index_name
 * @property string $display_name
 * @property string|null $description
 * @property array<array-key, mixed>|null $index_mappings
 * @property Visibility $visibility
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Collection> $collections
 * @property-read int|null $collections_count
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SearchIndex active()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SearchIndex newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SearchIndex newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SearchIndex query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SearchIndex whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SearchIndex whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SearchIndex whereDisplayName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SearchIndex whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SearchIndex whereIndexMappings($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SearchIndex whereIndexName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SearchIndex whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SearchIndex whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
class SearchIndex extends Model
{
    use HasFactory, HasOrganizationVisibility, HasUuid;

    protected $table = 'search_indexes';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'index_name',
        'display_name',
        'description',
        'index_mappings',
        'is_active',
        'visibility',
    ];

    protected $casts = [
        'index_mappings' => 'array',
        'is_active' => 'boolean',
        'visibility' => Visibility::class,
    ];

    /** @see HasOrganizationVisibility */
    protected function visibilityPivotTable(): string
    {
        return 'organization_search_index';
    }

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    /** @return HasMany<Collection, $this> */
    public function collections(): HasMany
    {
        return $this->hasMany(Collection::class, 'index_id');
    }

    // -------------------------------------------------------------------------
    // Scopes
    // -------------------------------------------------------------------------

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
