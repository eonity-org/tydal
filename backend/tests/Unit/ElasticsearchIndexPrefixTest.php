<?php

namespace Tests\Unit;

use App\Models\Vault;
use App\Services\ElasticsearchService;
use Elastic\Elasticsearch\ClientInterface;
use Mockery;
use ReflectionProperty;
use Tests\TestCase;

/**
 * #14 — every physical index name goes through one prefix
 * (ELASTICSEARCH_INDEX_PREFIX), so installations sharing a cluster never read,
 * write or wipe each other's indices. Empty prefix = the legacy names.
 */
class ElasticsearchIndexPrefixTest extends TestCase
{
    private ElasticsearchService $service;

    private mixed $mockClient;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new ElasticsearchService;
        $this->mockClient = Mockery::mock(ClientInterface::class);
        (new ReflectionProperty(ElasticsearchService::class, 'client'))->setValue($this->service, $this->mockClient);
    }

    private function vault(): Vault
    {
        $vault = new Vault;
        $vault->id = '0198C0DE-0000-7000-8000-000000000001';

        return $vault;
    }

    public function test_names_are_unchanged_when_the_prefix_is_empty(): void
    {
        config(['elasticsearch.index_prefix' => '']);

        $this->assertSame('tydal_multimedia', $this->service->physicalIndexName('tydal_multimedia'));
        $this->assertSame('tydal_multimedia_chunks', $this->service->buildChunksIndexName('tydal_multimedia'));
        $this->assertSame('vault_0198c0de-0000-7000-8000-000000000001', $this->service->buildVaultIndexName($this->vault()));
        $this->assertSame('tydal_*,vault_*', $this->service->wipePattern());
    }

    public function test_every_physical_name_carries_the_prefix(): void
    {
        config(['elasticsearch.index_prefix' => 'prod_']);

        $this->assertSame('prod_tydal_multimedia', $this->service->physicalIndexName('tydal_multimedia'));
        $this->assertSame('prod_tydal_multimedia_chunks', $this->service->buildChunksIndexName('tydal_multimedia'));
        $this->assertSame('prod_vault_0198c0de-0000-7000-8000-000000000001', $this->service->buildVaultIndexName($this->vault()));
    }

    public function test_wipe_pattern_is_scoped_to_the_prefix(): void
    {
        config(['elasticsearch.index_prefix' => 'staging_']);

        $this->assertSame('staging_tydal_*,staging_vault_*', $this->service->wipePattern());
    }

    public function test_prefixes_that_could_widen_a_pattern_are_refused(): void
    {
        foreach (['*', 'a,b', 'Prod_', '_x', 'a b', 'tydal_prod_', 'vault_x'] as $bad) {
            config(['elasticsearch.index_prefix' => $bad]);

            try {
                $this->service->physicalIndexName('tydal_multimedia');
                $this->fail("prefix '{$bad}' should have been refused");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_search_methods_address_the_prefixed_indices(): void
    {
        config(['elasticsearch.index_prefix' => 'prod_']);

        $indices = [];
        $this->mockClient->shouldReceive('search')
            ->withArgs(function (array $params) use (&$indices): bool {
                $indices[] = $params['index'];

                return true;
            })
            ->andReturn(new class
            {
                public function asArray(): array
                {
                    return ['hits' => ['hits' => [], 'total' => ['value' => 0]], 'aggregations' => []];
                }
            });

        $this->service->searchResources('tydal_multimedia', 1, '', [], [], 1, 20);
        $this->service->searchByWorkspace(['tydal_multimedia', 'tydal_documents'], 5, '', [], [], 1, 20);
        $this->service->knnSearchResources(['tydal_multimedia'], [0.1], 5, 'org-id');

        $this->assertSame([
            'prod_tydal_multimedia',
            'prod_tydal_multimedia,prod_tydal_documents',
            'prod_tydal_multimedia',
        ], $indices);
    }

    public function test_vault_sweeps_only_reach_this_installations_vault_indices(): void
    {
        config(['elasticsearch.index_prefix' => 'prod_']);

        $patterns = [];
        $this->mockClient->shouldReceive('deleteByQuery')
            ->twice()
            ->withArgs(function (array $params) use (&$patterns): bool {
                $patterns[] = $params['index'];

                return true;
            });

        $this->service->removeResourceFromVaultIndexes('r1');
        $this->service->pruneResourceFromUnreachableVaultIndexes('r1', []);

        $this->assertSame(['prod_vault_*', 'prod_vault_*'], $patterns);
    }

    public function test_wipe_command_deletes_only_the_scoped_pattern(): void
    {
        config(['elasticsearch.index_prefix' => 'prod_']);

        $mock = Mockery::mock(ElasticsearchService::class)->makePartial();
        $mock->shouldReceive('deleteIndicesMatching')
            ->once()
            ->with('prod_tydal_*,prod_vault_*')
            ->andReturn(['prod_tydal_multimedia']);
        $this->instance(ElasticsearchService::class, $mock);

        $this->artisan('search:wipe-indices --force')
            ->expectsOutputToContain('Wiped 1 index(es): prod_tydal_multimedia')
            ->assertExitCode(0);
    }

    public function test_wipe_command_warns_when_unprefixed(): void
    {
        config(['elasticsearch.index_prefix' => '']);

        $mock = Mockery::mock(ElasticsearchService::class)->makePartial();
        $mock->shouldReceive('deleteIndicesMatching')->once()->with('tydal_*,vault_*')->andReturn([]);
        $this->instance(ElasticsearchService::class, $mock);

        $this->artisan('search:wipe-indices --force')
            ->expectsOutputToContain('ELASTICSEARCH_INDEX_PREFIX is empty')
            ->assertExitCode(0);
    }
}
