<?php

namespace Tests\Feature;

use App\Enums\OrganizationRole;
use App\Enums\ResourceState;
use App\Models\Category;
use App\Models\Collection;
use App\Models\Organization;
use App\Models\Resource;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The role model: a platform capability (`users.is_superadmin`) and an
 * organization membership role, resolved through config/permissions.php.
 *
 * The behaviours pinned here are the ones that were previously wrong:
 * a platform admin needed a membership row in every organization to work in
 * it; no organization owner could manage their own members; administrators
 * could not touch a colleague's resource; and trash restore/force-delete
 * crossed organization boundaries.
 */
class RolesAndPermissionsTest extends TestCase
{
    use RefreshDatabase;

    private function organization(): Organization
    {
        return Organization::factory()->create();
    }

    private function member(Organization $organization, OrganizationRole|string $role): array
    {
        $user = User::factory()->create();
        $organization->users()->attach($user->id, [
            'role' => $role instanceof OrganizationRole ? $role->value : $role,
        ]);
        $user->update(['last_organization_id' => $organization->id]);

        return [$user, $user->createToken('t', ['*'], now()->addHour())->plainTextToken];
    }

    /** A platform admin with NO membership anywhere, pointed at $organization. */
    private function platformAdmin(Organization $organization): string
    {
        $user = User::factory()->create(['is_superadmin' => true]);
        $user->update(['last_organization_id' => $organization->id]);

        return $user->createToken('t', ['*'], now()->addHour())->plainTextToken;
    }

    private function collectionFor(Organization $organization, ?User $owner = null): Collection
    {
        return Collection::factory()->create([
            'organization_id' => $organization->id,
            'user_owner_id' => $owner?->id ?? User::factory()->create()->id,
        ]);
    }

    // ------------------------------------------------- the platform → org bridge

    public function test_a_platform_admin_needs_no_membership_to_read_an_organization(): void
    {
        $organization = $this->organization();
        $collection = $this->collectionFor($organization);
        Category::factory()->create(['organization_id' => $organization->id]);
        Resource::factory()->forCollection($collection->id)->create([
            'organization_id' => $organization->id,
            'state' => ResourceState::LIVE->value,
        ]);

        $token = $this->platformAdmin($organization);

        // Collections and categories were the hard failures: theirs were the
        // only two policies without a superadmin before() hook.
        $this->withToken($token)->getJson('/api/v1/collections')->assertStatus(200);
        $this->withToken($token)->getJson('/api/v1/categories')->assertStatus(200);
        $this->withToken($token)->getJson('/api/v1/resources')->assertStatus(200);
        $this->withToken($token)->getJson('/api/v1/workspaces')->assertStatus(200);
    }

    public function test_a_platform_admin_holds_no_membership_role(): void
    {
        $organization = $this->organization();
        $token = $this->platformAdmin($organization);

        // The distinction the two helpers draw: no pivot row, so no membership
        // role — but full authority all the same.
        $this->withToken($token)->getJson('/api/v1/collections')->assertStatus(200);
        $this->assertDatabaseCount('organization_user', 0);
    }

    // ------------------------------------------------------ organization members

    public function test_an_owner_can_manage_their_own_organizations_members(): void
    {
        $organization = $this->organization();
        [, $token] = $this->member($organization, OrganizationRole::OWNER);
        $newcomer = User::factory()->create();

        // Previously impossible for everyone but a superadmin: the policy
        // required the unreachable `org-admin` pivot role.
        $this->withToken($token)
            ->postJson("/api/v1/organizations/{$organization->id}/users", [
                'user_id' => $newcomer->id,
                'role' => 'editor',
            ])->assertStatus(201);

        $this->withToken($token)
            ->putJson("/api/v1/organizations/{$organization->id}/users/{$newcomer->id}", [
                'role' => 'admin',
            ])->assertStatus(200);

        $this->assertDatabaseHas('organization_user', [
            'organization_id' => $organization->id,
            'user_id' => $newcomer->id,
            'role' => 'admin',
        ]);

        $this->withToken($token)
            ->deleteJson("/api/v1/organizations/{$organization->id}/users/{$newcomer->id}")
            ->assertStatus(200);

        $this->assertDatabaseMissing('organization_user', [
            'organization_id' => $organization->id,
            'user_id' => $newcomer->id,
        ]);
    }

