<?php

namespace App\Models;

use App\Models\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Append-only audit of a write accepted at the vault boundary
 * (VAULT_WRITE_METHODS.md §5/§11). One row per authorized call; `summary`
 * holds a small structured record of intent (e.g. the activated hash list),
 * never the resources themselves.
 *
 * @property string $id
 * @property string $vault_id
 * @property string|null $vault_key_id
 * @property string $method
 * @property array<string, mixed>|null $summary
 * @property string|null $client_ip
 * @property Carbon|null $created_at
 * @property-read Vault $vault
 *
 * @mixin \Eloquent
 */
class VaultWrite extends Model
{
    use HasUuid;

    public $timestamps = false;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'vault_id',
        'vault_key_id',
        'method',
        'summary',
        'client_ip',
        'created_at',
    ];

    protected $casts = [
        'summary' => 'array',
        'created_at' => 'datetime',
    ];

    /** @return BelongsTo<Vault, $this> */
    public function vault(): BelongsTo
    {
        return $this->belongsTo(Vault::class);
    }
}
