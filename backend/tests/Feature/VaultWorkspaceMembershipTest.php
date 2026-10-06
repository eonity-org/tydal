<?php

namespace Tests\Feature;

use App\Models\Collection;
use App\Models\Organization;
use App\Models\Resource;
use App\Models\User;
use App\Models\Vault;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Changing the members of a workspace attached to a vault publishes or
 * unpublishes outside TYDAL, so it takes `workspaces.manage-vault-resources`
 * (admins and owners). Editors keep curating plain workspaces and AiTy Review
 * batches. WorkspacePolicy::manageResources.
 */
class VaultWorkspaceMembershipTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Workspace $plain;

    private Workspace $shared;

    private Resource $resource;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();

        $this->organization = Organization::factory()->create();
        $owner = User::factory()->create();
        $collection = Collection::factory()->create([
            'organization_id' => $this->organization->id,
            'user_owner_id' => $owner->id,
        ]);
        $this->resource = Resource::factory()->forCollection($collection->id)->create([
            'organization_id' => $this->organization->id,
            'user_owner_id' => $owner->id,
        ]);

        $this->plain = Workspace::factory()->forOrganization($this->organization->id)->create();
        $this->shared = Workspace::factory()->forOrganization($this->organization->id)->create();
        $vault = Vault::factory()->create(['organization_id' => $this->organization->id]);
        $this->shared->vaults()->attach($vault->id);
    }

    private function tokenFor(string $role): string
    {
        $user = User::factory()->create();
        $this->organization->users()->attach($user->id, ['role' => $role]);
        $user->update(['last_organization_id' => $this->organization->id]);

        return $user->createToken('auth-token', ['*'], now()->addHour())->plainTextToken;
    }

    private function add(string $token, Workspace $workspace): TestResponse
    {
        return $this->withToken($token)
            ->postJson("/api/v1/workspaces/{$workspace->id}/resources", ['resource_id' => $this->resource->id]);
    }

    private function remove(string $token, Workspace $workspace): TestResponse
    {
        return $this->withToken($token)
            ->deleteJson("/api/v1/workspaces/{$workspace->id}/resources/{$this->resource->id}");
    }

    private function isMember(Workspace $workspace): bool
    {
        return $workspace->resources()->whereKey($this->resource->id)->exists();
    }

    public function test_an_editor_cannot_add_to_or_remove_from_a_vault_attached_workspace(): void
    {
        $editor = $this->tokenFor('editor');

        $this->add($editor, $this->shared)
            ->assertForbidden()
            ->assertJsonFragment(['message' => 'This workspace is shared through a vault. Ask an administrator to add or remove its resources.']);
        $this->assertFalse($this->isMember($this->shared));

        $this->shared->resources()->attach($this->resource->id);
        $this->remove($editor, $this->shared)->assertForbidden();
        $this->assertTrue($this->isMember($this->shared));
    }

    public function test_an_editor_still_curates_a_plain_workspace(): void
    {
        $editor = $this->tokenFor('editor');

        $this->add($editor, $this->plain)->assertOk();
        $this->assertTrue($this->isMember($this->plain));

        $this->remove($editor, $this->plain)->assertOk();
        $this->assertFalse($this->isMember($this->plain));
    }

    public function test_an_admin_curates_both(): void
    {
        $admin = $this->tokenFor('admin');

        foreach ([$this->plain, $this->shared] as $workspace) {
            $this->add($admin, $workspace)->assertOk();
            $this->assertTrue($this->isMember($workspace));
            $this->remove($admin, $workspace)->assertOk();
            $this->assertFalse($this->isMember($workspace));
        }
    }

    public function test_bulk_attach_reports_every_id_skipped_as_vault_connected_for_an_editor(): void
    {
        $editor = $this->tokenFor('editor');

        $this->withToken($editor)
            ->postJson("/api/v1/workspaces/{$this->shared->id}/resources/bulk-attach", ['resource_ids' => [$this->resource->id]])
            ->assertForbidden()
            ->assertJson([
                'success' => false,
                'requested' => 1,
                'applied' => 0,
                'skipped' => [['id' => $this->resource->id, 'reason' => 'vault_connected']],
            ]);
        $this->assertFalse($this->isMember($this->shared));

        $this->shared->resources()->attach($this->resource->id);
        $this->withToken($editor)
            ->postJson("/api/v1/workspaces/{$this->shared->id}/resources/bulk-detach", ['resource_ids' => [$this->resource->id]])
            ->assertForbidden()
            ->assertJsonPath('skipped.0.reason', 'vault_connected');
        $this->assertTrue($this->isMember($this->shared));

        // The plain workspace is unaffected.
        $this->withToken($editor)
            ->postJson("/api/v1/workspaces/{$this->plain->id}/resources/bulk-attach", ['resource_ids' => [$this->resource->id]])
            ->assertOk()
            ->assertJsonPath('applied', 1);
    }

    public function test_bulk_attach_works_for_an_admin(): void
    {
        $this->withToken($this->tokenFor('admin'))
            ->postJson("/api/v1/workspaces/{$this->shared->id}/resources/bulk-attach", ['resource_ids' => [$this->resource->id]])
            ->assertOk()
            ->assertJsonPath('applied', 1);
        $this->assertTrue($this->isMember($this->shared));
    }

    public function test_a_viewer_is_refused_with_the_plain_403(): void
    {
        $this->withToken($this->tokenFor('viewer'))
            ->postJson("/api/v1/workspaces/{$this->shared->id}/resources/bulk-attach", ['resource_ids' => [$this->resource->id]])
            ->assertForbidden()
            ->assertJsonMissingPath('skipped');
    }

    public function test_an_editor_still_fills_an_aity_review_batch(): void
    {
        $batch = Workspace::factory()->forOrganization($this->organization->id)->create([
            'is_system' => true,
            'purpose' => 'aity_review',
        ]);

        $this->add($this->tokenFor('editor'), $batch)->assertOk();
        $this->assertTrue($this->isMember($batch));
    }

    public function test_the_workspace_list_says_which_workspaces_are_vault_connected(): void
    {
        $workspaces = collect(
            $this->withToken($this->tokenFor('editor'))
                ->getJson('/api/v1/workspaces')
                ->assertOk()
                ->json('data.workspaces')
        )->keyBy('id');

        $this->assertSame(1, $workspaces[$this->shared->id]['vaults_count']);
        $this->assertSame(0, $workspaces[$this->plain->id]['vaults_count']);
    }
}