    public function test_a_member_can_be_added_by_email(): void
    {
        // An organization admin cannot look up user ids — user search is
        // platform-only — so without this they could not invite anybody.
        $organization = $this->organization();
        [, $token] = $this->member($organization, OrganizationRole::OWNER);
        $newcomer = User::factory()->create(['email' => 'invitee@example.test']);

        $this->withToken($token)
            ->postJson("/api/v1/organizations/{$organization->id}/users", [
                'email' => 'invitee@example.test',
                'role' => 'editor',
            ])->assertStatus(201);

        $this->assertDatabaseHas('organization_user', [
            'organization_id' => $organization->id,
            'user_id' => $newcomer->id,
            'role' => 'editor',
        ]);

        $this->withToken($token)
            ->postJson("/api/v1/organizations/{$organization->id}/users", [
                'email' => 'nobody@example.test',
                'role' => 'editor',
            ])->assertStatus(404);
    }

    public function test_the_member_list_is_not_readable_by_outsiders(): void
    {
        $organization = $this->organization();
        $this->member($organization, OrganizationRole::OWNER);

        // A member of a different organization. The endpoint used to have no
        // authorization at all, so any authenticated user could read any
        // organization's member list.
        [, $outsiderToken] = $this->member($this->organization(), OrganizationRole::OWNER);

        $this->withToken($outsiderToken)
            ->getJson("/api/v1/organizations/{$organization->id}/users")
            ->assertStatus(403);
    }

    public function test_an_admin_cannot_mint_an_owner(): void
    {
        $organization = $this->organization();
        $this->member($organization, OrganizationRole::OWNER);
        [, $token] = $this->member($organization, OrganizationRole::ADMIN);
        $newcomer = User::factory()->create();

        $this->withToken($token)
            ->postJson("/api/v1/organizations/{$organization->id}/users", [
                'user_id' => $newcomer->id,
                'role' => 'owner',
            ])->assertStatus(403);
    }

    public function test_an_editor_cannot_manage_members_at_all(): void
    {
        $organization = $this->organization();
        [, $token] = $this->member($organization, OrganizationRole::EDITOR);

        $this->withToken($token)
            ->postJson("/api/v1/organizations/{$organization->id}/users", [
                'user_id' => User::factory()->create()->id,
                'role' => 'viewer',
            ])->assertStatus(403);
    }

    public function test_the_last_owner_cannot_be_demoted_or_removed(): void
    {
        $organization = $this->organization();
        [$owner, $token] = $this->member($organization, OrganizationRole::OWNER);

        $this->withToken($token)
            ->putJson("/api/v1/organizations/{$organization->id}/users/{$owner->id}", ['role' => 'admin'])
            ->assertStatus(422);

        $this->withToken($token)
            ->deleteJson("/api/v1/organizations/{$organization->id}/users/{$owner->id}")
            ->assertStatus(422);
    }

    /**
     * Owners are uncapped on purpose: with a cap of one, and the last-owner
     * guard above, ownership could never be handed over.
     */
    public function test_an_owner_can_appoint_a_second_owner(): void
    {
        $organization = $this->organization();
        [$first, $token] = $this->member($organization, OrganizationRole::OWNER);
        $successor = User::factory()->create();

        $this->withToken($token)
            ->postJson("/api/v1/organizations/{$organization->id}/users", [
                'user_id' => $successor->id,
                'role' => 'owner',
            ])->assertStatus(201);

        // …and now the original may step down, which the guard refused while
        // they were the only one.
        $this->withToken($token)
            ->putJson("/api/v1/organizations/{$organization->id}/users/{$first->id}", ['role' => 'admin'])
            ->assertStatus(200);
    }

