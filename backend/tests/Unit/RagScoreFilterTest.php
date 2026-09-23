<?php

namespace Tests\Unit;

use App\Services\ElasticsearchService;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * applyRagScoreFilters — the shared RAG context relevance cutoff (absolute
 * floor + relative ratio). Applied by the ask heads at context-assembly time;
 * the raw retrieval surfaces (vault /search?scope=chunks) skip it by design.
 */
class RagScoreFilterTest extends TestCase
{
    private ElasticsearchService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ElasticsearchService;
        Config::set('elasticsearch.rag_min_score', 0.72);
        Config::set('elasticsearch.rag_score_ratio', 0.88);
    }

    private function chunk(float $score, string $content = 'passage'): array
    {
        return ['content' => $content, 'score' => $score];
    }

    public function test_absolute_floor_drops_low_scores(): void
    {
        $result = $this->service->applyRagScoreFilters([
            $this->chunk(0.90, 'strong'),
            $this->chunk(0.71, 'below floor'),
        ]);

        $this->assertCount(1, $result);
        $this->assertSame('strong', $result[0]['content']);
    }

    public function test_ratio_filter_trims_stragglers_relative_to_best(): void
    {
        // 0.95 × 0.88 = 0.836 — 0.80 passes the floor but not the ratio
        $result = $this->service->applyRagScoreFilters([
            $this->chunk(0.95, 'best'),
            $this->chunk(0.90, 'close'),
            $this->chunk(0.80, 'straggler'),
        ]);

        $this->assertSame(['best', 'close'], array_column($result, 'content'));
    }

    public function test_all_below_floor_returns_empty(): void
    {
        $this->assertSame([], $this->service->applyRagScoreFilters([
            $this->chunk(0.50),
            $this->chunk(0.40),
        ]));
    }

    public function test_empty_input_returns_empty(): void
    {
        $this->assertSame([], $this->service->applyRagScoreFilters([]));
    }

    public function test_scores_survive_into_the_output(): void
    {
        $result = $this->service->applyRagScoreFilters([$this->chunk(0.9)]);

        $this->assertSame(0.9, $result[0]['score']);
    }

    public function test_min_score_override_replaces_the_config_floor(): void
    {
        // 0.60 fails the 0.72 config floor but passes an owner floor of 0.5
        $chunks = [$this->chunk(0.60, 'borderline')];

        $this->assertSame([], $this->service->applyRagScoreFilters($chunks));
        $this->assertCount(1, $this->service->applyRagScoreFilters($chunks, 0.5));

        // …and a stricter owner floor drops what the config would keep
        $this->assertSame([], $this->service->applyRagScoreFilters([$this->chunk(0.8)], 0.9));
    }
}
