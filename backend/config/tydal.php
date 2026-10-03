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

    /*
    |--------------------------------------------------------------------------
    | Visible filenames at the vault boundary
    |--------------------------------------------------------------------------
    |
    | Whether the public vault surfaces (/v/…, /h/…, /vault/{hash}) show the
    | names files were uploaded with — in addresses, in JSON, and as the name a
    | download or inline view is saved under.
    |
    | Off (default): uploaded filenames never leave TYDAL. A file is addressed
    | by a 3-character code, the same for that file in that vault forever
    | (/v/acme/expo/pepe/k7q), and is named after its resource and that code
    | (pepe-k7q.jpg; renditions pepe-k7q-large.jpg; a resource preview
    | pepe-preview.jpg), so a downloaded file stays recognisable. Filenames often carry
    | what a public address must not — camera serials, dates, the names of
    | people photographed — and renaming, reordering or re-roling a file never
    | moves its address.
    |
    | On: the uploaded name everywhere (/v/acme/expo/pepe/juan, juan.jpg), for
    | installations whose filenames are written for the public, or where the
    | exact original name must be retrievable.
    |
    | Hash addresses (/h/…) are the same either way. Switching applies to links
    | minted from then on; `php artisan vault:reslug-files` brings existing
    | file addresses in line.
    |
    */

    'export_visible_filenames' => (bool) env('TYDAL_EXPORT_VISIBLE_FILENAMES', false),
];
