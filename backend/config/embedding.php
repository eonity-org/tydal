<?php

return [
    'driver' => env('EMBEDDING_DRIVER', 'ollama'),
    'dimensions' => (int) env('EMBEDDING_DIMENSIONS', 768),
    'batch_size' => (int) env('EMBEDDING_BATCH_SIZE', 32),

    // Fallback chunk sizing for ChunkingService when a CollectionScheme has no
    // explicit processing_config.chunk_size / chunk_overlap. The chunk word
    // budget must fit the embedding model's context window — see the .env hints.
    'max_chunk_words' => (int) env('EMBEDDING_MAX_CHUNK_WORDS', 400),
    'chunk_overlap' => (int) env('EMBEDDING_CHUNK_OVERLAP', 50),

    'ollama' => [
        'host' => env('OLLAMA_HOST', 'http://localhost:11434'),
        'model' => env('OLLAMA_EMBED_MODEL', 'nomic-embed-text'),
        'timeout' => (int) env('OLLAMA_TIMEOUT', 120),
        // Truncate over-length input to the model context instead of failing the
        // request. Safety net only — keep chunk size within context to avoid
        // silently dropping chunk tails.
        'truncate' => (bool) env('OLLAMA_EMBED_TRUNCATE', true),
        // Hard char cap applied BEFORE the request. Ollama's `truncate` flag is
        // unreliable for token-dense multibyte text (it can still 400 with
        // "input length exceeds the context length"), so we pre-truncate to
        // guarantee the request fits the model's context. Sized for the default
        // mxbai-embed-large (512 tok ≈ 1450 chars on dense text; 1200 leaves
        // margin). Raise it for larger-context models (e.g. nomic-embed-text,
        // 2048 tok) or set 0 to disable. 0 = no cap.
        'max_input_chars' => (int) env('EMBEDDING_OLLAMA_MAX_INPUT_CHARS', 1200),
    ],

    'voyage' => [
        'api_key' => env('VOYAGE_API_KEY', ''),
        'model' => env('VOYAGE_EMBED_MODEL', 'voyage-3'),
        'timeout' => (int) env('VOYAGE_TIMEOUT', 30),
    ],

    'jina' => [
        'api_key' => env('JINA_API_KEY', ''),
        'model' => env('JINA_EMBED_MODEL', 'jina-embeddings-v3'),
        'timeout' => (int) env('JINA_TIMEOUT', 30),
    ],
];
