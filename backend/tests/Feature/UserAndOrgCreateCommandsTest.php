<?php

namespace Tests\Feature;

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Interfaces\OrganizationServiceInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

/**
 * `user:create` / `org:create` — accounts and organizations from the command
 * line, in two composable steps: a user can exist without an organization,
 * and `org:create --owner` makes them the owner of a new one.
 */
class UserAndOrgCreateCommandsTest extends TestCase
{
    use RefreshDatabase;

    // =========================================================================
    // user:create
    // =========================================================================

    public function test_creates_a_user_without_an_organization(): void
    {
        $this->artisan('user:create', ['--email' => 'owner@lucila.org', '--password' => 'chosen-secret-1', '--no-interaction' => true])
            ->expectsOutputToContain('No organization yet')
            ->assertSuccessful();

        $user = User::where('email', 'owner@lucila.org')->firstOrFail();
        $this->assertSame('owner', $user->name);
        $this->assertFalse($user->is_superadmin);
        $this->assertNull($user->last_organization_id);
        $this->assertSame(0, $user->organizations()->count());
        $this->assertTrue(Hash::check('chosen-secret-1', $user->password));
    }

    public function test_the_password_is_asked_hidden_and_confirmed_or_generated(): void
    {
        $this->artisan('user:create', ['--email' => 'a@example.org'])
            ->expectsQuestion('Password for the new user a@example.org (leave empty to generate one)', 'typed-secret-9')
            ->expectsQuestion('Repeat the password', 'typed-secret-9')
            ->expectsOutputToContain('the one you chose')
            ->assertSuccessful();
        $this->assertTrue(Hash::check('typed-secret-9', User::where('email', 'a@example.org')->value('password')));

        $this->artisan('user:create', ['--email' => 'b@example.org'])
            ->expectsQuestion('Password for the new user b@example.org (leave empty to generate one)', '')
            ->expectsOutputToContain('generated')
            ->assertSuccessful();
    }

    public function test_a_bad_password_or_email_creates_nothing(): void
    {
        $this->artisan('user:create', ['--email' => 'a@example.org'])
            ->expectsQuestion('Password for the new user a@example.org (leave empty to generate one)', 'typed-secret-9')
            ->expectsQuestion('Repeat the password', 'typo-secret-9')
            ->expectsOutputToContain('do not match')
            ->assertFailed();
        $this->artisan('user:create', ['--email' => 'a@example.org', '--password' => 'short'])
            ->expectsOutputToContain('at least 8 characters')
            ->assertFailed();
        $this->artisan('user:create', ['--email' => 'not-an-email', '--no-interaction' => true])->assertFailed();

        $this->assertSame(0, User::count());
    }

    public function test_refuses_an_existing_account(): void
    {
        User::factory()->create(['email' => 'known@example.org', 'password' => Hash::make('their-own-pass')]);

        $this->artisan('user:create', ['--email' => 'known@example.org', '--password' => 'attempted-overwrite'])
            ->expectsOutputToContain('already has a TYDAL account')
            ->assertFailed();
        $this->assertTrue(Hash::check('their-own-pass', User::where('email', 'known@example.org')->value('password')));
    }

    public function test_can_create_a_platform_admin(): void
    {
        $this->artisan('user:create', ['--email' => 'ops@example.org', '--superadmin' => true, '--no-interaction' => true])
            ->expectsOutputToContain('platform admin')
            ->assertSuccessful();

        $this->assertTrue(User::where('email', 'ops@example.org')->value('is_superadmin'));
    }

    public function test_can_join_an_existing_organization_but_never_as_owner(): void
    {
        $organization = Organization::factory()->create(['slug' => 'lucila']);

        $this->artisan('user:create', ['--email' => 'ed@example.org', '--org' => 'lucila', '--role' => 'owner', '--no-interaction' => true])
            ->expectsOutputToContain('--role must be one of')
            ->assertFailed();
        $this->artisan('user:create', ['--email' => 'ed@example.org', '--org' => 'nobody', '--no-interaction' => true])
            ->expectsOutputToContain('not found')
            ->assertFailed();
        $this->assertSame(0, User::count());

        $this->artisan('user:create', ['--email' => 'ed@example.org', '--org' => 'lucila', '--role' => 'viewer', '--no-interaction' => true])
            ->assertSuccessful();

        $user = User::where('email', 'ed@example.org')->firstOrFail();
        $this->assertSame('viewer', $organization->users()->where('users.id', $user->id)->first()->pivot->role);
        $this->assertSame($organization->id, $user->last_organization_id);
    }

