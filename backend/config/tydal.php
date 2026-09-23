<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Collection quota
    |--------------------------------------------------------------------------
    |
    | How many collections an organization's own people may create before a
    | platform administrator has to raise the limit. Per-organization overrides
    | live in `organizations.collection_quota`; this is the value used when that
    | column is null.
    |
    | A collection is not free: it pins a field contract and a search index, and
    | the facets of everything in it. The cap is there so self-service creation
    | cannot turn into an unbounded sprawl of near-identical collections, not to
    | be stingy — raise it per customer as needed.
    |
    | Platform administrators are never counted against it.
    |
    */

    'collection_quota' => (int) env('TYDAL_COLLECTION_QUOTA', 5),
];
