<?php

namespace App\Services;

use App\Enums\ResourceState;
use App\Models\Collection;
use App\Models\Resource;
use App\Models\SearchIndex;
use App\Models\Workspace;
use App\Services\LLM\Contracts\LlmServiceInterface;
use App\Services\Processing\Contracts\EmbeddingServiceInterface;

class RagService
{
    private const SYSTEM_PROMPT = <<<'PROMPT'
You are a document assistant for a digital asset repository. Answer the user's question using ONLY the context passages provided below.

Context passages come in two forms:
- [Source: <name>, page N] — an extracted text passage from a document
- [Resource overview: <name>] — a metadata summary that may contain: author, title, subject, creation date, language, page count, camera make/model, GPS coordinates, and other file properties

Use both forms to answer questions. For example:
- "Who authored this document?" → look for Author in a Resource overview
- "What documents were created in 2023?" → look for Created or Date Taken fields
- "Find photos taken with a Canon camera" → look for Camera Make in Resource overviews

If the answer cannot be found in any passage, say so clearly — do not invent information.
When citing a document passage, mention the source name and page number if available.
PROMPT;

    public function __construct(
        private readonly ElasticsearchService $es,
        private readonly EmbeddingServiceInterface $embedder,
        private readonly LlmServiceInterface $llm,
    ) {}

    /**
     * Answer a question using RAG over a collection's vector index.
     *
     * @return array{answer: string, sources: array}
     *
     * @throws \RuntimeException if no vector index is configured
     */
    public function ask(Collection $collection, string $question, int $k = 5): array
    {
        $indexName = $collection->searchIndex?->index_name;

        if (! $indexName) {
            throw new \RuntimeException('This collection has no search index configured.');
        }

        $chunksIndex = $this->es->buildChunksIndexName($indexName);

        // 1. Embed the question
        $queryVector = $this->embedder->embed($question);

        // 2. Retrieve top-K relevant chunks from ES (relevance floor: org
        // override → instance default)
        $hits = $this->es->knnSearchChunksForRag(
            $chunksIndex,
            $collection->id,
            $queryVector,
            $k,
            $collection->organization->aityRagMinScore()
        );

        if (empty($hits)) {
            return [
                'answer' => 'No relevant content found in this collection to answer your question.',
                'sources' => [],
            ];
        }

        // 3a. Guarantee every resource's meta chunk is present regardless of k-NN ranking
        $metaChunks = $this->es->fetchMetaChunksForCollection($chunksIndex, $collection->id);
        $hits = $this->mergeMetaChunks($metaChunks, $hits);

        // 3b. Load resource names for citations (single query)
        $resourceIds = array_unique(array_column($hits, 'resource_id'));
        $resourceNames = Resource::whereIn('id', $resourceIds)
            ->pluck('name', 'id');

        // 4. Build context string
        $maxChars = (int) config('llm.max_context_chars', 40000);
        $t0 = hrtime(true);
        ['context' => $context, 'truncated' => $contextTruncated] = $this->buildContext($hits, $resourceNames, $maxChars);
        AiActivityLogger::ragContextBuild(
            (string) $collection->id, $question,
            $hits, $hits, $context, false,
            (int) round((hrtime(true) - $t0) / 1e6),
        );

        // 5. Build messages
        $messages = [
            ['role' => 'system', 'content' => self::SYSTEM_PROMPT],
            ['role' => 'user',   'content' => "Context:\n\n{$context}\n\nQuestion: {$question}"],
        ];

        // 6. Call LLM
        $tq = hrtime(true);
        $answer = $this->llm->chat($messages);
        $ragDurationMs = (int) round((hrtime(true) - $tq) / 1e6);

        // 7. Build source citations
        $sources = $this->buildSources($hits, $resourceNames, $answer);

        AiActivityLogger::modelCall('ask-aity', $this->llm->getModel(), 'rag', 'completed', $ragDurationMs, mb_substr($question, 0, 120), null, mb_substr($answer, 0, 200));
        AiActivityLogger::ragQuery(
            (string) $collection->id, 'collection',
            $question, $answer, $sources,
            $ragDurationMs,
        );

        return ['answer' => $answer, 'sources' => $sources, 'context_truncated' => $contextTruncated];
    }