    // ------------------------------------------------------------- authorship

    public function test_an_admin_may_edit_and_delete_a_colleagues_resource(): void
    {
        $organization = $this->organization();
        [$colleague] = $this->member($organization, OrganizationRole::EDITOR);
        [, $token] = $this->member($organization, OrganizationRole::ADMIN);

        $collection = $this->collectionFor($organization, $colleague);
        $resource = Resource::factory()->forCollection($collection->id)->create([
            'organization_id' => $organization->id,
            'user_owner_id' => $colleague->id,
            'state' => ResourceState::LIVE->value,
        ]);

        $this->withToken($token)
            ->putJson("/api/v1/resources/{$resource->id}", ['name' => 'Corrected by the admin'])
            ->assertStatus(200);

        $this->withToken($token)
            ->deleteJson("/api/v1/resources/{$resource->id}")
            ->assertStatus(200);
    }

    public function test_an_editor_may_not_edit_a_colleagues_resource(): void
    {
        $organization = $this->organization();
        [$colleague] = $this->member($organization, OrganizationRole::ADMIN);
        [, $token] = $this->member($organization, OrganizationRole::EDITOR);

        $collection = $this->collectionFor($organization, $colleague);
        $resource = Resource::factory()->forCollection($collection->id)->create([
            'organization_id' => $organization->id,
            'user_owner_id' => $colleague->id,
            'state' => ResourceState::LIVE->value,
        ]);

        $this->withToken($token)
            ->putJson("/api/v1/resources/{$resource->id}", ['name' => 'Not mine to touch'])
            ->assertStatus(403);
    }

    // --------------------------------------------------- the cross-org trash hole

    public function test_an_admin_cannot_force_delete_another_organizations_resource(): void
    {
        $mine = $this->organization();
        [, $token] = $this->member($mine, OrganizationRole::ADMIN);

        $theirs = $this->organization();
        $collection = $this->collectionFor($theirs);
        $foreign = Resource::factory()->forCollection($collection->id)->create([
            'organization_id' => $theirs->id,
            'state' => ResourceState::LIVE->value,
        ]);
        $foreign->delete();

        // The list endpoint was org-scoped; these single-id ones were not.
        $this->withToken($token)
            ->patchJson("/api/v1/resources/{$foreign->id}/restore")
            ->assertStatus(403);

        $this->withToken($token)
            ->deleteJson("/api/v1/resources/{$foreign->id}/force")
            ->assertStatus(403);
    }

    // ------------------------------------------------------------- viewer limits

    public function test_a_viewer_may_read_but_not_write(): void
    {
        $organization = $this->organization();
        [$viewer, $token] = $this->member($organization, OrganizationRole::VIEWER);
        $collection = $this->collectionFor($organization, $viewer);

        $this->withToken($token)->getJson('/api/v1/resources')->assertStatus(200);

        // Neither of these creation endpoints authorized at all before — the
        // form request validated and the controller wrote.
        $this->withToken($token)
            ->postJson('/api/v1/resources', [
                'name' => 'Nope',
                'type' => 'image',
                'state' => 'draft',
                'collection_id' => $collection->id,
            ])->assertStatus(403);

        $this->withToken($token)
            ->postJson('/api/v1/categories', ['name' => 'Nope'])
            ->assertStatus(403);
    }

    // ------------------------------------------------------ the repaired writes

    public function test_creating_an_organization_makes_the_creator_its_owner(): void
    {
        // Previously a hard SQL error: the attach passed an `id` column that
        // `organization_user` does not have.
        $organization = $this->organization();
        [$user, $token] = $this->member($organization, OrganizationRole::OWNER);

        $response = $this->withToken($token)
            ->postJson('/api/v1/organizations', ['name' => 'Brand New Org', 'type' => 'business']);
        // 'business' is an OrganizationType value; the request used to validate
        // against a different, non-existent list.

        $response->assertStatus(201);

        $this->assertDatabaseHas('organization_user', [
            'organization_id' => $response->json('data.organization.id'),
            'user_id' => $user->id,
            'role' => OrganizationRole::OWNER->value,
        ]);
    }

