<?php

namespace Tests\Feature;

use App\Enums\ResourceState;
use App\Jobs\DeleteResourceFromElasticsearch;
use App\Jobs\IndexResourceToElasticsearch;
use App\Models\Collection;
use App\Models\Organization;
use App\Models\Resource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Verifies that Resource lifecycle events dispatch the correct ES queue jobs.
 * Does NOT hit a real Elasticsearch cluster — Queue::fake() intercepts jobs.
 */
class ElasticsearchIndexingTest extends TestCase
{
    use RefreshDatabase;

    private Collection $collection;

    private User $user;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->organization = Organization::factory()->create();
        $this->collection = Collection::factory()->create([
            'organization_id' => $this->organization->id,
            'user_owner_id' => $this->user->id,
        ]);

        $this->organization->users()->attach($this->user->id, ['role' => 'admin']);
        $this->user->update(['last_organization_id' => $this->organization->id]);
    }

    public function test_creating_active_resource_dispatches_index_job(): void
    {
        Queue::fake();

        Resource::factory()->create([
            'collection_id' => $this->collection->id,
            'organization_id' => $this->organization->id,
            'user_owner_id' => $this->user->id,
            'state' => ResourceState::LIVE->value,
        ]);

        Queue::assertPushed(IndexResourceToElasticsearch::class);
    }

    public function test_creating_inactive_resource_does_not_dispatch_index_job(): void
    {
        Queue::fake();

        Resource::factory()->create([
            'collection_id' => $this->collection->id,
            'organization_id' => $this->organization->id,
            'user_owner_id' => $this->user->id,
            'state' => ResourceState::ARCHIVED->value,
        ]);

        Queue::assertNotPushed(IndexResourceToElasticsearch::class);
    }

    public function test_updating_active_resource_dispatches_index_job(): void
    {
        $resource = Resource::factory()->create([
            'collection_id' => $this->collection->id,
            'organization_id' => $this->organization->id,
            'user_owner_id' => $this->user->id,
            'state' => ResourceState::LIVE->value,
        ]);

        Queue::fake();

        $resource->update(['name' => 'Updated Name']);

        Queue::assertPushed(IndexResourceToElasticsearch::class);
    }

    public function test_deactivating_resource_dispatches_delete_job(): void
    {
        $resource = Resource::factory()->create([
            'collection_id' => $this->collection->id,
            'organization_id' => $this->organization->id,
            'user_owner_id' => $this->user->id,
            'state' => ResourceState::LIVE->value,
        ]);

        Queue::fake();

        $resource->update(['state' => ResourceState::ARCHIVED->value]);

        Queue::assertPushed(DeleteResourceFromElasticsearch::class);
    }

    public function test_deleting_resource_dispatches_delete_job(): void
    {
        $resource = Resource::factory()->create([
            'collection_id' => $this->collection->id,
            'organization_id' => $this->organization->id,
            'user_owner_id' => $this->user->id,
            'state' => ResourceState::LIVE->value,
        ]);

        Queue::fake();

        $resource->delete();

        Queue::assertPushed(DeleteResourceFromElasticsearch::class);
    }
}
