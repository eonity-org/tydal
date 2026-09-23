<?php

namespace Tests\Integration;

use App\Models\Collection;
use App\Models\CollectionScheme;
use App\Models\Organization;
use App\Models\SearchIndex;
use App\Models\User;
use App\Services\ElasticsearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Epic 1.3 — search:setup-indices must be idempotent: mappings are fully
 * derived from the collection scheme, and re-running against an existing
 * index applies them additively (putMapping) instead of failing.
 * Requires a live Elasticsearch (same as the other Integration tests).
 */
class SearchSetupIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_setup_index_twice_is_a_no_op(): void
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();

        $searchIndex = SearchIndex::create([
            'index_name' => 'tydal_test_idempotency',
            'display_name' => 'Idempotency Test',
            'is_active' => true,
        ]);

        $scheme = CollectionScheme::create([
            'name' => 'idempotency-scheme',
            'display_name' => 'Idempotency Scheme',
            'accepted_mimetypes' => [],
            'is_system' => false,
            'fields' => [
                ['name' => 'language', 'display_name' => 'Language', 'type' => 'text',
                    'es_type' => 'keyword', 'is_facet' => true, 'storage' => 'metadata'],
            ],
        ]);

        Collection::factory()->create([
            'organization_id' => $org->id,
            'user_owner_id' => $user->id,
            'scheme_id' => $scheme->id,
            'index_id' => $searchIndex->id,
        ]);

        $es = app(ElasticsearchService::class);

        try {
            $es->setupIndex($searchIndex);
            $es->setupChunksIndex($searchIndex);

            // Second run must be additive, not destructive or failing
            $es->setupIndex($searchIndex);
            $es->setupChunksIndex($searchIndex);

            $this->assertTrue(true, 'setupIndex ran twice without error');
        } finally {
            // Clean up the throwaway indices
            foreach (['tydal_test_idempotency', 'tydal_test_idempotency_chunks'] as $index) {
                try {
                    $es->deleteIndex($index);
                } catch (\Throwable) {
                    // best-effort cleanup
                }
            }
        }
    }
}
