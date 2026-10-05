<?php

namespace Tests\Feature;

use App\Enums\OrganizationRole;
use App\Enums\ResourceState;
use App\Jobs\AutoApproveWorkspaceJob;
use App\Models\Collection;
use App\Models\Organization;
use App\Models\Resource;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The upload wizard's Auto mode opens an AiTy Review batch (a workspace with
 * `purpose = aity_review`), adds the new resources to it and dispatches the
 * auto-approve job. Editors upload resources, so the whole flow must work for
 * them — while ordinary (editorial) workspace creation stays admin-only.
 *
 * Regression for issue #10: the batch 403'd for editors and the wizard
 * swallowed the error.
 */
class AityReviewBatchAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
    }

    /** @return array{0: User, 1: string} */
    private function member(OrganizationRole $role): array
    {
        $user = User::factory()->create();
        $this->organization->users()->attach($user->id, ['role' => $role->value]);
        $user->update(['last_organization_id' => $this->organization->id]);

        return [$user, $user->createToken('t', ['*'], now()->addHour())->plainTextToken];
    }

    private function resourceOwnedBy(User $user): Resource
    {
        $collection = Collection::factory()->create([
            'organization_id' => $this->organization->id,
            'user_owner_id' => $user->id,
            'index_id' => null,
        ]);

        return Resource::factory()->forCollection($collection->id)->create([
            'organization_id' => $this->organization->id,
            'user_owner_id' => $user->id,
            'state' => ResourceState::LIVE->value,
        ]);
    }

    public function test_an_editor_can_open_an_aity_review_batch_and_run_it(): void
    {
        Queue::fake();
        [$editor, $token] = $this->member(OrganizationRole::EDITOR);
        $resource = $this->resourceOwnedBy($editor);

        $created = $this->withToken($token)
            ->postJson('/api/v1/workspaces', ['name' => 'New Upload', 'purpose' => 'aity_review'])
            ->assertStatus(201);

        $workspaceId = $created->json('data.workspace.id');
        $workspace = Workspace::findOrFail($workspaceId);
        $this->assertSame('aity_review', $workspace->purpose);
        $this->assertTrue((bool) $workspace->is_system);

        $this->withToken($token)
            ->postJson("/api/v1/workspaces/{$workspaceId}/resources", ['resource_id' => $resource->id])
            ->assertStatus(200);

        $this->withToken($token)
            ->postJson('/api/v1/aity/auto-approve/dispatch', [
                'workspace_id' => $workspaceId,
                'apply_name' => true,
                'apply_description' => true,
                'apply_tags' => true,
                'dedup' => true,
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'pending');

        Queue::assertPushed(AutoApproveWorkspaceJob::class);
        $this->assertSame('pending', $workspace->fresh()->auto_approve_status);
    }

    public function test_an_editor_still_cannot_create_an_ordinary_workspace(): void
    {
        [, $token] = $this->member(OrganizationRole::EDITOR);

        $this->withToken($token)
            ->postJson('/api/v1/workspaces', ['name' => 'Editorial'])
            ->assertStatus(403);

        $this->assertDatabaseMissing('workspaces', ['name' => 'Editorial']);
    }

    public function test_a_viewer_can_create_neither_kind_of_workspace(): void
    {
        [, $token] = $this->member(OrganizationRole::VIEWER);

        $this->withToken($token)
            ->postJson('/api/v1/workspaces', ['name' => 'Editorial'])
            ->assertStatus(403);

        $this->withToken($token)
            ->postJson('/api/v1/workspaces', ['name' => 'Batch', 'purpose' => 'aity_review'])
            ->assertStatus(403);

        $this->assertDatabaseMissing('workspaces', ['name' => 'Editorial']);
        $this->assertDatabaseMissing('workspaces', ['name' => 'Batch']);
    }

    public function test_a_viewer_cannot_dispatch_auto_approve(): void
    {
        Queue::fake();
        [$viewer, $token] = $this->member(OrganizationRole::VIEWER);

        $batch = Workspace::factory()->create([
            'organization_id' => $this->organization->id,
            'user_owner_id' => $viewer->id,
            'purpose' => 'aity_review',
            'is_system' => true,
        ]);

        $this->withToken($token)
            ->postJson('/api/v1/aity/auto-approve/dispatch', ['workspace_id' => $batch->id])
            ->assertStatus(403);

        Queue::assertNotPushed(AutoApproveWorkspaceJob::class);
    }

    public function test_an_admin_can_still_create_both_kinds(): void
    {
        [, $token] = $this->member(OrganizationRole::ADMIN);

        $this->withToken($token)
            ->postJson('/api/v1/workspaces', ['name' => 'Editorial'])
            ->assertStatus(201);

        $this->withToken($token)
            ->postJson('/api/v1/workspaces', ['name' => 'Batch', 'purpose' => 'aity_review'])
            ->assertStatus(201);
    }
}
