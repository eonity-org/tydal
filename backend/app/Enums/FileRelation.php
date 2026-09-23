<?php

namespace App\Enums;

use App\Enums\Traits\HasEnumHelpers;

/**
 * File Relation Enum
 *
 * Defines how a file relates to the canonical file within an asset.
 * Only applicable to non-canonical files (role = component | supporting).
 * A canonical file always has relation = null.
 */
enum FileRelation: string
{
    use HasEnumHelpers;

    case DERIVED = 'derived';
    case RENDITION = 'rendition';
    case VARIANT = 'variant';
    case TRANSLATION = 'translation';
    case TRANSCRIPT = 'transcript';
    case EXTRACTED = 'extracted';

    public function label(): string
    {
        return match ($this) {
            self::DERIVED => 'Derived',
            self::RENDITION => 'Rendition',
            self::VARIANT => 'Variant',
            self::TRANSLATION => 'Translation',
            self::TRANSCRIPT => 'Transcript',
            self::EXTRACTED => 'Extracted',
        };
    }
}
