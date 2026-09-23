<?php

namespace Tests\Integration;

use App\Services\ElasticsearchService;
use Elastic\Elasticsearch\ClientInterface;
use Illuminate\Support\Str;
use ReflectionProperty;
use Tests\TestCase;

/**
 * Live ES regression: vault documents expose hashes in `id`, but upkeep
 * addresses their internal `_id`. These fixtures never touch the database.
 */
class VaultIndexCleanupTest extends TestCase
{
    private ElasticsearchService $es;

    private ClientInterface $client;

    private string $resourceId;

    private string $otherResourceId;

    private array $indexes = [];

    private array $createdIndexes = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->es = app(ElasticsearchService::class);
        $this->client = (new ReflectionProperty($this->es, 'client'))->getValue($this->es);
        $this->resourceId = (string) Str::uuid();
        $this->otherResourceId = (string) Str::uuid();
        $suffix = str_replace('-', '', (string) Str::uuid());
        $this->indexes = [
            'base' => 'test_collection_cleanup_'.$suffix,
            'first' => 'vault_test_cleanup_'.$suffix.'_first',
            'second' => 'vault_test_cleanup_'.$suffix.'_second',
        ];

        foreach ($this->indexes as $role => $index) {
            $this->client->indices()->create([
                'index' => $index,
                'body' => [
                    'settings' => ['number_of_shards' => 1, 'number_of_replicas' => 0],
                    'mappings' => ['properties' => ['id' => ['type' => 'keyword']]],
                ],
            ]);
            $this->createdIndexes[] = $index;

            foreach ([$this->resourceId, $this->otherResourceId] as $id) {
                $this->client->index([
                    'index' => $index,
                    'id' => $id,
                    'body' => ['id' => $role === 'base' ? $id : Str::random(12)],
                ]);
            }

            $this->es->refreshIndex($index);
            $this->assertDocuments($role, [$this->resourceId, $this->otherResourceId]);
        }
    }

    protected function tearDown(): void
    {
        try {
            foreach ($this->createdIndexes as $index) {
                $this->es->deleteIndex($index);
            }
        } finally {
            parent::tearDown();
        }
    }

    public function test_pruning_preserves_the_collection_document_and_still_reachable_vault(): void
    {
        $this->es->pruneResourceFromUnreachableVaultIndexes($this->resourceId, [$this->indexes['second']]);

        $this->assertDocuments('base', [$this->resourceId, $this->otherResourceId]);
        $this->assertDocuments('second', [$this->resourceId, $this->otherResourceId]);
        $this->assertDocuments('first', [$this->otherResourceId]);
    }

    public function test_pruning_without_remaining_vaults_preserves_the_collection_document(): void
    {
        $this->es->pruneResourceFromUnreachableVaultIndexes($this->resourceId, []);

        $this->assertDocuments('base', [$this->resourceId, $this->otherResourceId]);
        $this->assertDocuments('first', [$this->otherResourceId]);
        $this->assertDocuments('second', [$this->otherResourceId]);
    }

    public function test_deletion_cleanup_removes_only_the_target_from_all_vault_indexes(): void
    {
        // The resource deletion job handles the collection separately. This
        // helper must only remove the vault projections, including on retries.
        $this->es->removeResourceFromVaultIndexes($this->resourceId);
        $this->es->removeResourceFromVaultIndexes($this->resourceId);

        $this->assertDocuments('base', [$this->resourceId, $this->otherResourceId]);
        $this->assertDocuments('first', [$this->otherResourceId]);
        $this->assertDocuments('second', [$this->otherResourceId]);
    }

    private function assertDocuments(string $role, array $expectedIds): void
    {
        $index = $this->indexes[$role];
        $this->es->refreshIndex($index);
        $response = $this->client->search([
            'index' => $index,
            'body' => ['query' => ['match_all' => new \stdClass], '_source' => false],
        ])->asArray();

        $this->assertEqualsCanonicalizing($expectedIds, array_column($response['hits']['hits'], '_id'), $role);
    }
}
