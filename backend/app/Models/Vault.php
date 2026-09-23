<?php

namespace App\Models;

use App\Enums\VaultCapability;
use App\Enums\VaultCredential;
use App\Enums\VaultPurpose;
use App\Enums\VaultState;
use App\Models\Traits\HasUuid;
use App\Services\ElasticsearchService;
use App\Values\VaultPolicy;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * @property string $id
 * @property string $organization_id
 * @property string $name
 * @property string $slug
 * @property string $hash
 * @property string|null $description
 * @property VaultPurpose $purpose
 * @property VaultState $state
 * @property string $salt
 * @property int $grant_epoch
 * @property bool $has_public_workspace
 * @property array<array-key, mixed>|null $selection_snapshot
 * @property bool $is_downloadable
 * @property int|null $hash_ttl_hours
 * @property array<array-key, mixed>|null $allowed_ips
 * @property array<array-key, mixed>|null $exposure_policy
 * @property Carbon|null $indexed_at Per-vault ES index freshness (NULL = stale/absent)
 * @property string|null $base_url
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Organization $organization
 * @property-read Collection<int, VaultLink> $links
 * @property-read int|null $links_count
 * @property-read Collection<int, Workspace> $workspaces
 * @property-read int|null $workspaces_count
 *
 * @method static \Database\Factories\VaultFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Vault newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Vault newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Vault query()
 *
 * @mixin \Eloquent
 */
class Vault extends Model
{
    use HasFactory, HasUuid;

    /** Length of the short opaque vault hash used in machine URLs. */
    public const HASH_LENGTH = 12;

    public const SALT_LENGTH = 32;

    /** Seconds a vault-by-hash resolution stays cached (spec §4.1). */
    public const HASH_CACHE_TTL = 300;

    /**
     * How the current request authenticated — transient, per resolution.
     * Deliberately not an attribute: it must never persist or serialize.
     */
    protected ?VaultCredential $credential = null;

    protected $keyType = 'string';

    public $incrementing = false;

    /** Mirror the DB defaults so a freshly created model reports them. */
    protected $attributes = [
        'purpose' => 'delivery',
        'state' => 'private',
    ];

    /**
     * The salt is the boundary's signing secret (link hashes + grant HMACs) —
     * it must never serialize into any API response.
     */
    protected $hidden = [
        'salt',
    ];

    protected $fillable = [
        'organization_id',
        'name',
        'slug',
        'description',
        'purpose',
        'generated_from',
        'state',
        'salt',
        'grant_epoch',
        'has_public_workspace',
        'selection_snapshot',
        'is_downloadable',
        'hash_ttl_hours',
        'allowed_ips',
        'exposure_policy',
        'base_url',
    ];

