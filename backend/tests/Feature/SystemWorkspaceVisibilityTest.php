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

/**
 * Where a machine-managed (is_system) workspace may and may not appear.
 *
 * It is not a curation axis a user chose — AITY review batches and vault
 * writers create and maintain these — so it must stay out of the browsing
 * facets. It is not secret either: its name shows on the cards of resources
 * that belong to it, so an org admin needs somewhere to find out what it is.
 * That somewhere is the workspace manager, via ?include_system=1.
 */
class SystemWorkspaceVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Collection $collection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
        $this->collection = Collection::factory()->create([
            'organization_id' => $this->organization->id,
            'index_id' => null,   // no ES index → the DB fallback builds the facets
        ]);
    }

    private function member(string $role): string
    {
        $user = User::factory()->create();
        $this->organization->users()->attach($user->id, ['role' => $role]);
        $user->update(['last_organization_id' => $this->organization->id]);

        return $user->createToken('auth-token', ['*'], now()->addHour())->plainTextToken;
    }

    private function workspace(string $name, bool $isSystem): Workspace
    {
        return Workspace::factory()->create([
            'organization_id' => $this->organization->id,
            'name' => $name,
            'is_system' => $isSystem,
        ]);
    }

    // ------------------------------------------------------------------ listing

    public function test_the_workspace_list_hides_system_workspaces_by_default(): void
    {
        $this->workspace('Curated', false);
        $this->workspace('exhibition — selection', true);

        $names = $this->withToken($this->member('admin'))
            ->getJson('/api/v1/workspaces')
            ->assertStatus(200)
            ->json('data.workspaces.*.name');

        $this->assertContains('Curated', $names);
        $this->assertNotContains('exhibition — selection', $names);
    }

    public function test_an_admin_can_ask_for_system_workspaces(): void
    {
        $this->workspace('Curated', false);
        $this->workspace('exhibition — selection', true);

        $names = $this->withToken($this->member('admin'))
            ->getJson('/api/v1/workspaces?include_system=1')
            ->assertStatus(200)
            ->json('data.workspaces.*.name');

        $this->assertContains('Curated', $names);
        $this->assertContains('exhibition — selection', $names);
    }

    public function test_an_editor_asking_for_system_workspaces_still_does_not_get_them(): void
    {
        $this->workspace('exhibition — selection', true);

        $names = $this->withToken($this->member('editor'))
            ->getJson('/api/v1/workspaces?include_system=1')
            ->assertStatus(200)
            ->json('data.workspaces.*.name');

        // Silently ignored rather than refused — the flag is a listing
        // preference, and the caller still gets a valid list without it.
        $this->assertNotContains('exhibition — selection', $names);
    }

    // ------------------------------------------------------------------- facets

    public function test_the_catalogue_facets_omit_system_workspaces(): void
    {
        $curated = $this->workspace('Curated', false);
        $system = $this->workspace('exhibition — selection', true);

        $resource = Resource::factory()->forCollection($this->collection->id)->create([
            'organization_id' => $this->organization->id,
            'state' => ResourceState::LIVE->value,
        ]);
        $resource->workspaces()->sync([$curated->id, $system->id]);

        $facets = $this->withToken($this->member('admin'))
            ->getJson("/api/v1/catalogue/{$this->collection->id}")
            ->assertStatus(200)
            ->json('facets');

        $workspaceFacet = collect($facets)->firstWhere('key', 'workspaces');

        $this->assertNotNull($workspaceFacet, 'The workspace facet should still be offered.');
        $this->assertArrayHasKey('Curated', $workspaceFacet['values']);
        $this->assertArrayNotHasKey('exhibition — selection', $workspaceFacet['values']);
    }

    public function test_a_hand_written_filter_cannot_select_by_a_system_workspace(): void
    {
        $system = $this->workspace('exhibition — selection', true);

        $resource = Resource::factory()->forCollection($this->collection->id)->create([
            'organization_id' => $this->organization->id,
            'state' => ResourceState::LIVE->value,
        ]);
        $resource->workspaces()->sync([$system->id]);

        // The value is not on the facet list, so the filter must not honour it
        // either — otherwise the exclusion is cosmetic.
        $data = $this->withToken($this->member('admin'))
            ->getJson("/api/v1/catalogue/{$this->collection->id}?".http_build_query([
                'facets' => ['workspaces' => ['exhibition — selection']],
            ]))
            ->assertStatus(200)
            ->json('data');

        $this->assertCount(0, $data);
    }
}