    // =========================================================================
    // org:create
    // =========================================================================

    public function test_creates_an_organization_owned_by_an_existing_user(): void
    {
        $this->artisan('user:create', ['--email' => 'owner@lucila.org', '--no-interaction' => true])->assertSuccessful();
        $owner = User::where('email', 'owner@lucila.org')->firstOrFail();

        $this->artisan('org:create', ['--name' => 'Lucila', '--owner' => 'owner@lucila.org'])
            ->expectsOutputToContain('slug lucila')
            ->expectsOutputToContain('fullframe.sh setup --org=lucila')
            ->assertSuccessful();

        $organization = Organization::where('slug', 'lucila')->firstOrFail();
        $this->assertSame('business', $organization->type);
        $this->assertSame(OrganizationRole::OWNER->value, $organization->users()->where('users.id', $owner->id)->first()->pivot->role);
        $this->assertSame($organization->id, $owner->fresh()->last_organization_id);

        $workspace = Workspace::where('organization_id', $organization->id)->where('is_default', true)->firstOrFail();
        $this->assertSame($owner->id, $workspace->user_owner_id);
    }

    public function test_without_owner_the_oldest_platform_admin_owns_it(): void
    {
        $admin = User::factory()->create(['is_superadmin' => true]);

        $this->artisan('org:create', ['--name' => 'Lucila', '--slug' => 'lu'])
            ->expectsOutputToContain('oldest platform admin')
            ->assertSuccessful();

        $organization = Organization::where('slug', 'lu')->firstOrFail();
        $this->assertTrue($organization->users()->where('users.id', $admin->id)->wherePivot('role', 'owner')->exists());
    }

    public function test_refuses_a_missing_owner_a_taken_slug_or_a_bad_type(): void
    {
        $this->artisan('org:create', ['--name' => 'Lucila', '--owner' => 'nobody@example.org'])
            ->expectsOutputToContain('user:create --email=nobody@example.org')
            ->assertFailed();
        $this->artisan('org:create', ['--name' => 'Lucila'])
            ->expectsOutputToContain('no platform admin')
            ->assertFailed();

        $owner = User::factory()->create(['email' => 'o@example.org']);
        $this->artisan('org:create', ['--name' => 'Lucila', '--owner' => 'o@example.org', '--type' => 'club'])
            ->expectsOutputToContain('--type must be one of')
            ->assertFailed();
        $this->assertSame(0, Organization::count());

        Organization::factory()->create(['slug' => 'lucila']);
        $this->artisan('org:create', ['--name' => 'Lucila', '--owner' => $owner->email])
            ->expectsOutputToContain('already exists')
            ->assertFailed();
    }

    public function test_an_owned_user_keeps_the_organization_they_already_land_in(): void
    {
        $first = Organization::factory()->create();
        $owner = User::factory()->create(['email' => 'o@example.org', 'last_organization_id' => $first->id]);

        $this->artisan('org:create', ['--name' => 'Second', '--owner' => 'o@example.org'])->assertSuccessful();

        $this->assertSame($first->id, $owner->fresh()->last_organization_id);
    }

    // =========================================================================
    // the repaired service
    // =========================================================================

    public function test_the_service_refuses_to_create_an_organization_without_an_owner(): void
    {
        // Used to write the organization's own id into workspaces.user_owner_id
        // (a users FK) whenever nobody was signed in — i.e. from any CLI path.
        $this->expectException(RuntimeException::class);

        app(OrganizationServiceInterface::class)->createOrganization(['name' => 'Orphan', 'slug' => 'orphan']);
    }
}
