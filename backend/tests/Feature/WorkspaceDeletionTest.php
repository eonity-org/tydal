<?php

namespace Tests\Feature;

use App\Jobs\SyncResourcesToElasticsearch;
use App\Models\Collection;
use App\Models\Organization;
use App\Models\Resource;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Interfaces\WorkspaceServiceInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * Deleting a workspace must take its members' search documents with it.
 *
 * `dam_resource_workspace` and `workspace_vault` cascade at the DATABASE level,
 * which bypasses Eloquent: no pivot events, no model events on the resources
 * that just lost a membership, so nothing reindexed them. They kept a stale
 * `workspace_ids` entry and — since membership is what grants vault
 * reachability — kept their documents in the vault indexes that membership
 * used to justify, i.e. stayed publicly projected.
 */
class WorkspaceDeletionTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Collection $collection;

    private WorkspaceServiceInterface $workspaces;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $this->organization = Organization::factory()->create();
        $this->collection = Collection::factory()->create([
            'organization_id' => $this->organization->id,
            'user_owner_id' => $user->id,
        ]);
        $this->workspaces = app(WorkspaceServiceInterface::class);

        Bus::fake();
    }

    private function populatedWorkspace(int $memberCount): array
    {
        $workspace = Workspace::factory()->create([
            'organization_id' => $this->organization->id,
        ]);

        $resources = Resource::factory()
            ->forCollection($this->collection->id)
            ->count($memberCount)
            ->create(['organization_id' => $this->organization->id]);

        $workspace->resources()->sync($resources->pluck('id')->all());

        return [$workspace, $resources->pluck('id')->all()];
    }

    public function test_deleting_a_workspace_reindexes_the_resources_that_lost_it(): void
    {
        [$workspace, $memberIds] = $this->populatedWorkspace(3);

        $this->assertTrue($this->workspaces->deleteWorkspace((string) $workspace->id));

        Bus::assertDispatchedSync(
            SyncResourcesToElasticsearch::class,
            fn (SyncResourcesToElasticsearch $job) => empty(array_diff($memberIds, $job->getResourceIds()))
                && count($job->getResourceIds()) === 3
        );
    }

    public function test_the_resources_survive_and_only_lose_the_membership(): void
    {
        [$workspace, $memberIds] = $this->populatedWorkspace(2);

        $this->workspaces->deleteWorkspace((string) $workspace->id);

        $this->assertDatabaseMissing('workspaces', ['id' => $workspace->id]);

        foreach ($memberIds as $id) {
            // The point of the confirmation copy: the resources are not deleted.
            $this->assertDatabaseHas('resources', ['id' => $id, 'deleted_at' => null]);
            $this->assertDatabaseMissing('dam_resource_workspace', [
                'resource_id' => $id,
                'workspace_id' => $workspace->id,
            ]);
        }
    }

    public function test_membership_of_other_workspaces_is_left_alone(): void
    {
        [$doomed, $memberIds] = $this->populatedWorkspace(2);

        $survivor = Workspace::factory()->create(['organization_id' => $this->organization->id]);
        $survivor->resources()->sync($memberIds);

        $this->workspaces->deleteWorkspace((string) $doomed->id);

        $this->assertEqualsCanonicalizing(
            $memberIds,
            $survivor->resources()->pluck('resources.id')->all()
        );
    }

    public function test_deleting_an_empty_workspace_queues_nothing(): void
    {
        $workspace = Workspace::factory()->create(['organization_id' => $this->organization->id]);

        $this->assertTrue($this->workspaces->deleteWorkspace((string) $workspace->id));

        Bus::assertNotDispatched(SyncResourcesToElasticsearch::class);
    }

    public function test_deleting_a_missing_workspace_reports_failure(): void
    {
        $this->assertFalse($this->workspaces->deleteWorkspace('999999'));
        Bus::assertNotDispatched(SyncResourcesToElasticsearch::class);
    }
}