    /**
     * Answer a question using workspace-scoped RAG.
     *
     * Two-phase approach:
     *   1. Collect all resource IDs in the workspace from the DB (no ES indexing lag risk)
     *   2. k-NN search across all chunk indices, filtered by those resource IDs
     *
     * @return array{answer: string, sources: array}
     *
     * @throws \RuntimeException if no vector index is available
     */
    public function askForWorkspace(Workspace $workspace, string $question, int $k = 5, bool $strict = false): array
    {
        // Phase 1 — get workspace resource IDs directly from DB
        $resourceIds = $workspace->is_default
            ? Resource::where('organization_id', $workspace->organization_id)
                ->where('state', ResourceState::LIVE->value)
                ->pluck('id')
                ->all()
            : $workspace->resources()
                ->where('resources.state', ResourceState::LIVE->value)
                ->pluck('resources.id')
                ->all();

        if (empty($resourceIds)) {
            return [
                'answer' => 'This workspace has no resources to search.',
                'sources' => [],
            ];
        }

        // Resolve chunks indices from the collections these resources belong to
        $collectionIds = Resource::whereIn('id', $resourceIds)->distinct()->pluck('collection_id')->all();
        $baseIndexNames = SearchIndex::whereHas(
            'collections',
            fn ($q) => $q->whereIn('id', $collectionIds)
        )->pluck('index_name')->unique()->all();

        $chunksIndexNames = array_values(
            array_filter(
                array_map(fn ($n) => $this->es->buildChunksIndexName($n), $baseIndexNames),
                fn ($n) => $this->es->chunksIndexExists($n)
            )
        );

        if (empty($chunksIndexNames)) {
            throw new \RuntimeException('No vector search index is available for this workspace.');
        }

        // Phase 2 — embed question and search chunks filtered by workspace
        // resource IDs (relevance floor: org override → instance default)
        $queryVector = $this->embedder->embed($question);

        $hits = $this->es->knnSearchChunksForWorkspace(
            $chunksIndexNames,
            $resourceIds,
            $queryVector,
            $k,
            minScore: $workspace->organization->aityRagMinScore()
        );

        if (empty($hits)) {
            return [
                'answer' => 'No relevant content found in this workspace to answer your question.',
                'sources' => [],
            ];
        }

        // Guarantee every workspace resource's meta chunk is present regardless of k-NN ranking
        $metaChunks = $this->es->fetchMetaChunksForResources($chunksIndexNames, $resourceIds);
        $hits = $this->mergeMetaChunks($metaChunks, $hits);

        $hitResourceIds = array_unique(array_column($hits, 'resource_id'));
        $resourceNames = Resource::whereIn('id', $hitResourceIds)->pluck('name', 'id');

        $maxChars = (int) config('llm.max_context_chars', 40000);
        $t0 = hrtime(true);
        ['context' => $context, 'truncated' => $contextTruncated] = $this->buildContext($hits, $resourceNames, $maxChars, $strict);
        AiActivityLogger::ragContextBuild(
            (string) $workspace->id, $question,
            $hits, $hits, $context, $strict,
            (int) round((hrtime(true) - $t0) / 1e6),
        );

        $messages = [
            ['role' => 'system', 'content' => self::SYSTEM_PROMPT],
            ['role' => 'user',   'content' => "Context:\n\n{$context}\n\nQuestion: {$question}"],
        ];

        $tq = hrtime(true);
        $answer = $this->llm->chat($messages);
        $ragDurationMs = (int) round((hrtime(true) - $tq) / 1e6);
        $sources = $this->buildSources($hits, $resourceNames, $answer);

        AiActivityLogger::modelCall('ask-aity', $this->llm->getModel(), 'rag', 'completed', $ragDurationMs, mb_substr($question, 0, 120), null, mb_substr($answer, 0, 200));
        AiActivityLogger::ragQuery(
            (string) $workspace->id, 'workspace',
            $question, $answer, $sources,
            $ragDurationMs,
        );

        return ['answer' => $answer, 'sources' => $sources, 'context_truncated' => $contextTruncated];
    }

