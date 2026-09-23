<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when an embedding vector's length does not match the dimension the
 * system was configured/indexed for. This is almost always a model ↔ config
 * mismatch (e.g. switching OLLAMA_EMBED_MODEL without updating
 * EMBEDDING_DIMENSIONS and recreating the ES index).
 *
 * The message is intentionally actionable — it is surfaced verbatim in the
 * admin "AI Services" screen and in the resource activity timeline.
 */
class EmbeddingDimensionMismatchException extends RuntimeException
{
    public function __construct(
        public readonly int $actual,
        public readonly int $expected,
        public readonly ?string $model = null,
        public readonly ?string $driver = null,
    ) {
        $who = $model ? "model '{$model}'" : 'the embedding model';
        $drv = $driver ? " ({$driver})" : '';

        parent::__construct(
            "Embedding size mismatch: {$who}{$drv} returns {$actual}-dimensional "
            ."vectors but EMBEDDING_DIMENSIONS is {$expected}. "
            ."Set EMBEDDING_DIMENSIONS={$actual} and run "
            .'`search:setup-indices --recreate` + `search:embed`, or choose a model '
            ."that outputs {$expected} dimensions."
        );
    }

    /** Throw if $actual differs from $expected. No-op when they match. */
    public static function assert(int $actual, int $expected, ?string $model = null, ?string $driver = null): void
    {
        if ($actual !== $expected) {
            throw new self($actual, $expected, $model, $driver);
        }
    }
}
