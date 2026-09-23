<?php

namespace Tests\Feature;

use App\Enums\ResourceState;
use App\Models\Collection;
use App\Models\Organization;
use App\Models\Resource;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $viewer;

    private Organization $organization;

    private Collection $collection;

    private Workspace $defaultWorkspace;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create();
        $this->viewer = User::factory()->create();
        $this->organization = Organization::factory()->create();
        $this->collection = Collection::factory()->create([
            'organization_id' => $this->organization->id,
            'user_owner_id' => $this->admin->id,
        ]);

        $this->organization->users()->attach($this->admin->id, ['role' => 'admin']);
        $this->organization->users()->attach($this->viewer->id, ['role' => 'viewer']);

        $this->admin->update(['last_organization_id' => $this->organization->id]);
        $this->viewer->update(['last_organization_id' => $this->organization->id]);

        $this->defaultWorkspace = Workspace::factory()->create([
            'organization_id' => $this->organization->id,
            'user_owner_id' => $this->admin->id,
            'is_default' => true,
        ]);

        $this->workspace = Workspace::factory()->create([
            'organization_id' => $this->organization->id,
            'user_owner_id' => $this->admin->id,
            'is_default' => false,
        ]);
    }

    // -------------------------------------------------------------------------
    // Index
    // -------------------------------------------------------------------------

    public function test_admin_can_list_workspaces(): void
    {
        $token = $this->admin->createToken('t')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/v1/workspaces');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => ['workspaces'],
                'meta' => ['pagination'],
            ])
            ->assertJsonPath('success', true);
    }

    public function test_viewer_can_list_workspaces(): void
    {
        $token = $this->viewer->createToken('t')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/v1/workspaces');

        $response->assertStatus(200);
    }

    public function test_index_only_returns_workspaces_for_current_organization(): void
    {
        $otherOrg = Organization::factory()->create();
        Workspace::factory()->forOrganization($otherOrg->id)->count(3)->create();

        $token = $this->admin->createToken('t')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/v1/workspaces');

        // Only the 2 workspaces belonging to $this->organization should appear
        $response->assertStatus(200)
            ->assertJsonPath('meta.pagination.total', 2);
    }

    // -------------------------------------------------------------------------
    // Store
    // -------------------------------------------------------------------------

    public function test_admin_can_create_workspace(): void
    {
        $token = $this->admin->createToken('t')->plainTextToken;

        $response = $this->withToken($token)->postJson('/api/v1/workspaces', [
            'name' => 'Marketing Assets',
            'description' => 'Workspace for the marketing team',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.workspace.name', 'Marketing Assets');

        $this->assertDatabaseHas('workspaces', ['name' => 'Marketing Assets']);
    }

    public function test_viewer_cannot_create_workspace(): void
    {
        $token = $this->viewer->createToken('t')->plainTextToken;

        $response = $this->withToken($token)->postJson('/api/v1/workspaces', [
            'name' => 'Should Fail',
        ]);

        $response->assertStatus(403);
    }

    // -------------------------------------------------------------------------
    // Show
    // -------------------------------------------------------------------------

    public function test_admin_can_view_workspace(): void
    {
        $token = $this->admin->createToken('t')->plainTextToken;

        $response = $this->withToken($token)->getJson("/api/v1/workspaces/{$this->workspace->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.workspace.id', $this->workspace->id);
    }

    public function test_cannot_view_workspace_from_other_organization(): void
    {
        $otherOrg = Organization::factory()->create();
        $otherWs = Workspace::factory()->forOrganization($otherOrg->id)->create();

        $token = $this->admin->createToken('t')->plainTextToken;

        $response = $this->withToken($token)->getJson("/api/v1/workspaces/{$otherWs->id}");

        $response->assertStatus(403);
    }

    // -------------------------------------------------------------------------
    // Update
    // -------------------------------------------------------------------------

    public function test_admin_can_update_workspace(): void
    {
        $token = $this->admin->createToken('t')->plainTextToken;

        $response = $this->withToken($token)->putJson("/api/v1/workspaces/{$this->workspace->id}", [
            'name' => 'Renamed Workspace',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.workspace.name', 'Renamed Workspace');

        $this->assertDatabaseHas('workspaces', [
            'id' => $this->workspace->id,
            'name' => 'Renamed Workspace',
        ]);
    }

    public function test_viewer_cannot_update_workspace(): void
    {
        $token = $this->viewer->createToken('t')->plainTextToken;

        $response = $this->withToken($token)->putJson("/api/v1/workspaces/{$this->workspace->id}", [
            'name' => 'Should Fail',
        ]);

        $response->assertStatus(403);
    }

    // -------------------------------------------------------------------------
    // Destroy
    // -------------------------------------------------------------------------

    public function test_admin_can_delete_workspace(): void
    {
        $token = $this->admin->createToken('t')->plainTextToken;

        $response = $this->withToken($token)->deleteJson("/api/v1/workspaces/{$this->workspace->id}");

        $response->assertStatus(200)->assertJsonPath('success', true);

        $this->assertDatabaseMissing('workspaces', ['id' => $this->workspace->id]);
    }

    public function test_viewer_cannot_delete_workspace(): void
    {
        $token = $this->viewer->createToken('t')->plainTextToken;

        $response = $this->withToken($token)->deleteJson("/api/v1/workspaces/{$this->workspace->id}");

        $response->assertStatus(403);
        $this->assertDatabaseHas('workspaces', ['id' => $this->workspace->id]);
    }

    // -------------------------------------------------------------------------
    // Catalogue
    // -------------------------------------------------------------------------

    public function test_default_workspace_catalogue_returns_all_org_resources(): void
    {
        Resource::factory()->count(3)->create([
            'organization_id' => $this->organization->id,
            'collection_id' => $this->collection->id,
            'user_owner_id' => $this->admin->id,
            'state' => ResourceState::LIVE->value,
        ]);

        // Resource in a different org — should NOT appear
        $otherOrg = Organization::factory()->create();
        $otherCollection = Collection::factory()->create(['organization_id' => $otherOrg->id]);
        Resource::factory()->create([
            'organization_id' => $otherOrg->id,
            'collection_id' => $otherCollection->id,
            'state' => ResourceState::LIVE->value,
        ]);

        $token = $this->admin->createToken('t')->plainTextToken;

        $response = $this->withToken($token)
            ->getJson("/api/v1/workspaces/{$this->defaultWorkspace->id}/catalogue");

        $response->assertStatus(200)
            ->assertJsonPath('total', 3);
    }

    public function test_curated_workspace_catalogue_returns_only_attached_resources(): void
    {
        $attached = Resource::factory()->count(2)->create([
            'organization_id' => $this->organization->id,
            'collection_id' => $this->collection->id,
            'user_owner_id' => $this->admin->id,
            'state' => ResourceState::LIVE->value,
        ]);
        $unattached = Resource::factory()->create([
            'organization_id' => $this->organization->id,
            'collection_id' => $this->collection->id,
            'user_owner_id' => $this->admin->id,
            'state' => ResourceState::LIVE->value,
        ]);

        $this->workspace->resources()->sync($attached->pluck('id'));

        $token = $this->admin->createToken('t')->plainTextToken;

        $response = $this->withToken($token)
            ->getJson("/api/v1/workspaces/{$this->workspace->id}/catalogue");

        $response->assertStatus(200)
            ->assertJsonPath('total', 2);

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertNotContains($unattached->id, $ids);
    }

    public function test_catalogue_search_filters_by_name(): void
    {
        Resource::factory()->create([
            'organization_id' => $this->organization->id,
            'collection_id' => $this->collection->id,
            'user_owner_id' => $this->admin->id,
            'name' => 'Brand Logo',
            'state' => ResourceState::LIVE->value,
        ]);
        Resource::factory()->create([
            'organization_id' => $this->organization->id,
            'collection_id' => $this->collection->id,
            'user_owner_id' => $this->admin->id,
            'name' => 'Product Photo',
            'state' => ResourceState::LIVE->value,
        ]);

        $token = $this->admin->createToken('t')->plainTextToken;

        $response = $this->withToken($token)
            ->getJson("/api/v1/workspaces/{$this->defaultWorkspace->id}/catalogue?search=Brand");

        $response->assertStatus(200)
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.name', 'Brand Logo');
    }

    // -------------------------------------------------------------------------
    // addResource / removeResource
    // -------------------------------------------------------------------------

    public function test_admin_can_add_resource_to_curated_workspace(): void
    {
        $resource = Resource::factory()->create([
            'organization_id' => $this->organization->id,
            'collection_id' => $this->collection->id,
            'user_owner_id' => $this->admin->id,
        ]);

        $token = $this->admin->createToken('t')->plainTextToken;

        $response = $this->withToken($token)
            ->postJson("/api/v1/workspaces/{$this->workspace->id}/resources", [
                'resource_id' => $resource->id,
            ]);

        $response->assertStatus(200)->assertJsonPath('success', true);

        $this->assertDatabaseHas('dam_resource_workspace', [
            'workspace_id' => $this->workspace->id,
            'resource_id' => $resource->id,
        ]);
    }

    public function test_admin_can_remove_resource_from_curated_workspace(): void
    {
        $resource = Resource::factory()->create([
            'organization_id' => $this->organization->id,
            'collection_id' => $this->collection->id,
            'user_owner_id' => $this->admin->id,
        ]);
        $this->workspace->resources()->attach($resource->id);

        $token = $this->admin->createToken('t')->plainTextToken;

        $response = $this->withToken($token)
            ->deleteJson("/api/v1/workspaces/{$this->workspace->id}/resources/{$resource->id}");

        $response->assertStatus(200)->assertJsonPath('success', true);

        $this->assertDatabaseMissing('dam_resource_workspace', [
            'workspace_id' => $this->workspace->id,
            'resource_id' => $resource->id,
        ]);
    }

    public function test_cannot_manually_add_resource_to_default_workspace(): void
    {
        $resource = Resource::factory()->create([
            'organization_id' => $this->organization->id,
            'collection_id' => $this->collection->id,
            'user_owner_id' => $this->admin->id,
        ]);

        $token = $this->admin->createToken('t')->plainTextToken;

        $response = $this->withToken($token)
            ->postJson("/api/v1/workspaces/{$this->defaultWorkspace->id}/resources", [
                'resource_id' => $resource->id,
            ]);

        $response->assertStatus(422);
    }

    public function test_cannot_manually_remove_resource_from_default_workspace(): void
    {
        $resource = Resource::factory()->create([
            'organization_id' => $this->organization->id,
            'collection_id' => $this->collection->id,
            'user_owner_id' => $this->admin->id,
        ]);

        $token = $this->admin->createToken('t')->plainTextToken;

        $response = $this->withToken($token)
            ->deleteJson("/api/v1/workspaces/{$this->defaultWorkspace->id}/resources/{$resource->id}");

        $response->assertStatus(422);
    }

    public function test_editor_can_add_resource_to_workspace(): void
    {
        $editor = User::factory()->create();
        $this->organization->users()->attach($editor->id, ['role' => 'editor']);
        $editor->update(['last_organization_id' => $this->organization->id]);

        $resource = Resource::factory()->create([
            'organization_id' => $this->organization->id,
            'collection_id' => $this->collection->id,
            'user_owner_id' => $editor->id,
        ]);

        $token = $editor->createToken('t')->plainTextToken;

        $response = $this->withToken($token)
            ->postJson("/api/v1/workspaces/{$this->workspace->id}/resources", [
                'resource_id' => $resource->id,
            ]);

        $response->assertStatus(200)->assertJsonPath('success', true);
    }

    public function test_viewer_cannot_add_resource_to_workspace(): void
    {
        $resource = Resource::factory()->create([
            'organization_id' => $this->organization->id,
            'collection_id' => $this->collection->id,
            'user_owner_id' => $this->admin->id,
        ]);

        $token = $this->viewer->createToken('t')->plainTextToken;

        $response = $this->withToken($token)
            ->postJson("/api/v1/workspaces/{$this->workspace->id}/resources", [
                'resource_id' => $resource->id,
            ]);

        $response->assertStatus(403);
    }

    public function test_cannot_add_resource_from_another_organization(): void
    {
        // Tenancy boundary: workspace membership is what a vault projects, so
        // a foreign resource here would reach the outside through this org's
        // boundary — the same rule that governs attaching a vault.
        $foreign = Resource::factory()->create();

        $token = $this->admin->createToken('t')->plainTextToken;

        $this->withToken($token)
            ->postJson("/api/v1/workspaces/{$this->workspace->id}/resources", [
                'resource_id' => $foreign->id,
            ])
            ->assertStatus(422);

        $this->assertDatabaseMissing('dam_resource_workspace', [
            'workspace_id' => $this->workspace->id,
            'resource_id' => $foreign->id,
        ]);
    }

    public function test_add_resource_rejects_nonexistent_resource(): void
    {
        $token = $this->admin->createToken('t')->plainTextToken;

        $response = $this->withToken($token)
            ->postJson("/api/v1/workspaces/{$this->workspace->id}/resources", [
                'resource_id' => 'nonexistent-uuid-0000-0000-000000000000',
            ]);

        $response->assertStatus(422);
    }

    // -------------------------------------------------------------------------
    // Superadmin bypass
    // -------------------------------------------------------------------------

    public function test_superadmin_can_manage_any_workspace(): void
    {
        $superadmin = User::factory()->create(['is_superadmin' => true]);
        $superadmin->update(['last_organization_id' => $this->organization->id]);

        $token = $superadmin->createToken('t')->plainTextToken;

        // Update
        $response = $this->withToken($token)
            ->putJson("/api/v1/workspaces/{$this->workspace->id}", ['name' => 'SA Renamed']);
        $response->assertStatus(200);

        // Delete a fresh workspace (avoid deleting the shared fixture)
        $ws = Workspace::factory()->create([
            'organization_id' => $this->organization->id,
            'user_owner_id' => $this->admin->id,
        ]);
        $response = $this->withToken($token)->deleteJson("/api/v1/workspaces/{$ws->id}");
        $response->assertStatus(200);
    }
}
