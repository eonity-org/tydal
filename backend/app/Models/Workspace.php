<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $organization_id
 * @property string $user_owner_id
 * @property string $name
 * @property string|null $slug
 * @property string|null $description
 * @property bool $is_active
 * @property bool $is_default
 * @property bool $is_system
 * @property string|null $purpose
 * @property string|null $auto_approve_status
 * @property array<array-key, mixed>|null $auto_approve_log
 * @property Carbon|null $auto_approve_reviewed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, Vault> $vaults
 * @property-read int|null $vaults_count
 * @property-read Organization $organization
 * @property-read User|null $owner
 * @property-read Collection<int, \App\Models\Resource> $resources
 * @property-read int|null $resources_count
 * @property-read Collection<int, User> $users
 * @property-read int|null $users_count
 *
 * @method static \Database\Factories\WorkspaceFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Workspace newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Workspace newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Workspace query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Workspace whereAutoApproveLog($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Workspace whereAutoApproveReviewedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Workspace whereAutoApproveStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Workspace whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Workspace whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Workspace whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Workspace whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Workspace whereIsDefault($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Workspace whereIsSystem($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Workspace whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Workspace whereOrganizationId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Workspace wherePurpose($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Workspace whereSlug($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Workspace whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Workspace whereUserOwnerId($value)
 *
 * @mixin \Eloquent
 */
class Workspace extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'user_owner_id',
        'name',
        'slug',
        'description',
        'is_active',
        'is_default',
        'is_system',
        'purpose',
        'auto_approve_status',
        'auto_approve_log',
        'auto_approve_reviewed_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_default' => 'boolean',
        'is_system' => 'boolean',
        'auto_approve_log' => 'array',
        'auto_approve_reviewed_at' => 'datetime',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_owner_id');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_workspace')
            ->withTimestamps();
    }

    /** @return BelongsToMany<\App\Models\Resource, $this> */
    public function resources(): BelongsToMany
    {
        return $this->belongsToMany(Resource::class, 'dam_resource_workspace');
    }

    /** @return BelongsToMany<Vault, $this> */
    public function vaults(): BelongsToMany
    {
        return $this->belongsToMany(Vault::class, 'workspace_vault')->withTimestamps();
    }
}
