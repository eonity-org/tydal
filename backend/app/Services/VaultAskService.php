<?php

namespace App\Services;

use App\Models\Vault;
use App\Services\LLM\Contracts\LlmServiceInterface;
use App\Services\LLM\Contracts\StreamingLlmInterface;

/**
 * The vault's built-in ask head (Epic 4.4) — grounded Q&A over one vault's
 * projection, serving both the internal `/vaults/{id}/ask` endpoint and the
 * boundary `/v … /ask` form. The loop: detect vault context → resolve
 * overlay → retrieve embeddings → expand graph → respond. Persona-neutral by
 * design: consumer apps put their own face on it (the aity name belongs to
 * the internal curation assistant, not this surface).
 *
 * Guardrails by construction: every retrieval step goes through
 * VaultOperationService — the exact REST↔MCP 1:1 operation layer any
 * external client uses — so the answer can only draw on what the vault
 * projects (scope) and what its tier policy exposes (a chunk-less gallery
 * vault is answered from Tier 0 identity cards alone). No raw SQL, no
 * file-level reasoning: passages are cited as resource + page, never files.
 */
class VaultAskService
{
    /**
     * How many of the vault's own identity cards are guaranteed into the ask
     * context regardless of k-NN ranking (newest first). Keeps whole-vault
     * questions complete for small vaults without flooding large ones — the
     * context char budget still applies downstream.
     */
    private const CARD_GUARANTEE_CAP = 24;

    private const SYSTEM_PROMPT = <<<'PROMPT'
You are the assistant for one TYDAL vault — a curated projection of resources. Answer the user's question using ONLY the context provided below.

Context blocks:
- [Vault] — what this vault is: its purpose, size, and the meaning of its metadata fields (field → semantic slot). Use the slot semantics to interpret metadata values.
- [Source: <resource>, page N] — an extracted text passage from a resource.
- [Resource: <name> (<slug>)] — a resource's identity card: description, tags, metadata.
- [Related] — resources connected in the knowledge graph (with relation origin and strength).

If the answer cannot be found in the context, say so clearly — do not invent information. Cite resources by their name (and page number for text passages). Never mention internal identifiers.
PROMPT;

    public function __construct(
        private readonly VaultOperationService $ops,
        private readonly VaultLinkService $links,
        private readonly LlmServiceInterface $llm,
        private readonly ElasticsearchService $es,
    ) {}

    /**
     * @return array{answer: string, sources: array, vault: array{slug: ?string, name: string, purpose: string}, used: array{chunks: int, cards: int, related: int}}
     */
    public function ask(Vault $vault, string $question, int $k = 5): array
    {
        $prep = $this->prepare($vault, $question, $k);
        if ($prep === null) {
            return $this->emptyAnswer($vault);
        }

        $tq = hrtime(true);
        $answer = $this->llm->chat($this->messages($prep['context']['text'], $question));

        return $this->finalize($vault, $question, $prep, $answer, (int) round((hrtime(true) - $tq) / 1e6));
    }

    /**
     * Streaming variant of ask(): identical retrieval, context, and final
     * shape, but the answer is produced incrementally — `$onDelta($fragment)`
     * fires for each fragment as it arrives (once with the whole answer when
     * the driver can't stream). Returns the same array as ask().
     *
     * @param  callable(string): void  $onDelta
     * @return array{answer: string, sources: array, vault: array, used: array}
     */
    public function askStreamed(Vault $vault, string $question, int $k, callable $onDelta): array
    {
        $prep = $this->prepare($vault, $question, $k);
        if ($prep === null) {
            $empty = $this->emptyAnswer($vault);
            $onDelta($empty['answer']);

            return $empty;
        }

        $tq = hrtime(true);
        $messages = $this->messages($prep['context']['text'], $question);
        $answer = '';

        if ($this->llm instanceof StreamingLlmInterface) {
            foreach ($this->llm->chatStream($messages) as $delta) {
                $answer .= $delta;
                $onDelta($delta);
            }
        } else {
            $answer = $this->llm->chat($messages);
            $onDelta($answer);
        }

        return $this->finalize($vault, $question, $prep, $answer, (int) round((hrtime(true) - $tq) / 1e6));
    }

