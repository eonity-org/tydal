<?php

namespace App\Services\Processing\Contracts;

interface EmbeddingServiceInterface
{
    /**
     * Embed a single text string.
     *
     * @return float[]
     */
    public function embed(string $text): array;

    /**
     * Embed a batch of texts. Returns a list of vectors in the same order as input.
     *
     * @param  string[]  $texts
     * @return float[][]
     */
    public function embedBatch(array $texts): array;

    /**
     * The number of dimensions produced by this model.
     * Must match EMBEDDING_DIMENSIONS in config and the ES dense_vector mapping.
     */
    public function dimensions(): int;
}
