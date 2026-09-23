<?php

namespace Tests\Feature;

use App\Models\Collection;
use App\Models\File;
use App\Models\Organization;
use App\Models\Resource;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GET /api/v1/workspaces/aity-batches must report the number of files involved in a
 * batch, not just the resource count: a canonical resource is one row but N files.
 */
class AityBatchesFileCountTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Organization $organization;

    private Collection $collection;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->organization = Organization::factory()->create();

        $this->organization->users()->attach($this->user->id, ['role' => 'admin']);
        $this->user->update(['last_organization_id' => $this->organization->id]);

        $this->collection = Collection::factory()->create([
            'organization_id' => $this->organization->id,
            'user_owner_id' => $this->user->id,
            'index_id' => null,
        ]);

        $this->token = $this->user->createToken('test', ['*'], now()->addHour())->plainTextToken;
    }

    private function makeResourceWithFiles(int $fileCount): Resource
    {
        $resource = Resource::factory()->create([
            'collection_id' => $this->collection->id,
            'organization_id' => $this->organization->id,
            'user_owner_id' => $this->user->id,
        ]);

        File::factory()->count($fileCount)->create([
            'resource_id' => $resource->id,
            'is_active' => true,
            'uncommitted_at' => null,
        ]);

        return $resource;
    }

    public function test_batch_reports_total_files_across_resources(): void
    {
        $batch = Workspace::factory()->create([
            'organization_id' => $this->organization->id,
            'user_owner_id' => $this->user->id,
            'purpose' => 'aity_review',
        ]);

        // 1 canonical-style resource with 3 files + 1 resource with 1 file = 2 resources, 4 files
        $batch->resources()->attach($this->makeResourceWithFiles(3)->id);
        $batch->resources()->attach($this->makeResourceWithFiles(1)->id);

        $response = $this->withToken($this->token)
            ->getJson('/api/v1/workspaces/aity-batches');

        $response->assertStatus(200)
            ->assertJsonPath('data.batches.0.resources_count', 2)
            ->assertJsonPath('data.batches.0.files_count', 4);
    }

    public function test_uncommitted_files_are_excluded_from_count(): void
    {
        $batch = Workspace::factory()->create([
            'organization_id' => $this->organization->id,
            'user_owner_id' => $this->user->id,
            'purpose' => 'aity_review',
        ]);

        $resource = $this->makeResourceWithFiles(2);
        // An uncommitted draft file should not be counted
        File::factory()->create([
            'resource_id' => $resource->id,
            'is_active' => true,
            'uncommitted_at' => now(),
        ]);
        $batch->resources()->attach($resource->id);

        $this->withToken($this->token)
            ->getJson('/api/v1/workspaces/aity-batches')
            ->assertStatus(200)
            ->assertJsonPath('data.batches.0.files_count', 2);
    }
}
