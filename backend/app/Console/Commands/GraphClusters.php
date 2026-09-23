<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Services\ResourceGraphService;
use Illuminate\Console\Command;

/**
 * Read-only view of the graph's connected components (Epic 4.3) — the raw
 * material Epic 4.5 will materialize into auto-named cluster vaults.
 * Run `graph:rebuild` first; components go over `related` edges of every
 * origin (manual + tags + semantic).
 */
class GraphClusters extends Command
{
    protected $signature = 'graph:clusters
        {--org= : Organization UUID or slug (default: every organization)}
        {--json : Machine-readable output}';

    protected $description = 'Show resource clusters (connected components of the relation graph)';

    public function handle(ResourceGraphService $graph): int
    {
        $orgs = $this->option('org') === null
            ? Organization::all()
            : Organization::where('id', $this->option('org'))->orWhere('slug', $this->option('org'))->get();

        if ($orgs->isEmpty()) {
            $this->error('No matching organization.');

            return self::FAILURE;
        }

        $payload = [];

        foreach ($orgs as $org) {
            $clusters = $graph->clusters($org->id);
            $payload[$org->slug] = $clusters;

            if ($this->option('json')) {
                continue;
            }

            $this->info("{$org->name} ({$org->slug}) — ".count($clusters).' cluster(s)');

            foreach ($clusters as $i => $cluster) {
                $this->line(sprintf(
                    '  #%d  %d resources · tags: %s',
                    $i + 1,
                    $cluster['size'],
                    $cluster['top_tags'] === [] ? '—' : implode(', ', $cluster['top_tags']),
                ));
                $this->line('      '.implode(' · ', $cluster['names']).($cluster['size'] > 5 ? ' …' : ''));
            }
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }

        return self::SUCCESS;
    }
}
