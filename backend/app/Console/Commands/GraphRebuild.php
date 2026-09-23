<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Services\ResourceGraphService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Rebuild the auto-asserted layers of the resource graph (Epic 4.3).
 * Each origin is its own rebuild domain: --tags recomputes tag
 * co-occurrence edges, --semantic recomputes embedding k-NN edges; neither
 * ever touches curator (`manual`) edges. No flag = both.
 */
class GraphRebuild extends Command
{
    protected $signature = 'graph:rebuild
        {--org= : Organization UUID or slug (default: every organization)}
        {--tags : Rebuild tag co-occurrence edges}
        {--semantic : Rebuild embedding-similarity edges}
        {--min-shared=2 : Minimum shared tags for a tag edge}
        {--k=5 : Neighbors considered per resource for semantic edges}
        {--min-score=0.75 : Minimum k-NN score for a semantic edge}';

    protected $description = 'Rebuild auto (tags/semantic) resource-relation edges';

    public function handle(ResourceGraphService $graph): int
    {
        $orgs = $this->resolveOrgs();

        if ($orgs->isEmpty()) {
            $this->error('No matching organization.');

            return self::FAILURE;
        }

        $doTags = (bool) $this->option('tags');
        $doSemantic = (bool) $this->option('semantic');
        if (! $doTags && ! $doSemantic) {
            $doTags = $doSemantic = true;
        }

        foreach ($orgs as $org) {
            $this->info("{$org->name} ({$org->slug})");

            if ($doTags) {
                $count = $graph->rebuildTagRelations($org->id, (int) $this->option('min-shared'));
                $this->line("  tags edges: {$count}");
            }

            if ($doSemantic) {
                $count = $graph->rebuildSemanticRelations(
                    $org->id,
                    (int) $this->option('k'),
                    (float) $this->option('min-score'),
                );
                $this->line("  semantic edges: {$count}");
            }
        }

        return self::SUCCESS;
    }

    /** @return Collection<int, Organization> */
    private function resolveOrgs(): Collection
    {
        $org = $this->option('org');

        if ($org === null) {
            return Organization::all();
        }

        return Organization::where('id', $org)->orWhere('slug', $org)->get();
    }
}
