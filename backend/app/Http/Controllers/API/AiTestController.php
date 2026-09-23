<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\SearchIndex;
use App\Services\ElasticsearchService;
use App\Services\LLM\LlmDriverFactory;
use App\Services\Processing\Contracts\EmbeddingServiceInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;

class AiTestController extends Controller
{
    /**
     * Return the currently configured drivers and models for all 3 AI services.
     */
    public function config(): JsonResponse
    {
        $embeddingDriver = config('embedding.driver', 'ollama');
        $chatDriver = config('llm.text_driver', 'claude');
        $visionDriver = config('llm.vision_driver') ?: $chatDriver;

        return response()->json([
            'success' => true,
            'data' => [
                'embedding' => [
                    'driver' => $embeddingDriver,
                    'model' => $this->embeddingModel(),
                    'params' => $this->embeddingParams($embeddingDriver),
                ],
                'chat' => [
                    'driver' => $chatDriver,
                    'model' => $this->llmModel($chatDriver),
                    'params' => $this->chatParams($chatDriver),
                ],
                'vision' => [
                    'driver' => $visionDriver,
                    'model' => $this->visionModel($visionDriver),
                    'params' => $this->visionParams($visionDriver),
                ],
            ],
        ]);
    }

    /**
     * Relevant runtime parameters for each service, with a short hint mirroring
     * the .env documentation. Surfaced read-only on the AI Services admin screen
     * so an operator can verify the live configuration next to the test results.
     *
     * @return array<int, array{label: string, value: string, hint: string}>
     */
    private function embeddingParams(string $driver): array
    {
        $params = [
            [
                'label' => 'Vector size',
                'value' => (string) (int) config('embedding.dimensions', 768),
                'hint' => 'Embedding dimensions. Must match the model output and the Elasticsearch chunks index.',
            ],
            [
                'label' => 'Chunk size',
                'value' => ((int) config('embedding.max_chunk_words', 400)).' words',
                'hint' => "Words per text chunk. Must fit the model's token context window — a larger chunk is rejected (HTTP 400 \"input length exceeds the context length\").",
            ],
            [
                'label' => 'Chunk overlap',
                'value' => ((int) config('embedding.chunk_overlap', 50)).' words',
                'hint' => 'Words shared between consecutive chunks, for context continuity.',
            ],
            [
                'label' => 'Batch size',
                'value' => (string) (int) config('embedding.batch_size', 32),
                'hint' => 'Chunks sent to the embed model per request.',
            ],
        ];

        if ($driver === 'ollama') {
            $params[] = [
                'label' => 'Truncate',
                'value' => config('embedding.ollama.truncate', true) ? 'on' : 'off',
                'hint' => 'Truncate an over-length chunk to the model context instead of failing the embed request. Safety net — prefer sizing chunks to fit.',
            ];
        }

        return $params;
    }

    /**
     * @return array<int, array{label: string, value: string, hint: string}>
     */
    private function chatParams(string $driver): array
    {
        $driver = $this->configDriver($driver);

        return [
            [
                'label' => 'Max reply tokens',
                'value' => (string) (int) config("llm.{$driver}.max_tokens", 1024),
                'hint' => "Maximum tokens in the model's reply.",
            ],
            [
                'label' => 'Max context chars',
                'value' => (string) (int) config('llm.max_context_chars', 40000),
                'hint' => 'Maximum characters of retrieved context injected into the RAG prompt.',
            ],
            [
                'label' => 'Meta chunk cap',
                'value' => ((int) config('llm.meta_chunk_max_chars', 600)).' chars',
                'hint' => "Per-resource metadata cap in the RAG context, so one verbose resource can't consume the whole budget.",
            ],
            [
                'label' => 'Auto-tagging',
                'value' => config('autotagging.enabled', false) ? 'on' : 'off',
                'hint' => 'Text-based tag suggestions for documents (AUTOTAGGING_ENABLED).',
            ],
            [
                'label' => 'RAG strict mode',
                'value' => config('autotagging.rag_strict_mode', true) ? 'on' : 'off',
                'hint' => 'On = RAG only uses human-confirmed metadata; off = unconfirmed AI suggestions are folded in (AITY_RAG_STRICT_MODE).',
            ],
        ];
    }

    /**
     * @return array<int, array{label: string, value: string, hint: string}>
     */
    private function visionParams(string $driver): array
    {
        $driver = $this->configDriver($driver);

        $maxTokens = $driver === 'ollama'
            ? (config('llm.ollama.vision_max_tokens') ?? config('llm.ollama.max_tokens', 2048))
            : config("llm.{$driver}.max_tokens", 1024);

        return [
            [
                'label' => 'Max reply tokens',
                'value' => (string) (int) $maxTokens,
                'hint' => "Maximum tokens in the vision model's reply.",
            ],
            [
                'label' => 'Vision analysis',
                'value' => config('autotagging.vision_enabled', false) ? 'on' : 'off',
                'hint' => 'Image analysis (name/description/tags) via the vision model (AI_VISION_ENABLED).',
            ],
        ];
    }

