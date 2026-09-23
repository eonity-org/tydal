<?php

namespace App\Services\LLM\Contracts;

interface LlmServiceInterface
{
    /**
     * Return a human-readable identifier for the active model.
     * Format: "<driver>:<model-name>", e.g. "claude:claude-sonnet-4-6", "zai:llama-3.1-70b".
     * Used by AiActivityLogger to populate the ai-models log.
     */
    public function getModel(): string;

    /**
     * Send a chat conversation and return the assistant's response text.
     *
     * Messages follow the OpenAI convention:
     *   [['role' => 'system', 'content' => '...'], ['role' => 'user', 'content' => '...']]
     *
     * Supported roles: 'system', 'user', 'assistant'.
     * Each driver maps this format to its own API schema internally.
     *
     * @param  array<int, array{role: string, content: string}>  $messages
     *
     * @throws \RuntimeException on API failure
     */
    public function chat(array $messages): string;

    /**
     * Send a prompt alongside a raw image and return the assistant's response text.
     *
     * The image is passed as raw bytes; drivers encode it as needed (e.g. base64).
     * Text messages follow the same format as chat().
     *
     * @param  string  $imageData  Raw binary image content
     * @param  string  $mimeType  Image MIME type (e.g. 'image/jpeg', 'image/png')
     * @param  array<int, array{role: string, content: string}>  $messages
     *
     * @throws \RuntimeException on API failure
     * @throws \BadMethodCallException if the driver does not support vision
     */
    public function chatWithVision(string $imageData, string $mimeType, array $messages): string;
}