    /**
     * @return array{context: string, truncated: bool}
     */
    private function buildContext(array $hits, $resourceNames, int $maxChars, bool $strict = false): array
    {
        $parts = [];
        $total = 0;
        $truncated = false;
        $metaMaxChars = (int) config('llm.meta_chunk_max_chars', 600);

        foreach ($hits as $hit) {
            $name = $resourceNames[$hit['resource_id']] ?? 'Unknown';
            $isMeta = str_starts_with($hit['chunk_id'], 'meta-');

            // Strict mode: skip metadata chunks entirely — only raw document passages.
            if ($strict && $isMeta) {
                continue;
            }

            // Metadata chunks carry resource-level context (name + description).
            // Label them differently so the LLM knows they are not page passages.
            if ($isMeta) {
                $heading = "[Resource overview: {$name}]";
                // Cap each meta chunk so a verbose Tika dump can't consume the entire
                // budget and prevent later resources from appearing in context.
                $content = mb_strlen($hit['content']) > $metaMaxChars
                    ? mb_substr($hit['content'], 0, $metaMaxChars).'…'
                    : $hit['content'];
            } else {
                $page = isset($hit['page_number']) ? ", page {$hit['page_number']}" : '';
                $heading = "[Source: {$name}{$page}]";
                $content = $hit['content'];
            }

            $block = "{$heading}\n{$content}";

            if ($total + strlen($block) > $maxChars) {
                $truncated = true;
                break;
            }

            $parts[] = $block;
            $total += strlen($block) + 2; // +2 for the separator
        }

        return ['context' => implode("\n\n", $parts), 'truncated' => $truncated];
    }

    /**
     * Prepend any meta chunks not already present in $knnHits.
     * Meta chunks are placed first so they are never truncated by the char budget
     * when the LLM needs to answer identification/metadata questions.
     */
    private function mergeMetaChunks(array $metaChunks, array $knnHits): array
    {
        $existing = array_flip(array_column($knnHits, 'chunk_id'));
        $novel = array_values(array_filter($metaChunks, fn ($c) => ! isset($existing[$c['chunk_id']])));

        return array_merge($novel, $knnHits);
    }

    private function buildSources(array $hits, $resourceNames, string $answer = ''): array
    {
        // ── Tier 1: text-chunk citations (k-NN ranked, page-level) ──────────────
        $textSources = [];

        foreach ($hits as $hit) {
            if (str_starts_with($hit['chunk_id'], 'meta-')) {
                continue;
            }

            $rid = $hit['resource_id'];

            if (! isset($textSources[$rid])) {
                $textSources[$rid] = [
                    'resource_id' => $rid,
                    'resource_name' => $resourceNames[$rid] ?? null,
                    'file_id' => $hit['file_id'],
                    'pages' => [],
                ];
            }

            if (isset($hit['page_number'])) {
                $textSources[$rid]['pages'][] = $hit['page_number'];
            }
        }

        // ── Tier 2: answer-confirmed citations ───────────────────────────────────
        // When the LLM names a resource verbatim in its answer (e.g. after using a
        // meta-only chunk like an image with just a tag), add that resource as a
        // citation even if it had no text-chunk hits. This covers the case where a
        // metadata-only resource (no PDF, just tags/description) is the correct answer
        // but was absent from the k-NN ranking.
        $answerSources = [];

        if ($answer) {
            foreach ($resourceNames as $rid => $name) {
                if ($name && mb_strlen($name) > 2 && mb_stripos($answer, $name) !== false) {
                    $metaFileId = '';
                    foreach ($hits as $hit) {
                        if ($hit['resource_id'] === $rid && str_starts_with($hit['chunk_id'], 'meta-')) {
                            $metaFileId = $hit['file_id'];
                            break;
                        }
                    }

                    $answerSources[$rid] = [
                        'resource_id' => $rid,
                        'resource_name' => $name,
                        'file_id' => $textSources[$rid]['file_id'] ?? $metaFileId,
                        'pages' => $textSources[$rid]['pages'] ?? [],
                    ];
                }
            }
        }

        // Prefer answer-confirmed citations when available — they reflect what the
        // LLM actually used. Fall back to text-chunk-based when the LLM answer does
        // not name any resource explicitly (e.g. open-ended summaries).
        $byResource = ! empty($answerSources) ? $answerSources : $textSources;

        foreach ($byResource as &$entry) {
            $entry['pages'] = array_values(array_unique($entry['pages']));
            sort($entry['pages']);
        }
        unset($entry);

        return array_values($byResource);
    }
}
