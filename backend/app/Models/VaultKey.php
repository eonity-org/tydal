<?php

namespace App\Models;

use App\Models\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Access key for a private (unpublished) vault — VAULT_SYSTEM.md §7.
 * Plaintext form: tvk_<40 random chars>, shown exactly once at creation;
 * only the sha256 hash is persisted.
 *
 * @property string $id
 * @property string $vault_id
 * @property string $name
 * @property string $key_hash
 * @property string $key_prefix
 * @property list<string> $abilities
 * @property Carbon|null $last_used_at
 * @property Carbon|null $revoked_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Vault $vault
 *
 * @mixin \Eloquent
 */
class VaultKey extends Model
{
    use HasUuid;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'vault_id',
        'name',
        'key_hash',
        'key_prefix',
        'abilities',
        'last_used_at',
        'revoked_at',
    ];

    protected $casts = [
        'abilities' => 'array',
        'last_used_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    /** @return BelongsTo<Vault, $this> */
    public function vault(): BelongsTo
    {
        return $this->belongsTo(Vault::class);
    }

    /**
     * Mint a new key for a vault. Returns [model, plaintext] — the plaintext
     * is not recoverable afterwards. A read key (`['read']`, the default) gates
     * the consumer read surface; a write key lists purpose methods it may
     * invoke (e.g. `['w:activate', 'w:open', 'w:close']`), see
     * VAULT_WRITE_METHODS.md §4.
     *
     * @param  list<string>  $abilities
     * @return array{0: self, 1: string}
     */
    public static function mint(Vault $vault, string $name, array $abilities = ['read']): array
    {
        $plaintext = 'tvk_'.Str::random(40);

        $key = self::create([
            'vault_id' => $vault->id,
            'name' => $name,
            'key_hash' => hash('sha256', $plaintext),
            'key_prefix' => substr($plaintext, 0, 12),
            'abilities' => $abilities,
        ]);

        return [$key, $plaintext];
    }

    /**
     * Look up a presented plaintext against this vault's active (non-revoked)
     * keys and touch its last_used_at. Returns the key model or null — the
     * ability check is layered on top by the callers below. Match is by
     * sha256 equality (constant-time within the DB engine).
     */
    private static function matchActive(Vault $vault, string $plaintext): ?self
    {
        $key = self::where('vault_id', $vault->id)
            ->where('key_hash', hash('sha256', $plaintext))
            ->whereNull('revoked_at')
            ->first();

        if (! $key) {
            return null;
        }

        $key->forceFill(['last_used_at' => now()])->saveQuietly();

        return $key;
    }

    /**
     * Read gate (VAULT_SYSTEM.md §7): a valid, non-revoked key that carries the
     * `read` ability. Every key minted before write methods existed was
     * backfilled to `['read']`, so legacy read keys keep working.
     */
    public static function verify(Vault $vault, string $plaintext): bool
    {
        return self::verifyWithAbility($vault, $plaintext, 'read');
    }

    /**
     * Does a valid, non-revoked key for this vault carry the given ability?
     * Abilities are full tokens: `read`, or `w:{method}` for a write method.
     */
    public static function verifyWithAbility(Vault $vault, string $plaintext, string $ability): bool
    {
        $key = self::matchActive($vault, $plaintext);

        return $key !== null && in_array($ability, $key->abilities ?? [], true);
    }

    /**
     * Resolve the key authorized to invoke a write method, or null. Returns the
     * model (not a bool) so the caller can attribute the audit row to the key
     * that authorized it (VAULT_WRITE_METHODS.md §5).
     */
    public static function resolveForWrite(Vault $vault, string $plaintext, string $method): ?self
    {
        $key = self::matchActive($vault, $plaintext);

        if ($key === null || ! in_array("w:$method", $key->abilities ?? [], true)) {
            return null;
        }

        return $key;
    }

    /**
     * The write methods a presented key is authorized for on this vault — the
     * intersection of its `w:` abilities with the methods the vault's purpose
     * actually exposes. Empty when the key is invalid, revoked, or read-only.
     * Powers the non-destructive write-auth probe (VAULT_WRITE_METHODS.md §5):
     * a config UI can confirm a write key is usable without performing a write.
     *
     * @return list<string>
     */
    public static function authorizedWriteMethods(Vault $vault, string $plaintext): array
    {
        $key = self::matchActive($vault, $plaintext);

        if ($key === null) {
            return [];
        }

        $granted = [];
        foreach ($key->abilities ?? [] as $ability) {
            if (str_starts_with($ability, 'w:')) {
                $method = substr($ability, 2);
                if ($vault->allowsWriteMethod($method)) {
                    $granted[] = $method;
                }
            }
        }

        return $granted;
    }
}
