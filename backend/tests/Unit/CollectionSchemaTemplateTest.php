<?php

namespace Tests\Unit;

use App\Models\CollectionScheme;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for the CollectionScheme model.
 * (Replaces the old CollectionSchemaTemplateTest.)
 */
class CollectionSchemaTemplateTest extends TestCase
{
    use RefreshDatabase;

    private CollectionScheme $scheme;

    protected function setUp(): void
    {
        parent::setUp();

        $this->scheme = CollectionScheme::create([
            'name' => 'test',
            'display_name' => 'Test Scheme',
            'is_system' => false,
            'fields' => [
                ['name' => 'title',       'display_name' => 'Title',       'type' => 'string',
                    'required' => true,  'storage' => 'metadata', 'is_facet' => false,
                    'display_in_form' => true, 'order' => 1, 'es_type' => 'text'],
                ['name' => 'language',    'display_name' => 'Language',    'type' => 'select',
                    'required' => false, 'storage' => 'metadata', 'is_facet' => true,
                    'facet_label' => 'Language', 'facet_order' => 1,
                    'display_in_form' => true, 'order' => 2, 'es_type' => 'keyword'],
                ['name' => 'description', 'display_name' => 'Description', 'type' => 'text',
                    'required' => true,  'storage' => 'column',   'is_facet' => false,
                    'display_in_form' => true, 'order' => 0, 'es_type' => 'text'],
            ],
        ]);
    }

    public function test_fields_cast_to_array(): void
    {
        $this->assertIsArray($this->scheme->fields);
        $this->assertCount(3, $this->scheme->fields);
    }

    public function test_get_field_map_returns_name_keyed_map(): void
    {
        $map = $this->scheme->getFieldMap();
        $this->assertArrayHasKey('title', $map);
        $this->assertArrayHasKey('language', $map);
        $this->assertEquals('text', $map['title']['es_type']);
    }

    public function test_get_facet_fields_returns_only_facets(): void
    {
        $facets = $this->scheme->getFacetFields();
        $this->assertCount(1, $facets);
        $this->assertEquals('language', $facets[0]['name']);
    }

    public function test_get_required_fields_returns_required_only(): void
    {
        $required = $this->scheme->getRequiredFields();
        $names = array_column($required, 'name');
        $this->assertContains('title', $names);
        $this->assertContains('description', $names);
        $this->assertNotContains('language', $names);
    }

    public function test_get_field_by_name(): void
    {
        $field = $this->scheme->getFieldByName('language');
        $this->assertNotNull($field);
        $this->assertEquals('select', $field['type']);

        $this->assertNull($this->scheme->getFieldByName('nonexistent'));
    }

    public function test_system_scope(): void
    {
        CollectionScheme::create([
            'name' => 'sys',
            'display_name' => 'System',
            'is_system' => true,
            'fields' => [],
        ]);

        $this->assertCount(1, CollectionScheme::system()->get());
        $this->assertCount(1, CollectionScheme::custom()->get());
    }
}
