<?php

namespace Tests\Integration;

use App\Models\SearchIndex;
use App\Services\ElasticsearchService;
use Elastic\Elasticsearch\ClientInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use ReflectionProperty;
use Tests\TestCase;

/**
 * #13 against a live Elasticsearch: documents whose Tika metadata disagrees
 * on a key's type, or carries more keys than the field limit, still index.
 * Only ever touches throwaway indices under the test prefix.
 */
class TikaMetadataIndexingTest extends TestCase
{
    use RefreshDatabase;

    private ElasticsearchService $es;

    private ClientInterface $client;

    private SearchIndex $searchIndex;

    private string $physical;

    protected function setUp(): void
    {
        parent::setUp();

        $this->es = app(ElasticsearchService::class);
        if ($this->es->indexPrefix() === '') {
            $this->markTestSkipped('Refusing to create indices without an index prefix (phpunit.xml sets test_).');
        }

        $this->client = (new ReflectionProperty($this->es, 'client'))->getValue($this->es);
        try {
            $this->client->info();
        } catch (\Throwable) {
            $this->markTestSkipped('Elasticsearch is not reachable.');
        }

        $this->searchIndex = SearchIndex::create([
            'index_name' => 'tydal_tika_'.strtolower(Str::random(10)),
            'display_name' => 'Tika collision test',
            'is_active' => true,
        ]);
        $this->physical = $this->es->physicalIndexName($this->searchIndex->index_name);
    }

    protected function tearDown(): void
    {
        try {
            if (isset($this->physical)) {
                $this->es->deleteIndex($this->physical);
            }
        } finally {
            parent::tearDown();
        }
    }

    public function test_conflicting_and_exploding_tika_keys_still_index(): void
    {
        $this->es->setupIndex($this->searchIndex);

        $documents = [
            'a' => ['xmpMM:History:When' => ['2024-01-02T10:00:00+01:00', '2024-01-03T11:00:00+01:00']],
            'b' => ['xmpMM:History:When' => 'yesterday, roughly'],
            // Past the default 1000-field limit on its own
            'c' => collect(range(1, 1200))->mapWithKeys(fn ($i) => ["xmp:Key{$i}" => "v{$i}"])->all(),
        ];

        foreach ($documents as $id => $tika) {
            $this->client->index([
                'index' => $this->physical,
                'id' => $id,
                'body' => ['id' => $id, 'name' => "doc {$id}", 'tika_metadata' => $tika, 'relation' => ['name' => 'resource']],
            ]);
        }
        $this->es->refreshIndex($this->physical);

        $this->assertSame(3, $this->client->count(['index' => $this->physical])->asArray()['count']);

        // Kept in _source, not indexed
        $source = $this->client->get(['index' => $this->physical, 'id' => 'b'])->asArray()['_source'];
        $this->assertSame('yesterday, roughly', $source['tika_metadata']['xmpMM:History:When']);
        $this->assertTrue($this->es->tikaMetadataDisabled($this->physical));
    }

    public function test_setup_on_a_legacy_dynamic_index_stays_additive_until_recreated(): void
    {
        // An index created before the fix: tika_metadata dynamically mapped
        $this->client->indices()->create([
            'index' => $this->physical,
            'body' => ['mappings' => ['properties' => ['tika_metadata' => ['type' => 'object', 'dynamic' => true]]]],
        ]);

        $this->es->setupIndex($this->searchIndex); // must not throw on the `enabled` flip
        $this->assertFalse($this->es->tikaMetadataDisabled($this->physical));

        $this->es->setupIndex($this->searchIndex, recreate: true);
        $this->assertTrue($this->es->tikaMetadataDisabled($this->physical));
    }
}
