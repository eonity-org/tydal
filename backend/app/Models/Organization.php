<?php

namespace App\Models;

use App\Models\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property string $type
 * @property string|null $logo_url
 * @property string|null $website_url
 * @property array<array-key, mixed>|null $settings
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Category> $categories
 * @property-read int|null $categories_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Collection> $collections
 * @property-read int|null $collections_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Resource> $resources
 * @property-read int|null $resources_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, SemanticTag> $semanticTags
 * @property-read int|null $semantic_tags_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, User> $users
 * @property-read int|null $users_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Workspace> $workspaces
 * @property-read int|null $workspaces_count
 *
 * @method static \Database\Factories\OrganizationFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Organization newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Organization newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Organization query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Organization whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Organization whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Organization whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Organization whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Organization whereLogoUrl($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Organization whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Organization whereSettings($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Organization whereSlug($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Organization whereType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Organization whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Organization whereWebsiteUrl($value)
 *
 * @mixin \Eloquent
 */
class Organization extends Model
{
    use HasFactory, HasUuid;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'type',
        'logo_url',
        'website_url',
        'settings',
        'is_active',
        'collection_quota',
    ];

    protected $casts = [
        'settings' => 'array',
        'is_active' => 'boolean',
        'collection_quota' => 'integer',
    ];

    /**
     * How many collections this organization's own people may create.
     *
     * Null on the row means "use the platform default" rather than "no limit",
     * so raising it for one customer is a single column write.
     */
    public function collectionQuota(): int
    {
        return $this->collection_quota ?? (int) config('tydal.collection_quota', 5);
    }

    /** Has the organization used up its allowance? */
    public function hasReachedCollectionQuota(): bool
    {
        return $this->collections()->count() >= $this->collectionQuota();
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'organization_user')
            ->withPivot('role')
            ->withTimestamps();
    }

    public function workspaces(): HasMany
    {
        return $this->hasMany(Workspace::class);
    }

    public function collections(): HasMany
    {
        return $this->hasMany(Collection::class);
    }

    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }

    public function semanticTags(): HasMany
    {
        return $this->hasMany(SemanticTag::class);
    }

    public function resources(): HasManyThrough
    {
        return $this->hasManyThrough(Resource::class, Collection::class);
    }

    /**
     * Whether AiTy RAG is in strict mode for this organization.
     *
     * Strict  (true)  — metadata chunks only include human-confirmed fields.
     * Non-strict (false) — AI suggestions are folded in immediately after
     *                      AutoTagResource runs, before human review.
     *
     * Resolution order: org setting → platform env → hardcoded default (true).
     */
    public function aityRagStrictMode(): bool
    {
        $orgSetting = $this->settings['aity']['rag_strict_mode'] ?? null;

        if ($orgSetting !== null) {
            return (bool) $orgSetting;
        }

        return (bool) config('autotagging.rag_strict_mode', true);
    }

    /**
     * Org-level override of the internal RAG relevance floor
     * (settings.aity.rag_min_score) — the internal twin of
     * Vault::ragMinScore(). Null = instance default
     * (elasticsearch.rag_min_score).
     */
    public function aityRagMinScore(): ?float
    {
        $value = $this->settings['aity']['rag_min_score'] ?? null;

        return $value === null ? null : (float) $value;
    }
}
