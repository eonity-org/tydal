<?php

namespace Tests\Unit;

use App\Models\Collection;
use App\Models\CollectionScheme;
use App\Models\Organization;
use App\Models\User;
use App\Services\CollectionSchemaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CollectionSchemaServiceTest extends TestCase
{
    use RefreshDatabase;

    private CollectionSchemaService $service;

    private User $user;

    private Organization $organization;

    private CollectionScheme $scheme;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new CollectionSchemaService;
        $this->user = User::factory()->create();
        $this->organization = Organization::factory()->create();
        $this->scheme = CollectionScheme::create([
            'name' => 'test',
            'display_name' => 'Test Scheme',
            'is_system' => false,
            'fields' => [
                [
                    'name' => 'language',
                    'display_name' => 'Language',
                    'type' => 'string',
                    'required' => true,
                    'storage' => 'metadata',
                    'is_facet' => false,
                    'display_in_form' => true,
                    'order' => 1,
                    'validators' => ['min_length' => 2, 'max_length' => 10],
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
                    'order' => 2,
                    'validators' => ['max_length' => 100],
                    'es_type' => 'keyword',
                ],
                [
                    'name' => 'title',
                    'display_name' => 'Title',
                    'type' => 'string',
                    'required' => false,
                    'storage' => 'column',   // skipped by schema service
                    'is_facet' => false,
                    'display_in_form' => true,
                    'order' => 0,
                    'validators' => null,
                    'es_type' => 'text',
                ],
            ],
        ]);
    }

    public function test_validate_resource_data_passes_with_valid_data(): void
    {
        $collection = Collection::factory()->create([
            'organization_id' => $this->organization->id,
            'user_owner_id' => $this->user->id,
            'scheme_id' => $this->scheme->id,
        ]);

        $data = [
            'language' => 'en',
            'author' => 'Jane Doe',
        ];

        $validated = $this->service->validateResourceData($collection, $data);

        $this->assertEquals('en', $validated['language']);
        $this->assertEquals('Jane Doe', $validated['author']);
    }

    public function test_validate_resource_data_throws_exception_for_missing_required_field(): void
    {
        $this->expectException(ValidationException::class);

        $collection = Collection::factory()->create([
            'organization_id' => $this->organization->id,
            'user_owner_id' => $this->user->id,
            'scheme_id' => $this->scheme->id,
        ]);

        // language is required but missing
        $this->service->validateResourceData($collection, ['author' => 'Jane']);
    }

    public function test_validate_resource_data_skips_column_storage_fields(): void
    {
        $collection = Collection::factory()->create([
            'organization_id' => $this->organization->id,
            'user_owner_id' => $this->user->id,
            'scheme_id' => $this->scheme->id,
        ]);

        // 'title' is storage=column so it should not be validated by this service
        // Providing only language (required metadata field) should pass
        $validated = $this->service->validateResourceData($collection, ['language' => 'es']);

        $this->assertEquals('es', $validated['language']);
    }

    public function test_validate_resource_data_returns_data_when_no_scheme(): void
    {
        $collection = Collection::factory()->create([
            'organization_id' => $this->organization->id,
            'user_owner_id' => $this->user->id,
            'scheme_id' => null,
        ]);

        $data = ['anything' => 'value'];

        $result = $this->service->validateResourceData($collection, $data);

        $this->assertEquals($data, $result);
    }

    public function test_validate_resource_data_enforces_max_length(): void
    {
        $this->expectException(ValidationException::class);

        $collection = Collection::factory()->create([
            'organization_id' => $this->organization->id,
            'user_owner_id' => $this->user->id,
            'scheme_id' => $this->scheme->id,
        ]);

        // language max_length is 10
        $this->service->validateResourceData($collection, [
            'language' => 'this_is_way_too_long',
        ]);
    }

    public function test_format_validation_errors_returns_flat_map(): void
    {
        $collection = Collection::factory()->create([
            'organization_id' => $this->organization->id,
            'user_owner_id' => $this->user->id,
            'scheme_id' => $this->scheme->id,
        ]);

        try {
            $this->service->validateResourceData($collection, []);
        } catch (ValidationException $e) {
            $errors = $this->service->formatValidationErrors($e);
            $this->assertIsArray($errors);
            $this->assertArrayHasKey('language', $errors);
        }
    }
}
