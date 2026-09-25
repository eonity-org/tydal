<?php

namespace App\Console\Commands;

use App\Services\ExhibitionProvisioner;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Create everything one photo exhibition needs in TYDAL — a workspace, a
 * private gallery vault showing it (uploads land there too), and a read + a
 * write key — and print what the curator pastes into Full Frame's
 * "Connect an exhibition". Run `exhibitions:setup` for the organization first.
 */
class ExhibitionsCreate extends Command
{
    protected $signature = 'exhibitions:create
        {--org= : Organization slug or UUID}
        {--name= : Exhibition name (the vault and workspace are named after it)}
        {--slug= : Vault address slug (default: from the name)}
        {--curator= : Email of the curator who runs it in Full Frame (TYDAL user created or reused)}
        {--curator-name= : Name for a newly created curator (default: from the email)}
        {--role=editor : Curator role in the organization: viewer (read-only), editor or admin}';

    protected $description = 'Create a photo exhibition (Full Frame): workspace, private gallery vault and its read + write keys';

    public function handle(ExhibitionProvisioner $provisioner): int
    {
        $organization = $provisioner->organization((string) $this->option('org'));
        if (! $organization) {
            $this->error('Organization not found. Pass --org=<slug|uuid>.');

            return self::FAILURE;
        }
        $name = trim((string) $this->option('name'));
        if ($name === '') {
            $this->error('Pass --name="Exhibition name".');

            return self::FAILURE;
        }

        // Checked before anything is created: the exhibition's keys are shown
        // only once, so a bad curator option must not cut the output short.
        $curatorEmail = $this->option('curator') !== null ? (string) $this->option('curator') : null;
        $role = (string) $this->option('role');
        if ($curatorEmail !== null && ! filter_var($curatorEmail, FILTER_VALIDATE_EMAIL)) {
            $this->error("{$curatorEmail} is not an email address.");

            return self::FAILURE;
        }
        if ($curatorEmail !== null && ! in_array($role, ExhibitionProvisioner::CURATOR_ROLES, true)) {
            $this->error('--role must be one of: '.implode(', ', ExhibitionProvisioner::CURATOR_ROLES).'.');

            return self::FAILURE;
        }

        try {
            $result = $provisioner->create(
                $organization,
                $name,
                $this->option('slug') !== null ? (string) $this->option('slug') : null,
            );
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $vault = $result['vault'];
        $base = rtrim((string) config('app.url'), '/');

        $this->info("✓ Workspace: {$result['workspace']->name}");
        $this->info("✓ Vault: {$vault->name} (gallery, private) — uploads land in it");
        $this->info('✓ Keys minted — shown only now, store them');
        $this->newLine();
        $this->line('Paste into Full Frame → "Connect an exhibition":');
        $this->line("  Shared vault URL : {$base}/v/{$organization->slug}/{$vault->slug}");
        $this->line("  Read key         : {$result['read_key']}");
        $this->line("  Write key        : {$result['write_key']}");
        $this->newLine();
        $this->line("Machine address: {$base}/h/{$vault->hash}");

        if ($curatorEmail === null) {
            return self::SUCCESS;
        }
        try {
            $curator = $provisioner->curator(
                $organization,
                $curatorEmail,
                $this->option('curator-name') !== null ? (string) $this->option('curator-name') : null,
                $role,
            );
        } catch (RuntimeException $e) {
            $this->newLine();
            $this->error("Curator not added: {$e->getMessage()} The exhibition above is ready; add them in TYDAL.");

            return self::FAILURE;
        }

        $this->newLine();
        $this->line("Curator — signs into Full Frame's studio with their TYDAL account ({$curator['role']}):");
        $this->line("  Email    : {$curator['user']->email}");
        $this->line($curator['password'] !== null
            ? "  Password : {$curator['password']}   (new account — shown only now; they can change it in TYDAL)"
            : '  Password : their existing TYDAL password');

        return self::SUCCESS;
    }
}
