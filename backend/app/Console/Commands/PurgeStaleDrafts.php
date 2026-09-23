<?php

namespace App\Console\Commands;

use App\Enums\ResourceState;
use App\Models\Resource;
use App\Services\Interfaces\ResourceServiceInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class PurgeStaleDrafts extends Command
{
    protected $signature = 'resources:purge-drafts
                            {--older-than=1 : TTL in hours; draft resources untouched longer than this are purged}
                            {--dry-run      : Report counts without deleting anything}';

    protected $description = 'Hard-delete abandoned draft resources left by a closed/crashed create session past the TTL';

    public function handle(ResourceServiceInterface $resourceService): int
    {
        $hours = (int) $this->option('older-than');
        $dryRun = (bool) $this->option('dry-run');
        $cutoff = now()->subHours($hours);
        $count = 0;

        if ($dryRun) {
            $this->warn('Dry-run mode — nothing will be deleted.');
        }

        // Drafts are never trashed — deleteResource() hard-deletes them outright (see
        // ResourceService::deleteResource). So the only drafts the reaper finds are
        // ones the frontend never got to clean up (tab close / crash).
        Resource::where('state', ResourceState::DRAFT->value)
            ->where('updated_at', '<=', $cutoff)
            ->chunkById(100, function ($drafts) use ($dryRun, $resourceService, $hours, &$count) {
                foreach ($drafts as $draft) {
                    $count++;
                    if ($dryRun) {
                        continue;
                    }
                    // Reuse the canonical hard-delete: files, Spatie media collection,
                    // public + local storage dirs, then forceDelete().
                    $resourceService->permanentlyDelete($draft);
                    Log::info('Purged abandoned draft resource', [
                        'resource_id' => $draft->id,
                        'reason' => "draft untouched past {$hours}h TTL",
                    ]);
                }
            });

        $label = $dryRun ? 'Found' : 'Purged';
        $this->info("{$label} {$count} abandoned draft resource(s) older than {$hours}h.");

        return Command::SUCCESS;
    }
}
