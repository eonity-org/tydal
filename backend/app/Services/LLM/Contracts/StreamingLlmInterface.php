<?php

namespace App\Services\LLM\Contracts;

/**
 * Opt-in capability: a driver that can stream a chat completion as it is
 * generated. Detected via `instanceof` — drivers that don't implement it
 * degrade to a single `chat()` call, so the ask surface works on every
 * backend and streams only where the model supports it.
 */
interface StreamingLlmInterface
{
    /**
     * Yield answer deltas (content fragments) in order as they arrive.
     *
     * @param  list<array{role: string, content: string}>  $messages
     * @return \Generator<int, string>
     */
    public function chatStream(array $messages): \Generator;
}
