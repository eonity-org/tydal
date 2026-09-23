<?php

namespace App\Exceptions;

use App\Models\SearchIndex;
use App\Support\IndexVocabulary;
use RuntimeException;

/**
 * Two schemes on one index declare the same field with incompatible ES
 * mappings. There is no correct mapping to write, so `search:setup-indices`
 * stops instead of picking one by row order — see {@see IndexVocabulary}.
 */
class IndexVocabularyConflict extends RuntimeException
{
    /** @param list<string> $conflicts */
    public function __construct(
        public readonly SearchIndex $searchIndex,
        public readonly array $conflicts,
    ) {
        parent::__construct(
            "field vocabulary conflict on {$searchIndex->index_name} — ".implode(' · ', $conflicts)
        );
    }
}