    protected $casts = [
        'purpose' => VaultPurpose::class,
        'state' => VaultState::class,
        'has_public_workspace' => 'boolean',
        'selection_snapshot' => 'array',
        'is_downloadable' => 'boolean',
        'hash_ttl_hours' => 'integer',
        'grant_epoch' => 'integer',
        'allowed_ips' => 'array',
        'exposure_policy' => 'array',
        'indexed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (Vault $vault) {
            if (empty($vault->hash)) {
                $vault->hash = self::generateUniqueHash();
            }
            // The salt is a cryptographic secret (seeds link hashes + grant
            // signatures) — generated, never human-picked. Rotated later via
            // the rotate-salt action, not by typing a new value.
            if (empty($vault->salt)) {
                $vault->salt = Str::random(self::SALT_LENGTH);
            }
        });

        // Policy fields (state, allowed_ips, …) feed the
        // cached hash/slug resolutions — any change must drop the cache entries.
        static::saved(function (Vault $vault) {
            Cache::forget("vault:hash:{$vault->hash}");
            Cache::forget("vault:slug:{$vault->organization_id}:{$vault->slug}");
            if ($vault->wasChanged('slug')) {
                Cache::forget("vault:slug:{$vault->organization_id}:{$vault->getOriginal('slug')}");
            }
        });
        static::deleted(function (Vault $vault) {
            Cache::forget("vault:hash:{$vault->hash}");
            Cache::forget("vault:slug:{$vault->organization_id}:{$vault->slug}");

            // Drop the projected ES index with its vault (best effort)
            try {
                app(ElasticsearchService::class)->deleteIndex('vault_'.strtolower($vault->id));
            } catch (\Throwable) {
                // index cleanup is recoverable via search:reindex --vault
            }
        });
    }

    /**
     * Resolve a vault by (org slug, vault slug) through the cache — the
     * entry point of the human namespace /v/{orgSlug}/{vaultSlug} (spec §4).
     */
    public static function findBySlugsCached(string $orgSlug, string $vaultSlug): ?self
    {
        $orgId = Cache::remember(
            "org:slug:{$orgSlug}",
            self::HASH_CACHE_TTL,
            fn () => Organization::where('slug', $orgSlug)->value('id')
        );

        if (! $orgId) {
            return null;
        }

        $key = "vault:slug:{$orgId}:{$vaultSlug}";

        $cached = Cache::get($key);
        if ($cached instanceof self) {
            return $cached;
        }

        $vault = self::where('organization_id', $orgId)->where('slug', $vaultSlug)->first();
        if ($vault) {
            Cache::put($key, $vault, self::HASH_CACHE_TTL);
        }

        return $vault;
    }

    /**
     * Resolve a vault by its opaque hash through the cache (DB fallback).
     * First step of /h/{vaultHash}/{linkHash} resolution — policy is known
     * before any link query (spec §4.1).
     */
    public static function findByHashCached(string $hash): ?self
    {
        $key = "vault:hash:{$hash}";

        $cached = Cache::get($key);
        if ($cached instanceof self) {
            Cache::increment('metrics:vault_hash_cache:hits');

            return $cached;
        }

        Cache::increment('metrics:vault_hash_cache:misses');

        $vault = self::where('hash', $hash)->first();
        if ($vault) {
            Cache::put($key, $vault, self::HASH_CACHE_TTL);
        }

        return $vault;
    }

    public function isDelivery(): bool
    {
        return $this->purpose === VaultPurpose::DELIVERY;
    }

    /** Does the boundary answer at all — with or without a credential? */
    public function isReachable(): bool
    {
        return $this->state->isReachable();
    }

    /** Does it answer without any credential? */
    public function isOpen(): bool
    {
        return $this->state->isOpen();
    }

    /**
     * File roles this vault mints addresses for (exposure policy, spec §6.1).
     * Default: canonical + component; supporting stays unaddressed.
     *
     * @return list<string>
     */
    public function addressRoles(): array
    {
        return $this->policy()->value(VaultCapability::ADDRESS_ROLES);
    }

    /**
     * File roles whose text feeds Tier 1 chunks (spec §6.1). In `ai` vaults
     * supporting text (lyrics, transcripts) contributes by default —
     * projection-only, resource indexing/embedding is untouched.
     *
     * @return list<string>
     */
    public function chunkRoles(): array
    {
        return $this->policy()->value(VaultCapability::CHUNK_ROLES);
    }

    /**
     * Tier 1 — may consumers read chunk content? Purpose preset (spec §6.3),
     * overridable via exposure_policy.allow_chunks.
     */
    public function allowsChunks(): bool
    {
        return $this->answers(VaultCapability::ALLOW_CHUNKS);
    }

    /**
     * Tier 2 — may consumers reach binaries (/links, /download, direct
     * serving)? `ai` vaults are metadata/chunk-first: binary off by default.
     * Overridable via exposure_policy.allow_binary.
     */
    public function allowsBinary(): bool
    {
        return $this->answers(VaultCapability::ALLOW_BINARY);
    }

    /**
     * May consumers use the vault's ask head at the boundary (`/ask`)? Reasoning is
     * compute, not data: only AI-facing purposes answer by default, so a
     * published gallery can't be farmed for LLM tokens. Overridable via
     * exposure_policy.allow_ask.
     */
    public function allowsAsk(): bool
    {
        return $this->answers(VaultCapability::ALLOW_ASK);
    }

    /**
     * Does a gated capability answer for the credential this vault was resolved
     * with? `denied` never answers, `inherit` always does (reachability was
     * already settled by `state`), and `key` answers only for a real vault key
     * — which is how one vault can list publicly while keeping its binaries
     * behind a credential.
     */
    private function answers(VaultCapability $capability): bool
    {
        return $this->policy()->levelOf($capability)->answersFor($this->credential());
    }

    /**
     * How the current request got through the boundary. Set by
     * VaultLinkService at resolution and never persisted; absent outside the
     * boundary (admin API, jobs), where OPEN is right because those callers
     * are already authorized by org membership.
     */
    public function credential(): VaultCredential
    {
        return $this->credential ?? VaultCredential::OPEN;
    }

    /** Record how this resolution authenticated (boundary use only). */
    public function withCredential(VaultCredential $credential): static
    {
        $this->credential = $credential;

        return $this;
    }

    /**
     * May this vault accept the given write method at the boundary? The set is
     * purpose-derived (VaultPurpose::writeMethods) and can be widened via
     * exposure_policy.write_methods, symmetric with the read-tier overrides.
     */
    public function allowsWriteMethod(string $method): bool
    {
        return in_array($method, $this->policy()->value(VaultCapability::WRITE_METHODS), true);
    }

    /**
     * The purpose preset overlaid with this vault's own overrides. Every
     * accessor above reads through it, so the preset matrix has exactly one
     * definition (VaultPolicy::presetFor) that the admin UI can also render.
     */
    public function policy(): VaultPolicy
    {
        return VaultPolicy::for($this->purpose, $this->exposure_policy);
    }

    /**
     * Relevance floor for this vault's ask head — retrieved chunks scoring
     * below it are excluded from the LLM context. The right value is corpus-
     * and embedder-dependent (dense prose tolerates a higher floor than
     * sparse image metadata), so the vault owner may override the instance
     * default via exposure_policy.rag_min_score. Null = use config
     * (elasticsearch.rag_min_score). Raw retrieval surfaces (/search) are
     * never affected — consumers there apply their own cutoff.
     */
    public function ragMinScore(): ?float
    {
        $value = $this->policy()->value(VaultCapability::RAG_MIN_SCORE);

        return $value === null ? null : (float) $value;
    }

    /**
     * Generate a globally unique short opaque hash — the machine-facing vault
     * identifier used in /h/{vaultHash}/... URLs (reveals nothing about org
     * or content).
     */
    public static function generateUniqueHash(): string
    {
        do {
            $hash = Str::random(self::HASH_LENGTH);
        } while (self::where('hash', $hash)->exists());

        return $hash;
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function workspaces(): BelongsToMany
    {
        return $this->belongsToMany(Workspace::class, 'workspace_vault')->withTimestamps();
    }

    public function links(): HasMany
    {
        return $this->hasMany(VaultLink::class);
    }
}
