<?php

namespace App\Enums;

use App\Enums\Traits\HasEnumHelpers;

/**
 * Who a platform-level record is offered to.
 *
 * Shared by collection schemes and search indexes. "One organization only" is
 * `RESTRICTED` with a single pivot row rather than a case of its own — one less
 * state to keep consistent.
 */
enum Visibility: string
{
    use HasEnumHelpers;

    /** Every organization. */
    case GLOBAL = 'global';

    /** Only the organizations named in the pivot. */
    case RESTRICTED = 'restricted';

    public function label(): string
    {
        return match ($this) {
            self::GLOBAL => 'All organizations',
            self::RESTRICTED => 'Selected organizations',
        };
    }
}
