<?php

namespace Tests\Unit;

use App\Models\Collection;
use App\Models\CollectionScheme;
use App\Models\Organization;
use App\Models\SearchIndex;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CollectionSchemaTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Organization $organization;

    private CollectionScheme $scheme;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->organization = Organization::factory()->create();
        $this->scheme = CollectionScheme::create([
            'name' => 'test_scheme',
            'display_name' => 'Test Scheme',
            'is_system' => false,
            'fields' => [
                [
                    'name' => 'title',
                    'display_name' => 'Title',
                    'type' => 'string',
                    'required' => true,
                    'storage' => 'metadata',
                    'is_facet' => false,
                    'display_in_form' => true,
                    'order' => 1,
                    'validators' => ['min_length' => 3, 'max_length' => 255],
                    'es_type' => 'text',
                ],
                [
                    'name' => 'description',
                    'display_name' => 'Description',
                    'type' => 'text',
                    'required' => true,
                    'storage' => 'metadata',
                    'is_facet' => false,
                    'display_in_form' => true,
                    'order' => 2,
                    'validators' => ['min_length' => 10],
                    'es_type' => 'text',
                ],
                [
                    'name' => 'tags',
                    'display_name' => 'Tags',
                    'type' => 'array',
                    'required' => false,
                    'storage' => 'metadata',
                    'is_facet' => false,
                    'display_in_form' => true,
                    'order' => 3,
                    'validators' => null,
                    'es_type' => 'keyword',
                ],
                [
                    'name' => 'author',
                    'display_name' => 'Author',
                    'type' => 'string',
                    'required' => false,
                    'storage' => 'metadata',
                    'is_facet' => false,
                    'display_in_form' => true,
                    'order' => 4,
                    'validators' => null,
                    'es_type' => 'keyword',
                ],
            ],
        ]);
    }

    public function test_collection_belongs_to_scheme(): void
    {
        $collection = Collection::factory()->create([
            'organization_id' => $this->organization->id,
            'user_owner_id' => $this->user->id,
            'scheme_id' => $this->scheme->id,
        ]);

        $this->assertInstanceOf(CollectionScheme::class, $collection->scheme);
        $this->assertEquals($this->scheme->id, $collection->scheme->id);
    }

    public function test_collection_belongs_to_search_index(): void
    {
        $index = SearchIndex::create([
            'index_name' => 'test_index',
            'display_name' => 'Test Index',
            'is_active' => true,
        ]);

        $collection = Collection::factory()->create([
            'organization_id' => $this->organization->id,
            'user_owner_id' => $this->user->id,
            'index_id' => $index->id,
        ]);

        $this->assertInstanceOf(SearchIndex::class, $collection->searchIndex);
        $this->assertEquals($index->id, $collection->searchIndex->id);
    }

    public function test_get_effective_schema_returns_scheme_fields(): void
    {
        $collection = Collection::factory()->create([
            'organization_id' => $this->organization->id,
            'user_owner_id' => $this->user->id,
            'scheme_id' => $this->scheme->id,
        ]);

        $fields = $collection->getEffectiveSchema();

        $this->assertIsArray($fields);
        $this->assertCount(4, $fields);
        $this->assertEquals('title', $fields[0]['name']);
    }

    public function test_get_effective_schema_returns_null_when_no_scheme(): void
    {
        $collection = Collection::factory()->create([
            'organization_id' => $this->organization->id,
            'user_owner_id' => $this->user->id,
            'scheme_id' => null,
        ]);

        $this->assertNull($collection->getEffectiveSchema());
    }

    public function test_get_required_fields_returns_names_of_required_fields(): void
    {
        $collection = Collection::factory()->create([
            'organization_id' => $this->organization->id,
            'user_owner_id' => $this->user->id,
            'scheme_id' => $this->scheme->id,
        ]);

        $required = $collection->getRequiredFields();

        $this->assertIsArray($required);
        $this->assertContains('title', $required);
        $this->assertContains('description', $required);
        $this->assertNotContains('tags', $required);
    }

    public function test_get_optional_fields_returns_names_of_optional_fields(): void
    {
        $collection = Collection::factory()->create([
            'organization_id' => $this->organization->id,
            'user_owner_id' => $this->user->id,
            'scheme_id' => $this->scheme->id,
        ]);

        $optional = $collection->getOptionalFields();

        $this->assertIsArray($optional);
        $this->assertContains('tags', $optional);
        $this->assertContains('author', $optional);
        $this->assertNotContains('title', $optional);
    }

    public function test_is_field_required_returns_true_for_required_field(): void
    {
        $collection = Collection::factory()->create([
            'organization_id' => $this->organization->id,
            'user_owner_id' => $this->user->id,
            'scheme_id' => $this->scheme->id,
        ]);

        $this->assertTrue($collection->isFieldRequired('title'));
        $this->assertTrue($collection->isFieldRequired('description'));
    }

    public function test_is_field_required_returns_false_for_optional_field(): void
    {
        $collection = Collection::factory()->create([
            'organization_id' => $this->organization->id,
            'user_owner_id' => $this->user->id,
            'scheme_id' => $this->scheme->id,
        ]);

        $this->assertFalse($collection->isFieldRequired('tags'));
        $this->assertFalse($collection->isFieldRequired('author'));
    }

    public function test_scheme_get_field_map_returns_keyed_map(): void
    {
        $map = $this->scheme->getFieldMap();

        $this->assertArrayHasKey('title', $map);
        $this->assertArrayHasKey('description', $map);
        $this->assertEquals('text', $map['title']['es_type']);
    }

    public function test_scheme_get_facet_fields_returns_only_facet_fields(): void
    {
        $facetScheme = CollectionScheme::create([
            'name' => 'facet_scheme',
            'display_name' => 'Facet Scheme',
            'is_system' => false,
            'fields' => [
                ['name' => 'language', 'display_name' => 'Language', 'type' => 'string',
                    'required' => false, 'storage' => 'metadata', 'is_facet' => true,
                    'facet_order' => 1, 'display_in_form' => true, 'order' => 1, 'es_type' => 'keyword'],
                ['name' => 'title', 'display_name' => 'Title', 'type' => 'string',
                    'required' => true, 'storage' => 'metadata', 'is_facet' => false,
                    'display_in_form' => true, 'order' => 2, 'es_type' => 'text'],
            ],
        ]);

        $facets = $facetScheme->getFacetFields();

        $this->assertCount(1, $facets);
        $this->assertEquals('language', $facets[0]['name']);
    }
}
