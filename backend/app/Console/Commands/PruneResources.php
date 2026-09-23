<?php

namespace App\Console\Commands;

use App\Enums\ResourceState;
use App\Models\Resource;
use App\Services\Interfaces\ResourceServiceInterface;
use Illuminate\Console\Command;

class PruneResources extends Command
{
    protected $signature = 'resource:prune
                            {--draft-hours=24  : Permanently delete draft resources older than this many hours}
                            {--deleted-days=30 : Permanently delete soft-deleted resources older than this many days}
                            {--dry-run         : Report counts without deleting anything}';

    protected $description = 'Permanently delete abandoned draft resources and expired soft-deleted resources';

    public function handle(ResourceServiceInterface $resourceService): int
    {
        $draftHours = (int) $this->option('draft-hours');
        $deletedDays = (int) $this->option('deleted-days');
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->warn('Dry-run mode — nothing will be deleted.');
        }

        $this->pruneDrafts($draftHours, $dryRun, $resourceService);
        $this->pruneSoftDeleted($deletedDays, $dryRun, $resourceService);

        return Command::SUCCESS;
    }

    private function pruneDrafts(int $hours, bool $dryRun, ResourceServiceInterface $resourceService): void
    {
        $cutoff = now()->subHours($hours);
        $count = 0;

        Resource::where('state', ResourceState::DRAFT->value)
            ->where('created_at', '<=', $cutoff)
            ->chunk(100, function ($resources) use ($dryRun, &$count, $resourceService) {
                foreach ($resources as $resource) {
                    $count++;
                    if (! $dryRun) {
                        $resourceService->permanentlyDelete($resource);
                    }
                }
            });

        $label = $dryRun ? 'Found' : 'Deleted';
        $this->info("[draft]        {$label} {$count} resource(s) abandoned for more than {$hours}h.");
    }

    private function pruneSoftDeleted(int $days, bool $dryRun, ResourceServiceInterface $resourceService): void
    {
        $cutoff = now()->subDays($days);
        $count = 0;

        Resource::onlyTrashed()
            ->where('deleted_at', '<=', $cutoff)
            ->chunk(100, function ($resources) use ($dryRun, &$count, $resourceService) {
                foreach ($resources as $resource) {
                    $count++;
                    if (! $dryRun) {
                        $resourceService->permanentlyDelete($resource);
                    }
                }
            });

        $label = $dryRun ? 'Found' : 'Deleted';
        $this->info("[soft-deleted] {$label} {$count} resource(s) trashed for more than {$days}d.");
    }
}
