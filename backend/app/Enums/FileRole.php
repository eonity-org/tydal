<?php

namespace App\Enums;

use App\Enums\Traits\HasEnumHelpers;

/**
 * File Role Enum
 *
 * Defines the structural role of a file within an asset.
 * Orthogonal to FileRelation — role defines WHAT the file IS,
 * relation defines HOW it was derived from the canonical file.
 */
enum FileRole: string
{
    use HasEnumHelpers;

    case CANONICAL = 'canonical';
    case COMPONENT = 'component';
    case SUPPORTING = 'supporting';

    public function label(): string
    {
        return match ($this) {
            self::CANONICAL => 'Canonical',
            self::COMPONENT => 'Component',
            self::SUPPORTING => 'Supporting',
        };
    }
}
