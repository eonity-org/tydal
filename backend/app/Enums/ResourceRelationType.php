<?php

namespace App\Enums;

/**
 * Edge types of the resource graph (Epic 4.3).
 *
 * `RELATED` is symmetric: one row per pair (subject/object normalized by
 * UUID order at write time). `DERIVED_FROM` is directed: the subject was
 * derived from the object. Vault membership (`IN_VAULT`) is deliberately
 * not an edge type — it is derived live from the vault projection.
 */
enum ResourceRelationType: string
{
    case RELATED = 'related';
    case DERIVED_FROM = 'derived_from';

    public function isSymmetric(): bool
    {
        return $this === self::RELATED;
    }
}