    /**
     * Shared prelude for both ask paths (steps 1–3): retrieve chunk passages +
     * identity cards through the tier-gated grammar, expand the graph, build
     * the LLM context. Returns null when nothing relevant matched — the caller
     * emits the canned "no content" answer.
     *
     * @return array{chunkHits: array, cardHits: array, related: array, context: array{text: string, truncated: bool}}|null
     */
    private function prepare(Vault $vault, string $question, int $k): ?array
    {
        $meta = $this->ops->vaultMeta($vault);

        $chunkPayload = $vault->allowsChunks()
            ? $this->ops->vaultSearchChunks($vault, $question, $k, 'semantic')
            : null;

        // The boundary serves raw scored hits (external reasoners pick their
        // own cutoff); context hygiene is THIS head's concern, so the RAG
        // relevance filters apply here, at assembly time — with the vault
        // owner's floor when the exposure policy sets one.
        $chunkHits = $this->es->applyRagScoreFilters($chunkPayload['results'] ?? [], $vault->ragMinScore());

        $cardPayload = $this->ops->vaultSearch($vault, $question, 1, $k, 'semantic');
        $cardHits = $cardPayload['results'] ?? [];

        // Normalize every hit to its vault-scoped id (the link hash) so all
        // downstream correlation — graph expansion, source dedup, name lookup —
        // shares one key space and no raw resource UUID travels to the client.
        // Chunk hits arrive from ES keyed by resource UUID; cards already carry
        // the link hash in `id`.
        foreach ($chunkHits as &$hit) {
            $hit['rid'] = $this->links->getOrCreateLink($vault, null, $hit['resource_id'], null)->hash;
        }
        unset($hit);
        $cardHits = array_map(fn (array $c) => $c + ['rid' => $c['id']], $cardHits);

        // Enumeration guarantee — parity with the internal head's
        // fetchMetaChunks*: merge the vault's own identity cards (newest first,
        // capped) so whole-vault questions ("summarize everything") see every
        // resource, not only what k-NN ranked. Resources with no embeddings
        // (e.g. images pending metadata) would otherwise be invisible here.
        // Guaranteed cards join the context but stay out of graph expansion
        // and citations — they are inventory, not evidence.
        $listing = $this->ops->vaultIndex($vault, 1, self::CARD_GUARANTEE_CAP)['resources'] ?? [];
        $rankedIds = array_flip(array_column($cardHits, 'id'));
        $guaranteed = [];
        foreach ($listing as $card) {
            if (! isset($rankedIds[$card['id']])) {
                $guaranteed[] = $card + ['rid' => $card['id']];
            }
        }

        if ($chunkHits === [] && $cardHits === [] && $guaranteed === []) {
            return null;
        }

        $related = $this->expandGraph($vault, $chunkHits, $cardHits);

        $contextCards = array_merge($cardHits, $guaranteed);

        $t0 = hrtime(true);
        $context = $this->buildContext($meta, $chunkHits, $contextCards, $related);
        AiActivityLogger::ragContextBuild(
            $vault->id, $question,
            $chunkHits, $chunkHits, $context['text'], false,
            (int) round((hrtime(true) - $t0) / 1e6),
        );

        return compact('chunkHits', 'cardHits', 'related', 'context', 'contextCards');
    }

    /** The system+context message pair sent to the LLM. */
    private function messages(string $contextText, string $question): array
    {
        return [
            ['role' => 'system', 'content' => self::SYSTEM_PROMPT],
            ['role' => 'user', 'content' => "Context:\n\n{$contextText}\n\nQuestion: {$question}"],
        ];
    }

    /** The canned response when the vault has nothing relevant to offer. */
    private function emptyAnswer(Vault $vault): array
    {
        return [
            'answer' => 'No relevant content found in this vault to answer your question.',
            'sources' => [],
            'vault' => ['slug' => $vault->slug, 'name' => $vault->name, 'purpose' => $vault->purpose->value],
            'used' => ['chunks' => 0, 'cards' => 0, 'related' => 0],
        ];
    }

    /** Step 4 tail: build sources, log, and assemble the final payload. */
    private function finalize(Vault $vault, string $question, array $prep, string $answer, int $ragDurationMs): array
    {
        $sources = $this->buildSources($vault, $prep['chunkHits'], $prep['cardHits']);

        AiActivityLogger::modelCall('ask-vault', $this->llm->getModel(), 'rag', 'completed', $ragDurationMs, mb_substr($question, 0, 120), null, mb_substr($answer, 0, 200));
        AiActivityLogger::ragQuery($vault->id, 'vault', $question, $answer, $sources, $ragDurationMs);

        return [
            'answer' => $answer,
            'sources' => $sources,
            'vault' => ['slug' => $vault->slug, 'name' => $vault->name, 'purpose' => $vault->purpose->value],
            'used' => ['chunks' => count($prep['chunkHits']), 'cards' => count($prep['contextCards']), 'related' => count($prep['related'])],
            'context_truncated' => $prep['context']['truncated'],
        ];
    }

    /**
     * Graph expansion through the vault's own /related operation — the
     * projection filter (out-of-vault neighbors never appear) comes free.
     *
     * @return list<array{of: string, name: mixed, slug: mixed, relation: mixed}>
     */
    private function expandGraph(Vault $vault, array $chunkHits, array $cardHits): array
    {
        $topHashes = collect($chunkHits)->pluck('rid')
            ->merge(collect($cardHits)->pluck('rid'))
            ->filter()
            ->unique()
            ->take(3);

        $related = [];
        $seen = [];

        foreach ($topHashes as $hash) {
            $link = $this->links->findLinkInVault($vault, $hash);

            if (! $link) {
                continue;
            }

            $payload = $this->ops->resourceRelated($vault, $link, 5);

            if (($payload['source'] ?? null) !== 'graph') {
                continue; // tag-overlap fallback adds noise here, not signal
            }

            foreach ($payload['resources'] as $card) {
                $key = $hash.'|'.$card['id'];
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;

                $related[] = [
                    'of' => $hash,
                    'name' => $card['name'],
                    'slug' => $card['slug'],
                    'relation' => $card['relation'],
                ];
            }
        }

        return array_slice($related, 0, 10);
    }

