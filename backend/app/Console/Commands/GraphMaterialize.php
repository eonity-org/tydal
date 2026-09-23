<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Services\ClusterVaultService;
use Illuminate\Console\Command;

/**
 * Epic 4.5 — materialize graph clusters as auto-named vaults. Run
 * `graph:rebuild` first (edges), optionally `graph:clusters` to preview;
 * this command writes: one vault per cluster (member-overlap matching keeps
 * slugs/hashes stable across runs), stale cluster vaults retired.
 */
class GraphMaterialize extends Command
{
    protected $signature = 'graph:materialize
        {--org= : Organization UUID or slug (default: every organization)}
        {--min-size=2 : Smallest cluster that becomes a vault}';

    protected $description = 'Materialize graph clusters as auto-created vaults';

    public function handle(ClusterVaultService $clusterVaults): int
    {
        $orgs = $this->option('org') === null
            ? Organization::all()
            : Organization::where('id', $this->option('org'))->orWhere('slug', $this->option('org'))->get();

        if ($orgs->isEmpty()) {
            $this->error('No matching organization.');

            return self::FAILURE;
        }

        foreach ($orgs as $org) {
            $summary = $clusterVaults->rebuild($org, minSize: (int) $this->option('min-size'));

            $this->info(sprintf(
                '%s (%s) — %d cluster(s): %d created, %d updated, %d retired',
                $org->name,
                $org->slug,
                $summary['clusters'],
                $summary['created'],
                $summary['updated'],
                $summary['deleted'],
            ));

            foreach ($summary['vaults'] as $vault) {
                $this->line("  {$vault['slug']}  ·  {$vault['name']}  ·  {$vault['size']} resources");
            }
        }

        return self::SUCCESS;
    }
}
