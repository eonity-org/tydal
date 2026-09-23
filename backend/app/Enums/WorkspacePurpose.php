<?php

namespace App\Enums;

enum WorkspacePurpose: string
{
    case AITY_REVIEW = 'aity_review';

    public function label(): string
    {
        return match ($this) {
            self::AITY_REVIEW => 'AiTy Review',
        };
    }
}
