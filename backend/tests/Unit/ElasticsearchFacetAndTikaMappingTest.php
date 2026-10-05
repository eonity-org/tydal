<?php

namespace Tests\Unit;

use App\Services\ElasticsearchService;
use Elastic\Elasticsearch\ClientInterface;
use Mockery;
use ReflectionProperty;
use Tests\TestCase;

/**
 * #13 — Tika's raw metadata is kept in _source but never indexed (its keys
 * collided across files and blew the field limit), and text facets aggregate
 * on their `.keyword` subfield (aggregating the analysed text field failed
 * with "Fielddata is disabled", pushing workspace views onto the DB fallback).
 */
class ElasticsearchFacetAndTikaMappingTest extends TestCase
{
    private ElasticsearchService $service;

    private mixed $mockClient;

    private const AUTHOR = [
        'name' => 'author', 'type' => 'string', 'storage' => 'metadata', 'is_facet' => true,
        'es_type' => 'text', 'es_fields' => ['keyword' => ['type' => 'keyword', 'ignore_above' => 256]],
    ];

    private const LANGUAGE = [
        'name' => 'language', 'type' => 'string', 'storage' => 'metadata', 'is_facet' => true,
        'es_type' => 'keyword',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new ElasticsearchService;
        $this->mockClient = Mockery::mock(ClientInterface::class);
        (new ReflectionProperty(ElasticsearchService::class, 'client'))->setValue($this->service, $this->mockClient);
    }

    public function test_tika_metadata_is_stored_but_not_indexed(): void
    {
        $mapping = $this->service->buildMappings(null);

        $this->assertSame(['type' => 'object', 'enabled' => false], $mapping['properties']['tika_metadata']);
    }

    public function test_text_facets_resolve_to_the_keyword_subfield(): void
    {
        $this->assertSame('metadata.author.keyword', $this->service->facetFieldPath(self::AUTHOR));
        $this->assertSame('metadata.language', $this->service->facetFieldPath(self::LANGUAGE));
        $this->assertSame('type', $this->service->facetFieldPath(['name' => 'type', 'is_facet' => true]));
        // No es_type = text; a text facet always gets a keyword subfield
        $this->assertSame('metadata.genre.keyword', $this->service->facetFieldPath(['name' => 'genre', 'is_facet' => true]));
    }

    public function test_workspace_search_aggregates_and_filters_on_keyword_fields(): void
    {
        $captured = null;
        $this->mockClient->shouldReceive('search')->once()
            ->withArgs(function (array $params) use (&$captured): bool {
                $captured = $params;

                return true;
            })
            ->andReturn($this->emptyResponse());

        $this->service->searchByWorkspace(
            ['tydal_photo_exhibition'], 7, '',
            ['author' => ['Ana Pérez'], 'language' => ['es']],
            [self::AUTHOR, self::LANGUAGE],
            1, 20
        );

        $aggs = $captured['body']['aggs'];
        $this->assertSame('metadata.author.keyword', $aggs['author']['terms']['field']);
        $this->assertSame('metadata.language', $aggs['language']['terms']['field']);

        $filter = $captured['body']['query']['bool']['filter'];
        $this->assertContains(['terms' => ['metadata.author.keyword' => ['Ana Pérez']]], $filter);
        $this->assertContains(['terms' => ['metadata.language' => ['es']]], $filter);
    }

    public function test_collection_search_aggregates_on_keyword_fields(): void
    {
        $captured = null;
        $this->mockClient->shouldReceive('search')->once()
            ->withArgs(function (array $params) use (&$captured): bool {
                $captured = $params;

                return true;
            })
            ->andReturn($this->emptyResponse());

        $this->service->searchResources('tydal_photo_exhibition', 1, '', [], [self::AUTHOR], 1, 20);

        $this->assertSame('metadata.author.keyword', $captured['body']['aggs']['author']['terms']['field']);
    }

    private function emptyResponse(): object
    {
        return new class
        {
            public function asArray(): array
            {
                return ['hits' => ['hits' => [], 'total' => ['value' => 0]], 'aggregations' => []];
            }
        };
    }
}