    /**
     * Test the embedding service with a short text.
     *
     * Beyond connectivity, this validates vector size consistency: the model's
     * actual output dimension must match EMBEDDING_DIMENSIONS, and that in turn
     * must match the dims the live ES chunks index was built with. A mismatch on
     * either axis is reported as a failure with an actionable message, so a
     * wrong-size model is caught here instead of silently failing every upload.
     */
    public function testEmbedding(ElasticsearchService $es): JsonResponse
    {
        $t0 = hrtime(true);
        try {
            $embedder = app(EmbeddingServiceInterface::class);
            $vector = $embedder->embed('TYDAL AI connectivity test');
            $actual = count($vector);
            $expected = (int) config('embedding.dimensions', 768);
            $ms = (int) round((hrtime(true) - $t0) / 1e6);

            // 1) model output vs configured dimensions
            if ($actual !== $expected) {
                return response()->json(['success' => true, 'data' => [
                    'ok' => false,
                    'detail' => "Size mismatch: model returns {$actual}-dim vectors but "
                                     ."EMBEDDING_DIMENSIONS is {$expected}. Set EMBEDDING_DIMENSIONS={$actual} "
                                     .'and recreate the index (search:setup-indices --recreate + search:embed), '
                                     ."or pick a model that outputs {$expected} dims.",
                    'duration_ms' => $ms,
                ]]);
            }

            // 2) configured dimensions vs the live chunks index (drift detection)
            if ($indexMismatch = $this->indexDimsMismatch($es, $expected)) {
                return response()->json(['success' => true, 'data' => [
                    'ok' => false,
                    'detail' => $indexMismatch,
                    'duration_ms' => $ms,
                ]]);
            }

            return response()->json(['success' => true, 'data' => [
                'ok' => true,
                'detail' => "{$actual}-dimensional vector returned (matches index)",
                'duration_ms' => $ms,
            ]]);
        } catch (\Throwable $e) {
            $ms = (int) round((hrtime(true) - $t0) / 1e6);

            return response()->json([
                'success' => true,
                'data' => ['ok' => false, 'detail' => $e->getMessage(), 'duration_ms' => $ms],
            ]);
        }
    }

    /**
     * Returns a descriptive message if any existing chunks index was built with
     * a different dims than the configured value, else null.
     */
    private function indexDimsMismatch(ElasticsearchService $es, int $expected): ?string
    {
        foreach (SearchIndex::query()->whereNotNull('index_name')->get() as $searchIndex) {
            $chunksIndex = $es->buildChunksIndexName($searchIndex->index_name);
            $indexDims = $es->getChunksVectorDims($chunksIndex);

            if ($indexDims !== null && $indexDims !== $expected) {
                return "Index drift: '{$chunksIndex}' was built for {$indexDims} dims but "
                       ."EMBEDDING_DIMENSIONS is now {$expected}. Recreate the index "
                       .'(search:setup-indices --recreate + search:embed).';
            }
        }

        return null;
    }

    /**
     * Test the chat LLM with a minimal prompt.
     * Returns the raw model reply on success.
     */
    public function testChat(): JsonResponse
    {
        $t0 = hrtime(true);
        try {
            $llm = LlmDriverFactory::chatDriver();
            $response = $llm->chat([
                ['role' => 'system', 'content' => 'You are a connectivity test assistant. Reply with a single word.'],
                ['role' => 'user',   'content' => 'Say: OK'],
            ]);
            $ms = (int) round((hrtime(true) - $t0) / 1e6);

            return response()->json([
                'success' => true,
                'data' => ['ok' => true, 'detail' => trim($response), 'duration_ms' => $ms],
            ]);
        } catch (\Throwable $e) {
            $ms = (int) round((hrtime(true) - $t0) / 1e6);

            return response()->json([
                'success' => true,
                'data' => ['ok' => false, 'detail' => $e->getMessage(), 'duration_ms' => $ms],
            ]);
        }
    }

