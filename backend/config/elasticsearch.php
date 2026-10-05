<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Elasticsearch Connection
    |--------------------------------------------------------------------------
    |
    | Set ELASTICSEARCH_HOST in your .env to point at your ES cluster.
    | For local dev: http://localhost:9200
    | For Elastic Cloud: https://your-deployment.es.io:443
    |
    */

    'host' => env('ELASTICSEARCH_HOST', 'http://localhost:9200'),
    'api_key' => env('ELASTICSEARCH_API_KEY'),
    'username' => env('ELASTICSEARCH_USERNAME'),
    'password' => env('ELASTICSEARCH_PASSWORD'),

    /*
    |--------------------------------------------------------------------------
    | Index Prefix
    |--------------------------------------------------------------------------
    |
    | Prepended to every physical index name this installation creates or
    | queries: collection indices (search_indexes.index_name stays the logical,
    | unprefixed name), their `_chunks` companions and the per-vault
    | `vault_<uuid>` indices. Destructive commands (search:wipe-indices,
    | search:setup-indices --recreate, search:reconcile --fix) only reach
    | names under this prefix.
    |
    | Set a distinct prefix (e.g. `prod_`, `staging_`) whenever two
    | installations share one cluster. Empty (the default) keeps the legacy
    | unprefixed names. Lowercase letters, digits, `_`, `-` and `.` only,
    | starting with a letter or digit.
    |
    */

    'index_prefix' => env('ELASTICSEARCH_INDEX_PREFIX', ''),

    /*
    |--------------------------------------------------------------------------
    | Index Settings
    |--------------------------------------------------------------------------
    |
    | Default settings applied to every index created via search:setup-indices.
    |
    */

    'index_settings' => [
        'number_of_shards' => env('ELASTICSEARCH_SHARDS', 1),
        'number_of_replicas' => env('ELASTICSEARCH_REPLICAS', 0),
    ],

    /*
    |--------------------------------------------------------------------------
    | Hybrid Search Tuning
    |--------------------------------------------------------------------------
    |
    | hybrid_min_knn_score: minimum cosine similarity score a chunk must reach
    | before it enters the RRF merge step.  ES reports cosine similarity as
    | (1 + cosine) / 2, so the default 0.6 ≈ cosine = 0.2 — semantically
    | related content. Raise toward 1.0 for stricter matching; lower toward
    | 0.5 (orthogonal) for broader recall.
    |
    */

    'hybrid_min_knn_score' => env('ELASTICSEARCH_HYBRID_MIN_KNN_SCORE', 0.7),

    /*
    |--------------------------------------------------------------------------
    | RAG Chunk Score Filters
    |--------------------------------------------------------------------------
    |
    | rag_min_score:   absolute floor — chunks below this cosine similarity are
    |                  discarded before being sent to the LLM as context.
    |                  ES reports cosine as (1 + cosine) / 2, so 0.72 ≈ cosine = 0.44.
    |                  Lower if relevant chunks are being missed; raise to tighten.
    |
    | rag_score_ratio: relative filter — after applying the absolute floor, discard
    |                  chunks whose score is below (best_score × ratio). This removes
    |                  filler chunks that are much weaker than the top match.
    |                  0.88 means a chunk must score ≥ 88% of the best chunk's score.
    |
    */

    'rag_min_score' => env('ELASTICSEARCH_RAG_MIN_SCORE', 0.72),
    'rag_score_ratio' => env('ELASTICSEARCH_RAG_SCORE_RATIO', 0.88),
];
