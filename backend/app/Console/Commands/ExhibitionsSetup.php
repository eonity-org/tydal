<?php

namespace App\Console\Commands;

use App\Services\ExhibitionProvisioner;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Prepare an organization for photo exhibitions served to a gallery client
 * such as Full Frame: the photo scheme, its index, and a Photos collection.
 * Run once per organization; safe to re-run. Then `exhibitions:create`.
 */
class ExhibitionsSetup extends Command
{
    protected $signature = 'exhibitions:setup
        {--org= : Organization slug or UUID}
        {--index= : Share an existing search index (e.g. tydal_multimedia) instead of creating tydal_photo_exhibition}
        {--collection=Photos : Name of the collection to create}';

    protected $description = 'Prepare an organization for photo exhibitions (Full Frame): photo scheme, index and Photos collection';

    public function handle(ExhibitionProvisioner $provisioner): int
    {
        $organization = $provisioner->organization((string) $this->option('org'));
        if (! $organization) {
            $this->error('Organization not found. Pass --org=<slug|uuid>.');

            return self::FAILURE;
        }

        try {
            $result = $provisioner->setup(
                $organization,
                $this->option('index') !== null ? (string) $this->option('index') : null,
                (string) $this->option('collection'),
            );
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $collection = $result['collection'];
        $this->info('✓ Scheme: photo_exhibition');
        $this->info("✓ Index: {$result['index']->index_name}");
        $this->info($result['created']
            ? "✓ Collection created: {$collection->name} (id {$collection->id})"
            : "✓ Collection already set up: {$collection->name} (id {$collection->id}) — kept as is");
        if (! $result['created'] && $this->option('index') !== null
            && $this->option('index') !== $result['index']->index_name) {
            $this->warn("  --index was ignored: the existing collection uses {$result['index']->index_name}.");
        }

        $this->newLine();
        $this->line('Next, for each exhibition:');
        $this->line("  php artisan exhibitions:create --org={$organization->slug} --name=\"Exhibition name\"");

        return self::SUCCESS;
    }
}
