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
        {--collection=Photos : Name of the collection to create}
        {--language= : Language of the photographs\' texts, an ISO code (en, es, pt-BR…). Asked when the collection is created interactively; default en. On a re-run it corrects the existing collection}';

    protected $description = 'Prepare an organization for photo exhibitions (Full Frame): photo scheme, index and Photos collection';

    public function handle(ExhibitionProvisioner $provisioner): int
    {
        $organization = $provisioner->organization((string) $this->option('org'));
        if (! $organization) {
            $this->error('Organization not found. Pass --org=<slug|uuid>.');

            return self::FAILURE;
        }

        // Only a collection about to be created needs a language chosen; an
        // existing one keeps its own unless --language says otherwise.
        $language = $this->option('language') !== null ? (string) $this->option('language') : null;
        if ($language === null && $this->input->isInteractive() && ! $provisioner->photoCollection($organization)) {
            $language = trim((string) $this->ask(
                'Language of the photographs\' texts — titles, descriptions (ISO code: en, es, fr, ca, pt-BR…)',
                ExhibitionProvisioner::DEFAULT_LANGUAGE,
            ));
        }

        try {
            $result = $provisioner->setup(
                $organization,
                $this->option('index') !== null ? (string) $this->option('index') : null,
                (string) $this->option('collection'),
                $language,
            );
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $collection = $result['collection'];
        $this->info('✓ Scheme: photo_exhibition');
        $this->info("✓ Index: {$result['index']->index_name}");
        $this->info($result['created']
            ? "✓ Collection created: {$collection->name} (id {$collection->id}, language {$collection->language})"
            : "✓ Collection already set up: {$collection->name} (id {$collection->id}, language {$collection->language})");
        if (! $result['created'] && $this->option('index') !== null
            && $this->option('index') !== $result['index']->index_name) {
            $this->warn("  --index was ignored: the existing collection uses {$result['index']->index_name}.");
        }

        // tools/clients/fullframe.sh marks its runs, so the hint names the
        // same tier-aware entry point the operator is already using.
        $create = getenv('TYDAL_VIA_FULLFRAME_SH') === '1'
            ? 'tools/clients/fullframe.sh create'
            : 'php artisan exhibitions:create';
        $this->newLine();
        $this->line('Next, for each exhibition:');
        $this->line("  {$create} --org={$organization->slug} --name=\"Exhibition name\" [--curator=email@example.org]");

        return self::SUCCESS;
    }
}