    public function test_registering_into_an_organization_uses_the_default_role(): void
    {
        $organization = $this->organization();

        $response = $this->postJson('/api/v1/register', [
            'name' => 'New Person',
            'email' => 'new.person@example.test',
            'password' => 'secret-password',
            'password_confirmation' => 'secret-password',
            'organization_id' => $organization->id,
        ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('organization_user', [
            'organization_id' => $organization->id,
            'role' => OrganizationRole::default()->value,
        ]);
    }

    // ------------------------------------------------- permissions on the wire

    public function test_me_reports_the_permissions_the_dashboard_gates_on(): void
    {
        $organization = $this->organization();
        [, $viewerToken] = $this->member($organization, OrganizationRole::VIEWER);

        $permissions = $this->withToken($viewerToken)
            ->getJson('/api/v1/me')
            ->assertStatus(200)
            ->json('data.user.permissions');

        // The dashboard hides the create controls off the back of this.
        $this->assertContains('resources.view', $permissions);
        $this->assertNotContains('resources.create', $permissions);
    }

    public function test_an_editor_is_told_they_may_create(): void
    {
        // A separate test rather than a second request in the one above: the
        // org context is remembered in the session, so two identities inside
        // one test do not resolve independently.
        $organization = $this->organization();
        [, $token] = $this->member($organization, OrganizationRole::EDITOR);

        $permissions = $this->withToken($token)
            ->getJson('/api/v1/me')
            ->assertStatus(200)
            ->json('data.user.permissions');

        $this->assertContains('resources.create', $permissions);
    }

    public function test_a_platform_admin_reports_platform_permissions_without_membership(): void
    {
        $organization = $this->organization();
        $token = $this->platformAdmin($organization);

        $permissions = $this->withToken($token)
            ->getJson('/api/v1/me')
            ->assertStatus(200)
            ->json('data.user.permissions');

        // Resolved from the config's `superadmin` block, which was dead
        // configuration until the effective-role bridge went in.
        $this->assertContains('resources.*', $permissions);
        $this->assertContains('platform.*', $permissions);
    }

    public function test_the_permission_matcher_understands_wildcards(): void
    {
        // The client mirrors this in constants/roles.ts; if the two disagreed,
        // the UI would offer buttons the API refuses.
        $this->assertTrue(Permissions::allows(['resources.*'], 'resources.update'));
        $this->assertTrue(Permissions::allows(['resources.*'], 'resources'));
        $this->assertTrue(Permissions::allows(['resources.view'], 'resources.view'));
        $this->assertFalse(Permissions::allows(['resources.view'], 'resources.update'));
        $this->assertFalse(Permissions::allows(['resources.*'], 'collections.update'));
        $this->assertFalse(Permissions::allows([], 'resources.view'));
    }

    // ------------------------------------------------------------------- the enum

    public function test_the_role_hierarchy_matches_the_config(): void
    {
        $this->assertTrue(OrganizationRole::OWNER->atLeast(OrganizationRole::ADMIN));
        $this->assertFalse(OrganizationRole::EDITOR->atLeast(OrganizationRole::ADMIN));

        $this->assertTrue(OrganizationRole::OWNER->canAssign(OrganizationRole::ADMIN));
        $this->assertTrue(OrganizationRole::OWNER->canAssign(OrganizationRole::OWNER));
        $this->assertFalse(OrganizationRole::ADMIN->canAssign(OrganizationRole::OWNER));
        $this->assertSame([], OrganizationRole::VIEWER->assignableRoles());

        // Descending rank, so a picker lists the strongest first.
        $this->assertSame(
            [OrganizationRole::EDITOR, OrganizationRole::VIEWER],
            OrganizationRole::ADMIN->assignableRoles(),
        );

        $this->assertNull(OrganizationRole::OWNER->limit());
    }
}
