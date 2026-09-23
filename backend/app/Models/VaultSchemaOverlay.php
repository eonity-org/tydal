<?php

namespace App\Models;

use App\Models\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Per-(vault × scheme) semantic mapping override — Epic 3.1.
 * field_roles: { "<fieldName>": { "gallery": "badge", "ai": "facet", … } }.
 * Resolution order (VaultSchemaResolver): preset ← scheme vault_roles ← this.
 *
 * @property string $id
 * @property string $vault_id
 * @property string $scheme_id
 * @property array<string, array<string, string>> $field_roles
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Vault $vault
 * @property-read CollectionScheme $scheme
 *
 * @mixin \Eloquent
 */
class VaultSchemaOverlay extends Model
{
    use HasUuid;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'vault_id',
        'scheme_id',
        'field_roles',
    ];

    protected $casts = [
        'field_roles' => 'array',
    ];

    /** @return BelongsTo<Vault, $this> */
    public function vault(): BelongsTo
    {
        return $this->belongsTo(Vault::class);
    }

    /** @return BelongsTo<CollectionScheme, $this> */
    public function scheme(): BelongsTo
    {
        return $this->belongsTo(CollectionScheme::class, 'scheme_id');
    }
}
