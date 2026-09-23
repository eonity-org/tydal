<?php

namespace App\Enums;

use App\Enums\Traits\HasEnumHelpers;

/**
 * How open a vault's boundary is — the one axis the outside world feels.
 *
 * Replaces the `is_active` + `is_published` pair. They were never independent:
 * the policy gate tests `is_active` first, so "inactive but published" was
 * unreachable, and the three surviving combinations are exactly these cases.
 *
 * Openness lives here rather than on the resource because one resource is
 * projected through many vaults: the same photograph is private through the
 * jury's vault and public through the opened exhibition's, with nothing about
 * the photograph itself changing. See ResourceState for the other axis.
 */
enum VaultState: string
{
    use HasEnumHelpers;

    /** Off. Every address 404s — keys and signed grants included. */
    case DISABLED = 'disabled';

    /** Live, but credentials required: a vault key or a signed grant. */
    case PRIVATE = 'private';

    /** Open: the address alone is enough. */
    case PUBLIC = 'public';

    /** Does the boundary answer at all (with or without a credential)? */
    public function isReachable(): bool
    {
        return $this !== self::DISABLED;
    }

    /** Does it answer without any credential? */
    public function isOpen(): bool
    {
        return $this === self::PUBLIC;
    }

    public function label(): string
    {
        return match ($this) {
            self::DISABLED => 'Disabled',
            self::PRIVATE => 'Private',
            self::PUBLIC => 'Public',
        };
    }
}
