<?php

namespace App\Console\Commands;

use App\Enums\OrganizationType;
use App\Models\Organization;
use App\Models\User;
use App\Services\Interfaces\OrganizationServiceInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Create an organization from the command line, owned by an existing user
 * (`user:create` first) or, without --owner, by the oldest platform admin —
 * with its default "All Resources" workspace, exactly as the admin UI does.
 */
class OrgCreate extends Command
{
    protected $signature = 'org:create
        {--name= : Organization name}
        {--slug= : Address slug (default: from the name)}
        {--type=business : individual, business, educational, government or non_profit}
        {--description= : Optional description}
        {--owner= : Email of an existing user who owns it (default: the oldest platform admin)}';

    protected $description = 'Create an organization owned by an existing user, with its default workspace';

    public function handle(OrganizationServiceInterface $organizations): int
    {
        $name = trim((string) $this->option('name'));
        if ($name === '') {
            $this->error('Pass --name="Organization name".');

            return self::FAILURE;
        }

        $slug = Str::slug($this->option('slug') !== null ? (string) $this->option('slug') : $name);
        if ($slug === '') {
            $this->error('The slug would be empty — pass --slug=<letters, digits, dashes>.');

            return self::FAILURE;
        }
        if (Organization::where('slug', $slug)->exists()) {
            $this->error("An organization with slug {$slug} already exists — nothing was created.");

            return self::FAILURE;
        }

        $type = OrganizationType::tryFrom((string) $this->option('type'));
        if (! $type) {
            $this->error('--type must be one of: '.implode(', ', array_column(OrganizationType::cases(), 'value')).'.');

            return self::FAILURE;
        }

        $email = $this->option('owner') !== null ? trim((string) $this->option('owner')) : null;
        if ($email !== null) {
            $owner = User::where('email', $email)->first();
            if (! $owner) {
                $this->error("No user {$email} — create them first: php artisan user:create --email={$email}");

                return self::FAILURE;
            }
        } else {
            $owner = User::where('is_superadmin', true)->oldest()->first();
            if (! $owner) {
                $this->error('Pass --owner=<email>: there is no platform admin to own it.');

                return self::FAILURE;
            }
        }

        $organization = $organizations->createOrganization(array_filter([
            'name' => $name,
            'slug' => $slug,
            'type' => $type->value,
            'description' => $this->option('description'),
            'is_active' => true,
        ], fn ($value) => $value !== null), $owner);

        $this->info("✓ Organization created: {$organization->name} (slug {$organization->slug}, id {$organization->id})");
        $this->info("✓ Owner: {$owner->name} <{$owner->email}>".($email === null ? ' — the oldest platform admin (no --owner given)' : ''));
        $this->info('✓ Default workspace: All Resources');
        $this->newLine();
        $this->line('Next, if it runs photo exhibitions (FullFrame):');
        $this->line("  tools/clients/fullframe.sh setup --org={$organization->slug}");

        return self::SUCCESS;
    }
}
