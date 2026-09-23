<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Stateless Bearer-token clients (MCP, integrations) never hit /login, so
 * they never populate the session or `last_organization_id` that
 * CurrentOrganizationService normally relies on. They must be able to
 * declare org context explicitly via the X-Organization-ID header instead.
 */
class OrganizationHeaderContextTest extends TestCase
{
    use RefreshDatabase;

    public function test_bearer_token_client_can_scope_requests_via_org_header(): void
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->create(['last_organization_id' => null]);
        $organization->users()->attach($user->id, ['role' => 'editor']);

        Workspace::factory()->create([
            'organization_id' => $organization->id,
            'user_owner_id' => $user->id,
        ]);

        $token = $user->createToken('claude-desktop', ['read', 'ask']);

        $this->withToken($token->plainTextToken)
            ->withHeader('X-Organization-ID', (string) $organization->id)
            ->getJson('/api/v1/workspaces')
            ->assertStatus(200)
            ->assertJsonPath('success', true);
    }

    public function test_org_header_is_ignored_when_user_lacks_access(): void
    {
        $ownOrg = Organization::factory()->create();
        $otherOrg = Organization::factory()->create();
        $user = User::factory()->create(['last_organization_id' => null]);
        $ownOrg->users()->attach($user->id, ['role' => 'editor']);
        $user->update(['last_organization_id' => $ownOrg->id]);

        $ownWorkspace = Workspace::factory()->create([
            'organization_id' => $ownOrg->id,
            'user_owner_id' => $user->id,
            'name' => 'Own Workspace',
        ]);
        Workspace::factory()->create([
            'organization_id' => $otherOrg->id,
            'name' => 'Other Org Workspace',
        ]);

        $token = $user->createToken('claude-desktop', ['read', 'ask']);

        // Header names an org the token owner does not belong to. The
        // request must fall back to the user's own org, not silently
        // scope into (or leak data from) the org named in the header.
        $response = $this->withToken($token->plainTextToken)
            ->withHeader('X-Organization-ID', (string) $otherOrg->id)
            ->getJson('/api/v1/workspaces');

        $response->assertStatus(200);
        $ids = array_column($response->json('data.workspaces'), 'id');
        $this->assertContains($ownWorkspace->id, $ids);
        $this->assertCount(1, $ids);
    }

    public function test_missing_org_header_falls_back_to_last_organization(): void
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->create();
        $organization->users()->attach($user->id, ['role' => 'editor']);
        $user->update(['last_organization_id' => $organization->id]);

        $token = $user->createToken('claude-desktop', ['read', 'ask']);

        $this->withToken($token->plainTextToken)
            ->getJson('/api/v1/workspaces')
            ->assertStatus(200)
            ->assertJsonPath('success', true);
    }
}
