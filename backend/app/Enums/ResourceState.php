<?php

namespace App\Enums;

use App\Enums\Traits\HasEnumHelpers;

/**
 * Where a resource sits in its lifecycle — the single answer to "is this
 * thing real, and should anyone see it?".
 *
 * Replaces the old trio of `active` (bool), `visibility` (5-value enum of
 * which only `draft` was ever enforced) and `published_at` (never read). They
 * overlapped: two fields meaning "not visible", filtered in different places,
 * so neither was applied consistently — a deactivated resource still resolved
 * at the vault boundary and a draft was projected publicly.
 *
 * Note what this deliberately does NOT model: whether the outside world can
 * reach the resource. One resource is projected through many vaults, each with
 * its own openness, so that answer belongs to the projection — see VaultState.
 */
enum ResourceState: string
{
    use HasEnumHelpers;

    /** Mid-creation: the wizard's working copy. Never addressable, prunable. */
    case DRAFT = 'draft';

    /** Normal. Listed, searchable, projectable. */
    case LIVE = 'live';

    /** Withdrawn: gone from every listing AND no longer addressable. */
    case ARCHIVED = 'archived';

    /** May this resource be listed, searched, or projected by a vault? */
    public function isVisible(): bool
    {
        return $this === self::LIVE;
    }

    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Draft',
            self::LIVE => 'Live',
            self::ARCHIVED => 'Archived',
        };
    }
}
