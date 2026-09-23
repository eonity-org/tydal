<?php

namespace App\Models\Traits;

use App\Enums\Visibility;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * "Which organizations is this platform-level record offered to?"
 *
 * Collection schemes and search indexes are both platform-owned and both need
 * the same answer, so the mechanism lives here once. `global` means everyone;
 * `restricted` means only the organizations in the pivot.
 *
 * The implementing model must declare `visibilityPivotTable()`.
 */
trait HasOrganizationVisibility
{
    /** Organizations this record is restricted to (empty when global). */
    public function organizations(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class, $this->visibilityPivotTable())
            ->withTimestamps();
    }

    public function isGlobal(): bool
    {
        return $this->visibility === Visibility::GLOBAL;
    }

    /** Is this offered to the given organization? */
    public function isVisibleTo(?string $organizationId): bool
    {
        if ($this->isGlobal()) {
            return true;
        }

        return $organizationId !== null
            && $this->organizations()->where('organizations.id', $organizationId)->exists();
    }

    /**
     * Restrict a query to what this organization may use.
     *
     * A null organization (no context) sees only the global records — never
     * somebody else's restricted ones.
     */
    public function scopeVisibleTo(Builder $query, ?string $organizationId): Builder
    {
        return $query->where(function (Builder $q) use ($organizationId) {
            $q->where('visibility', Visibility::GLOBAL->value);

            if ($organizationId !== null) {
                $q->orWhereHas('organizations', fn (Builder $o) => $o->where('organizations.id', $organizationId));
            }
        });
    }

    /**
     * Order most-specific-first: records restricted to somebody come before
     * the global ones.
     *
     * This is what makes "give the organization its own index if it has one,
     * otherwise the shared default" a single query rather than two.
     */
    public function scopeMostSpecificFirst(Builder $query): Builder
    {
        return $query->orderByRaw('CASE WHEN visibility = ? THEN 0 ELSE 1 END', [Visibility::RESTRICTED->value]);
    }
}
