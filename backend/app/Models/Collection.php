<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $organization_id
 * @property string $user_owner_id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property string $language
 * @property string|null $scheme_id
 * @property string|null $index_id
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Category> $categories
 * @property-read int|null $categories_count
 * @property-read Organization $organization
 * @property-read User|null $owner
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Resource> $resources
 * @property-read int|null $resources_count
 * @property-read CollectionScheme|null $scheme
 * @property-read SearchIndex|null $searchIndex
 *
 * @method static \Database\Factories\CollectionFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Collection newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Collection newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Collection query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Collection whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Collection whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Collection whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Collection whereIndexId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Collection whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Collection whereLanguage($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Collection whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Collection whereOrganizationId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Collection whereSchemeId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Collection whereSlug($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Collection whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Collection whereUserOwnerId($value)
 *
 * @mixin \Eloquent
 */
class Collection extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'user_owner_id',
        'scheme_id',
        'index_id',
        'name',
        'slug',
        'description',
        'language',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_owner_id');
    }

    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }

    public function resources(): HasMany
    {
        return $this->hasMany(Resource::class);
    }

    public function scheme(): BelongsTo
    {
        return $this->belongsTo(CollectionScheme::class, 'scheme_id');
    }

    public function searchIndex(): BelongsTo
    {
        return $this->belongsTo(SearchIndex::class, 'index_id');
    }

    // -------------------------------------------------------------------------
    // Schema helpers
    // -------------------------------------------------------------------------

    /**
     * Return the scheme's fields array, or null if no scheme is assigned.
     * Used by CollectionSchemaService::validateResourceData() and ResourceController.
     *
     * @return array<int, array>|null
     */
    public function getEffectiveSchema(): ?array
    {
        return $this->scheme?->fields;
    }

    /**
     * Get names of required fields for this collection.
     *
     * @return string[]
     */
    public function getRequiredFields(): array
    {
        $fields = $this->getEffectiveSchema() ?? [];

        return array_column(
            array_filter($fields, fn ($f) => $f['required'] ?? false),
            'name'
        );
    }

    /**
     * Get names of optional fields for this collection.
     *
     * @return string[]
     */
    public function getOptionalFields(): array
    {
        $fields = $this->getEffectiveSchema() ?? [];

        return array_column(
            array_filter($fields, fn ($f) => ! ($f['required'] ?? false)),
            'name'
        );
    }

    /**
     * Check if a field is required for this collection.
     */
    public function isFieldRequired(string $field): bool
    {
        foreach ($this->getEffectiveSchema() ?? [] as $fieldDef) {
            if ($fieldDef['name'] === $field) {
                return $fieldDef['required'] ?? false;
            }
        }

        return false;
    }
}