    /**
     * Test the vision LLM with a tiny hardcoded 1×1 PNG.
     * No file I/O or GD required — just enough to exercise the multimodal API.
     */
    public function testVision(): JsonResponse
    {
        // Generate an 8×8 black-and-white checkerboard PNG — visually distinct enough
        // for vision models to describe meaningfully (avoids "no visible data" replies).
        $imageData = $this->makeCheckerboardPng();

        $t0 = hrtime(true);
        try {
            $llm = LlmDriverFactory::visionDriver();
            $response = $llm->chatWithVision($imageData, 'image/png', [
                ['role' => 'system', 'content' => 'You are a connectivity test assistant. Be extremely brief.'],
                ['role' => 'user',   'content' => 'What do you see in this image? Reply in 5 words or less.'],
            ]);
            $ms = (int) round((hrtime(true) - $t0) / 1e6);

            return response()->json([
                'success' => true,
                'data' => ['ok' => true, 'detail' => trim($response), 'duration_ms' => $ms],
            ]);
        } catch (\Throwable $e) {
            $ms = (int) round((hrtime(true) - $t0) / 1e6);

            return response()->json([
                'success' => true,
                'data' => ['ok' => false, 'detail' => $e->getMessage(), 'duration_ms' => $ms],
            ]);
        }
    }

    /**
     * Return pulled models from the local Ollama instance.
     * Calls GET /api/tags on OLLAMA_HOST.
     */
    public function ollamaModels(): JsonResponse
    {
        $host = rtrim(config('llm.ollama.host', 'http://localhost:11434'), '/');

        try {
            $response = Http::timeout(5)->get("{$host}/api/tags");

            if (! $response->successful()) {
                return response()->json([
                    'success' => false,
                    'message' => "Ollama unreachable (HTTP {$response->status()})",
                ], 502);
            }

            $models = collect($response->json('models', []))
                ->pluck('name')
                ->sort()
                ->values();

            return response()->json(['success' => true, 'data' => ['models' => $models]]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Could not connect to Ollama: '.$e->getMessage(),
            ], 502);
        }
    }

    // =========================================================================
    // INTERNAL
    // =========================================================================

    /**
     * Generate an 8×8 black-and-white checkerboard PNG using GD.
     * Falls back to a hardcoded 1×1 grey pixel if GD is unavailable.
     */
    private function makeCheckerboardPng(): string
    {
        if (! function_exists('imagecreate')) {
            // GD not available — use a minimal 1×1 grey PNG
            return base64_decode(
                'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='
            );
        }

        $size = 8;
        $img = imagecreate($size, $size);
        $black = imagecolorallocate($img, 0, 0, 0);
        $white = imagecolorallocate($img, 255, 255, 255);

        for ($x = 0; $x < $size; $x++) {
            for ($y = 0; $y < $size; $y++) {
                imagesetpixel($img, $x, $y, ($x + $y) % 2 === 0 ? $black : $white);
            }
        }

        ob_start();
        imagepng($img);
        $data = ob_get_clean();
        imagedestroy($img);

        return $data;
    }

    private function embeddingModel(): string
    {
        return match (config('embedding.driver', 'ollama')) {
            'jina' => config('embedding.jina.model', 'jina-embeddings-v3'),
            'voyage' => config('embedding.voyage.model', 'voyage-3'),
            default => config('embedding.ollama.model', 'nomic-embed-text'),
        };
    }

    /**
     * 'anthropicproxy' shares config/llm.php's 'claude' block (same
     * ClaudeLlmService, same ANTHROPIC_* vars, just a name that makes an
     * Anthropic-compatible proxy like Z.ai's visible in LLM_TEXT_DRIVER
     * itself) — see LlmDriverFactory. Every driver-keyed config('llm.{driver}.*')
     * lookup below needs this, not just the model match.
     */
    private function configDriver(string $driver): string
    {
        return $driver === 'anthropicproxy' ? 'claude' : $driver;
    }

    private function llmModel(string $driver): string
    {
        // Note: config()'s default arg only applies when the key is entirely absent —
        // 'zai' (and 'ollama.vision_model') store an explicit null when their env var
        // is unset, so that default never kicks in and a bare config() call here would
        // return null, violating this method's string return type (TypeError, 500s the
        // whole /ai/config endpoint). Use ?: to actually fall back on empty/null.
        return match ($this->configDriver($driver)) {
            'claude' => config('llm.claude.model', 'claude-sonnet-4-6'),
            'openai' => config('llm.openai.model', 'gpt-4o'),
            'gemini' => config('llm.gemini.model', 'gemini-2.0-flash'),
            'zai' => config('llm.zai.model') ?: '— (set ZAI_MODEL)',
            'ollama' => config('llm.ollama.model', 'llama3.2'),
            default => 'unknown',
        };
    }

    /** Like llmModel() but for vision — reads OLLAMA_VISION_MODEL when driver is ollama. */
    private function visionModel(string $driver): string
    {
        if ($driver === 'ollama') {
            return config('llm.ollama.vision_model') ?: config('llm.ollama.model', 'llama3.2');
        }

        return $this->llmModel($driver);
    }
}
