<?php

namespace App\Console\Commands;

use App\Console\Concerns\AsksForNewPassword;
use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Create a TYDAL account from the command line — optionally a platform admin,
 * optionally a member of one organization. A user with no organization is a
 * normal state (registration allows it too): `org:create --owner` can make
 * them the owner of a new one next.
 */
class UserCreate extends Command
{
    use AsksForNewPassword;

    /** Roles given here — ownership comes only from `org:create --owner`. */
    public const ROLES = ['viewer', 'editor', 'admin'];

    protected $signature = 'user:create
        {--email= : Email address (the login)}
        {--name= : Display name (default: from the email)}
        {--password= : Password (min 8). Omitted: asked (hidden) when interactive, else generated. Prefer the prompt — a value here lands in shell history}
        {--superadmin : Make them a platform administrator}
        {--org= : Organization slug or UUID to add them to (default: none)}
        {--role=editor : Their role in --org: viewer, editor or admin}';

    protected $description = 'Create a user account, optionally a platform admin or a member of an organization';

    public function handle(): int
    {
        $email = trim((string) $this->option('email'));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error($email === '' ? 'Pass --email=<address>.' : "{$email} is not an email address.");

            return self::FAILURE;
        }
        if (User::withTrashed()->where('email', $email)->exists()) {
            $this->error("{$email} already has a TYDAL account — nothing was created.");

            return self::FAILURE;
        }

        $organization = null;
        $role = (string) $this->option('role');
        if ($this->option('org') !== null) {
            $org = (string) $this->option('org');
            $organization = Organization::where('slug', $org)->orWhere('id', $org)->first();
            if (! $organization) {
                $this->error("Organization {$org} not found — nothing was created. Create it with org:create.");

                return self::FAILURE;
            }
            if (! in_array($role, self::ROLES, true)) {
                $this->error('--role must be one of: '.implode(', ', self::ROLES).' (owners come from org:create --owner).');

                return self::FAILURE;
            }
        }

        $given = $this->option('password');
        $password = $this->newPassword($email, $given !== null ? (string) $given : null, '--password', 'user');
        if ($password === false) {
            return self::FAILURE;
        }
        $generated = $password === null ? Str::password(16, symbols: false) : null;

        $user = DB::transaction(function () use ($email, $password, $generated, $organization, $role): User {
            $user = User::create([
                'name' => trim((string) $this->option('name')) ?: Str::before($email, '@'),
                'email' => $email,
                'password' => $password ?? $generated,
                'is_active' => true,
                'is_superadmin' => (bool) $this->option('superadmin'),
                'last_organization_id' => $organization?->id,
            ]);
            $organization?->users()->attach($user->id, ['role' => OrganizationRole::from($role)->value]);

            return $user;
        });

        $this->info("✓ User created: {$user->name} <{$user->email}>".($user->is_superadmin ? ' — platform admin' : ''));
        $this->line($organization
            ? "  Member of {$organization->name} ({$organization->slug}) as {$role}"
            : '  No organization yet — next: php artisan org:create --name="…" --owner='.$user->email);
        $this->line($generated !== null
            ? "  Password : {$generated}   (generated — shown only now; they can change it in TYDAL)"
            : '  Password : the one you chose');

        return self::SUCCESS;
    }
}
