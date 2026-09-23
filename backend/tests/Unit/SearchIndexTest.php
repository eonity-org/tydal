<?php

namespace Tests\Unit;

use App\Models\SearchIndex;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for the SearchIndex model.
 * (Replaces the old SolrCoreSchemaTest.)
 */
class SearchIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_search_index_can_be_created(): void
    {
        $index = SearchIndex::create([
            'index_name' => 'tydal_test',
            'display_name' => 'Test Index',
            'is_active' => true,
        ]);

        $this->assertNotNull($index->id);
        $this->assertEquals('tydal_test', $index->index_name);
    }

    public function test_is_active_is_cast_to_boolean(): void
    {
        $index = SearchIndex::create([
            'index_name' => 'bool_test',
            'display_name' => 'Bool Test',
            'is_active' => true,
        ]);

        $this->assertIsBool($index->is_active);
        $this->assertTrue($index->is_active);
    }

    public function test_index_mappings_cast_to_array(): void
    {
        $mappings = ['properties' => ['title' => ['type' => 'text']]];
        $index = SearchIndex::create([
            'index_name' => 'mappings_test',
            'display_name' => 'Mappings Test',
            'index_mappings' => $mappings,
            'is_active' => true,
        ]);

        $this->assertIsArray($index->index_mappings);
        $this->assertArrayHasKey('properties', $index->index_mappings);
    }

    public function test_active_scope(): void
    {
        SearchIndex::create(['index_name' => 'active_1',   'display_name' => 'A', 'is_active' => true]);
        SearchIndex::create(['index_name' => 'inactive_1', 'display_name' => 'B', 'is_active' => false]);

        $active = SearchIndex::active()->get();
        $this->assertCount(1, $active);
        $this->assertEquals('active_1', $active->first()->index_name);
    }
}
