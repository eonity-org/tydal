<?php

namespace App\Enums;

/**
 * Who approved a tag entry into the org's vocabulary.
 *
 * Pairs with TagVocabulary (where the label came from). Together they replace
 * the old free-form `source` column on semantic_tags.
 *
 * Per-attachment provenance (who attached a tag to a specific resource) lives
 * separately on Resource via tags_origin / tags_set_by.
 */
enum TagReviewer: string
{
    case USER = 'user';   // A human created or approved this tag entry
    case AITY = 'aity';   // The AI agent auto-approved this tag entry

    public function label(): string
    {
        return match ($this) {
            self::USER => 'User',
            self::AITY => 'Aity',
        };
    }
}