    /**
     * @return array{text: string, truncated: bool}
     */
    private function buildContext(array $meta, array $chunkHits, array $cardHits, array $related): array
    {
        $maxChars = (int) config('llm.max_context_chars', 40000);
        $parts = [];
        $total = 0;
        $truncated = false;

        $push = function (string $block) use (&$parts, &$total, &$truncated, $maxChars): bool {
            if ($total + strlen($block) > $maxChars) {
                $truncated = true;

                return false;
            }
            $parts[] = $block;
            $total += strlen($block) + 2;

            return true;
        };

        // [Vault] — purpose, size, and the resolved semantic mapping
        $semantics = [];
        foreach ($meta['presentation'] as $block) {
            foreach ($block['fields'] as $field => $slot) {
                if ($slot !== 'hidden' && ! isset($semantics[$field])) {
                    $semantics[$field] = $slot;
                }
            }
        }
        $semanticsLine = $semantics === []
            ? ''
            : "\nField semantics: ".collect($semantics)->map(fn ($slot, $field) => "{$field} → {$slot}")->implode(', ');
        $push(sprintf(
            "[Vault]\n%s — purpose: %s, %d resources.%s",
            $meta['name'],
            $meta['purpose'],
            $meta['resource_count'],
            $semanticsLine,
        ));

        // [Source] — Tier 1 passages, evidence first
        foreach ($chunkHits as $hit) {
            $page = isset($hit['page_number']) ? ", page {$hit['page_number']}" : '';
            if (! $push("[Source: {$hit['resource_name']}{$page}]\n{$hit['content']}")) {
                break;
            }
        }

        // [Resource] — Tier 0 identity cards (hybrid: metadata evidence)
        foreach ($cardHits as $card) {
            $lines = ["[Resource: {$card['name']} ({$card['slug']})]"];
            if (! empty($card['description'])) {
                $lines[] = (string) $card['description'];
            }
            if (! empty($card['tags'])) {
                $lines[] = 'Tags: '.implode(', ', $card['tags']);
            }
            foreach ($card['metadata'] ?? [] as $field => $value) {
                if ($value !== null && $value !== '' && $value !== []) {
                    $lines[] = "{$field}: ".(is_array($value) ? implode(', ', $value) : $value);
                }
            }
            if (! $push(implode("\n", $lines))) {
                break;
            }
        }

        // [Related] — graph neighborhood of the top hits
        if ($related !== []) {
            $lines = ['[Related]'];
            $namesById = collect($cardHits)->pluck('name', 'rid')
                ->merge(collect($chunkHits)->pluck('resource_name', 'rid'));
            foreach ($related as $edge) {
                $lines[] = sprintf(
                    '%s ↔ %s (%s%s)',
                    $namesById[$edge['of']] ?? 'a top hit',
                    $edge['name'],
                    $edge['relation']['origin'],
                    $edge['relation']['weight'] !== null ? ', '.$edge['relation']['weight'] : '',
                );
            }
            $push(implode("\n", $lines));
        }

        return ['text' => implode("\n\n", $parts), 'truncated' => $truncated];
    }

    /**
     * Vault-relative citations: resource slug/name (+ pages for passages).
     * Deliberately NO file identifiers — the reasoning stays at resource
     * level (guardrail).
     */
    private function buildSources(Vault $vault, array $chunkHits, array $cardHits): array
    {
        $sources = [];

        foreach ($chunkHits as $hit) {
            $rid = $hit['rid']; // vault-scoped link hash, never the resource UUID
            if (! isset($sources[$rid])) {
                $link = $this->links->getOrCreateLink($vault, null, $hit['resource_id'], null);
                $link->setRelation('vault', $vault);
                $sources[$rid] = [
                    'resource_id' => $rid,
                    'resource_name' => $hit['resource_name'],
                    'slug' => $link->slug,
                    'url' => $this->links->buildUrl($link),
                    'pages' => [],
                ];
            }
            if (isset($hit['page_number'])) {
                $sources[$rid]['pages'][] = $hit['page_number'];
            }
        }

        foreach ($cardHits as $card) {
            $sources[$card['rid']] ??= [
                'resource_id' => $card['rid'],
                'resource_name' => $card['name'],
                'slug' => $card['slug'],
                'url' => $card['url'] ?? null,
                'pages' => [],
            ];
        }

        foreach ($sources as &$entry) {
            $entry['pages'] = array_values(array_unique($entry['pages']));
            sort($entry['pages']);
        }
        unset($entry);

        return array_values($sources);
    }
}
