<?php

namespace App\Models;

use App\Enums\Visibility;
use App\Jobs\RebuildVaultIndex;
use App\Models\Traits\HasOrganizationVisibility;
use App\Models\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Collection Scheme Model
 *
 * Defines the canonical field structure for a collection type.
 * Reusable across multiple collections and organizations.
 * Replaces CollectionSchemaTemplate + the schema-relevant parts of SolrCoreSchema.
 *
 * The `fields` JSON column drives:
 *   - Frontend dynamic resource forms (display_in_form, order, type → widget)
 *   - ES index mapping generation (es_type, es_fields)
 *   - Facet list for /catalogue (is_facet = true)
 *   - Validation (validators, required)
 *
 * @property string $id
 * @property string $name
 * @property string $display_name
 * @property string|null $description
 * @property array<array-key, mixed> $fields
 * @property Visibility $visibility
 * @property array<array-key, mixed>|null $accepted_mimetypes
 * @property array<array-key, mixed>|null $processing_config
 * @property bool $is_system
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Collection> $collections
 * @property-read int|null $collections_count
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CollectionScheme custom()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CollectionScheme newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CollectionScheme newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CollectionScheme query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CollectionScheme system()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CollectionScheme whereAcceptedMimetypes($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CollectionScheme whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CollectionScheme whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CollectionScheme whereDisplayName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CollectionScheme whereFields($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CollectionScheme whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CollectionScheme whereIsSystem($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CollectionScheme whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CollectionScheme whereProcessingConfig($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CollectionScheme whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
class CollectionScheme extends Model
{
    use HasFactory, HasOrganizationVisibility, HasUuid;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'name',
        'display_name',
        'description',
        'fields',
        'accepted_mimetypes',
        'processing_config',
        'is_system',
        'visibility',
    ];

    protected $casts = [
        'fields' => 'array',
        'accepted_mimetypes' => 'array',
        'processing_config' => 'array',
        'is_system' => 'boolean',
        'visibility' => Visibility::class,
    ];

    /** @see HasOrganizationVisibility */
    protected function visibilityPivotTable(): string
    {
        return 'collection_scheme_organization';
    }

    protected static function booted(): void
    {
        // Recomposition trigger (Epic 3.2): a field-contract change alters
        // the semantic mapping of every vault projecting resources of this
        // scheme — their projected ES indexes are stale until rebuilt.
        static::updated(function (CollectionScheme $scheme) {
            if (! $scheme->wasChanged('fields')) {
                return;
            }

            foreach ($scheme->affectedVaultIds() as $vaultId) {
                Vault::whereKey($vaultId)->update(['indexed_at' => null]);
                RebuildVaultIndex::dispatch($vaultId);
            }
        });
    }

    /**
     * Whether an uploaded MIME type is accepted by this scheme. An empty list
     * accepts anything; wildcards work ("image/*" matches "image/jpeg").
     */
    public function acceptsMime(string $mime): bool
    {
        $accepted = $this->accepted_mimetypes ?? [];

        if ($accepted === []) {
            return true;
        }

        foreach ($accepted as $pattern) {
            if ($pattern === '*' || $pattern === '*/*' || $pattern === $mime) {
                return true;
            }
            if (str_ends_with($pattern, '/*') && str_starts_with($mime, substr($pattern, 0, -2).'/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Vaults whose projected index depends on this scheme: those with an
     * explicit overlay for it, plus those projecting resources of any
     * collection using it (via workspaces, or has_public_workspace vaults
     * in the same orgs).
     *
     * @return list<string>
     */
    public function affectedVaultIds(): array
    {
        $overlayVaults = VaultSchemaOverlay::where('scheme_id', $this->id)->pluck('vault_id');

        $collectionIds = DB::table('collections')
            ->where('scheme_id', $this->id)->pluck('id');

        if ($collectionIds->isEmpty()) {
            return $overlayVaults->unique()->values()->all();
        }

        $workspaceIds = DB::table('dam_resource_workspace')
            ->whereIn('resource_id', fn ($q) => $q->select('id')->from('resources')->whereIn('collection_id', $collectionIds))
            ->distinct()
            ->pluck('workspace_id');

        $projectionVaults = DB::table('workspace_vault')
            ->whereIn('workspace_id', $workspaceIds)
            ->pluck('vault_id');

        $orgIds = DB::table('collections')
            ->whereIn('id', $collectionIds)->distinct()->pluck('organization_id');

        $publicVaults = Vault::where('has_public_workspace', true)
            ->whereIn('organization_id', $orgIds)
            ->pluck('id');

        return $overlayVaults->merge($projectionVaults)->merge($publicVaults)
            ->unique()->values()->all();
    }

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    public function collections(): HasMany
    {
        return $this->hasMany(Collection::class, 'scheme_id');
    }

    // -------------------------------------------------------------------------
    // Field helpers
    // -------------------------------------------------------------------------

    /**
     * Return fields as a name-keyed map for O(1) lookup.
     *
     * @return array<string, array>
     */
    public function getFieldMap(): array
    {
        $map = [];
        foreach ($this->fields ?? [] as $field) {
            $map[$field['name']] = $field;
        }

        return $map;
    }

    /**
     * Return only fields where is_facet = true, sorted by facet_order.
     *
     * @return array<int, array>
     */
    public function getFacetFields(): array
    {
        $facets = array_filter($this->fields ?? [], fn ($f) => $f['is_facet'] ?? false);
        usort($facets, fn ($a, $b) => ($a['facet_order'] ?? 99) <=> ($b['facet_order'] ?? 99));

        return array_values($facets);
    }

    /**
     * Return only fields where required = true.
     *
     * @return array<int, array>
     */
    public function getRequiredFields(): array
    {
        return array_values(array_filter($this->fields ?? [], fn ($f) => $f['required'] ?? false));
    }

    /**
     * Find a single field definition by name.
     */
    public function getFieldByName(string $name): ?array
    {
        foreach ($this->fields ?? [] as $field) {
            if ($field['name'] === $name) {
                return $field;
            }
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // Processing config helpers
    // -------------------------------------------------------------------------

    /**
     * Target words per extraction chunk (used by ChunkingService).
     * Falls back to the embedding config default, which must fit the embedding
     * model's context window (EMBEDDING_MAX_CHUNK_WORDS in .env).
     */
    public function getChunkSize(): int
    {
        return (int) ($this->processing_config['chunk_size'] ?? config('embedding.max_chunk_words', 400));
    }

    /**
     * Words shared between consecutive chunks for context continuity.
     */
    public function getChunkOverlap(): int
    {
        return (int) ($this->processing_config['chunk_overlap'] ?? config('embedding.chunk_overlap', 50));
    }

    /**
     * Whether text extraction is enabled for resources in this scheme.
     */
    public function shouldExtract(): bool
    {
        return ! ($this->processing_config['skip_extraction'] ?? false);
    }

    // -------------------------------------------------------------------------
    // Scopes
    // -------------------------------------------------------------------------

    public function scopeSystem($query)
    {
        return $query->where('is_system', true);
    }

    public function scopeCustom($query)
    {
        return $query->where('is_system', false);
    }
}
