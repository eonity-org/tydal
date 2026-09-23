<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $vault_id
 * @property int|null $workspace_id NULL = vault link (workspace-free key)
 * @property string $resource_id
 * @property string|null $file_id
 * @property string $link_key
 * @property string|null $hash
 * @property string|null $slug Human address form, unique per vault
 * @property Carbon|null $expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Vault $vault
 * @property-read File|null $file
 * @property-read \App\Models\Resource|null $resource
 * @property-read Workspace|null $workspace
 *
 * @method static \Database\Factories\VaultLinkFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VaultLink newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VaultLink newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VaultLink query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VaultLink whereVaultId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VaultLink whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VaultLink whereExpiresAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VaultLink whereFileId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VaultLink whereHash($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VaultLink whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VaultLink whereLinkKey($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VaultLink whereResourceId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VaultLink whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VaultLink whereWorkspaceId($value)
 *
 * @mixin \Eloquent
 */
class VaultLink extends Model
{
    use HasFactory;

    protected $fillable = [
        'vault_id',
        'workspace_id',
        'resource_id',
        'file_id',
        'link_key',
        'hash',
        'slug',
        'expires_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
    ];

    /** @return BelongsTo<Vault, $this> */
    public function vault(): BelongsTo
    {
        return $this->belongsTo(Vault::class);
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<\App\Models\Resource, $this> */
    public function resource(): BelongsTo
    {
        return $this->belongsTo(Resource::class);
    }

    /** @return BelongsTo<File, $this> */
    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * Compute the deterministic link_key (VAULT_SYSTEM.md §4.2).
     *
     * `delivery` links carry a workspace: sha256(vault:workspace:resource:file).
     * Vault-purpose links are workspace-free — one stable address per
     * resource no matter how many workspaces project it:
     * sha256(vault:resource:file).
     */
    public static function computeLinkKey(string $vaultId, ?string $workspaceId, string $resourceId, ?string $fileId): string
    {
        $parts = $workspaceId === null
            ? [$vaultId, $resourceId, $fileId ?? 'null']
            : [$vaultId, $workspaceId, $resourceId, $fileId ?? 'null'];

        return hash('sha256', implode(':', $parts));
    }
}
