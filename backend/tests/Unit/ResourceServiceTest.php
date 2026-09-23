<?php

namespace Tests\Unit;

use App\Enums\ResourceState;
use App\Enums\ResourceType;
use App\Models\Collection;
use App\Models\Organization;
use App\Models\Resource;
use App\Models\User;
use App\Services\ResourceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResourceServiceTest extends TestCase
{
    use RefreshDatabase;

    private ResourceService $service;

    private User $user;

    private Organization $organization;

    private Collection $collection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(ResourceService::class);

        $this->user = User::factory()->create();
        $this->organization = Organization::factory()->create();

        $this->collection = Collection::factory()->create([
            'organization_id' => $this->organization->id,
            'user_owner_id' => $this->user->id,
        ]);
    }

    public function test_get_resources_returns_paginated_results(): void
    {
        Resource::factory()
            ->forCollection($this->collection->id)
            ->count(25)
            ->create([
                'organization_id' => $this->organization->id,
                'user_owner_id' => $this->user->id,
            ]);

        $result = $this->service->getResources(
            $this->organization->id,
            [],
            20,
            1
        );

        $this->assertCount(20, $result->items());
        $this->assertEquals(25, $result->total());
        $this->assertEquals(2, $result->lastPage());
    }

    public function test_get_resources_filters_by_type(): void
    {
        Resource::factory()
            ->forCollection($this->collection->id)
            ->create([
                'organization_id' => $this->organization->id,
                'user_owner_id' => $this->user->id,
                'type' => ResourceType::DOCUMENT->value,
            ]);

        Resource::factory()
            ->forCollection($this->collection->id)
            ->create([
                'organization_id' => $this->organization->id,
                'user_owner_id' => $this->user->id,
                'type' => ResourceType::IMAGE->value,
            ]);

        $result = $this->service->getResources(
            $this->organization->id,
            ['type' => 'document'],
            20,
            1
        );

        $this->assertCount(1, $result->items());
        $this->assertEquals(ResourceType::DOCUMENT, $result->items()[0]->type);
    }

    public function test_get_resources_filters_by_collection(): void
    {
        $collection2 = Collection::factory()->create([
            'organization_id' => $this->organization->id,
        ]);

        Resource::factory()
            ->forCollection($this->collection->id)
            ->count(5)
            ->create([
                'organization_id' => $this->organization->id,
                'user_owner_id' => $this->user->id,
            ]);

        Resource::factory()
            ->forCollection($collection2->id)
            ->count(3)
            ->create([
                'organization_id' => $this->organization->id,
                'user_owner_id' => $this->user->id,
            ]);

        $result = $this->service->getResources(
            $this->organization->id,
            ['collection_id' => $this->collection->id],
            20,
            1
        );

        $this->assertCount(5, $result->items());
    }

    public function test_get_resources_filters_by_search_term(): void
    {
        Resource::factory()
            ->forCollection($this->collection->id)
            ->create([
                'organization_id' => $this->organization->id,
                'user_owner_id' => $this->user->id,
                'name' => 'Python Programming Guide',
            ]);

        Resource::factory()
            ->forCollection($this->collection->id)
            ->create([
                'organization_id' => $this->organization->id,
                'user_owner_id' => $this->user->id,
                'name' => 'JavaScript Basics',
            ]);

        $result = $this->service->getResources(
            $this->organization->id,
            ['search' => 'Python'],
            20,
            1
        );

        $this->assertCount(1, $result->items());
        $this->assertStringContainsString('Python', $result->items()[0]->name);
    }

    public function test_create_resource_sets_default_flags(): void
    {
        $data = [
            'collection_id' => $this->collection->id,
            'organization_id' => $this->organization->id,
            'user_owner_id' => $this->user->id,
            'name' => 'Test Resource',
            'type' => ResourceType::DOCUMENT->value,
        ];

        $resource = $this->service->createResource($data);

        $this->assertNotNull($resource);
        $this->assertSame(ResourceState::LIVE, $resource->state);
        $this->assertTrue($resource->payload['downloadable']);
        $this->assertFalse($resource->payload['public']);
        $this->assertFalse($resource->payload['featured']);
    }

    public function test_get_resource_by_id(): void
    {
        $resource = Resource::factory()->create([
            'collection_id' => $this->collection->id,
            'organization_id' => $this->organization->id,
            'user_owner_id' => $this->user->id,
        ]);

        $found = $this->service->getResourceById($resource->id);

        $this->assertNotNull($found);
        $this->assertEquals($resource->id, $found->id);
        $this->assertEquals($resource->name, $found->name);
    }

    public function test_update_resource(): void
    {
        $resource = Resource::factory()->create([
            'collection_id' => $this->collection->id,
            'organization_id' => $this->organization->id,
            'user_owner_id' => $this->user->id,
            'name' => 'Original Name',
        ]);

        $updated = $this->service->updateResource($resource->id, [
            'name' => 'Updated Name',
            'description' => 'Updated Description',
        ]);

        $this->assertNotNull($updated);
        $this->assertEquals('Updated Name', $updated->name);
        $this->assertEquals('Updated Description', $updated->description);
    }

    public function test_delete_resource(): void
    {
        $resource = Resource::factory()->create([
            'collection_id' => $this->collection->id,
            'organization_id' => $this->organization->id,
            'user_owner_id' => $this->user->id,
        ]);

        $deleted = $this->service->deleteResource($resource->id);

        $this->assertTrue($deleted);
        $this->assertSoftDeleted('resources', [
            'id' => $resource->id,
        ]);
    }

    public function test_delete_nonexistent_resource_returns_false(): void
    {
        $deleted = $this->service->deleteResource('nonexistent-id');

        $this->assertFalse($deleted);
    }
}
