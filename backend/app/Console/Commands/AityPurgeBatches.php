<?php

namespace App\Console\Commands;

use App\Enums\AityStatus;
use App\Enums\WorkspacePurpose;
use App\Models\Workspace;
use App\Services\Interfaces\WorkspaceServiceInterface;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

/**
 * Delete old AiTy Review batches once they have been dealt with.
 *
 * The upload wizard opens one `aity_review` system workspace per Auto upload,
 * so the AiTy Review page grows by one row per upload, forever. A batch is
 * finished with when either
 *   - someone opened it for review (`auto_approve_reviewed_at` set — what the
 *     AiTy Review page shows as "Reviewed"), or
 *   - its auto-approve job ended (`auto_approve_status` done or failed),
 * and it is purged once that happened more than --days days ago (measured from
 * `auto_approve_reviewed_at`, else `updated_at`). Batches whose job is still
 * pending/running, or whose members are still being analysed, are kept.
 *
 * Deleting a batch removes only the workspace and its membership rows: it goes
 * through WorkspaceService::deleteWorkspace, the same path as the Delete
 * button, which detaches and reindexes the members. Resources are never
 * deleted.
 */
class AityPurgeBatches extends Command
{
    protected $signature = 'aity:purge-batches
                            {--days= : Retention in days; default AITY_BATCH_RETENTION_DAYS (30). 0 disables}
                            {--dry-run : List what would be deleted without deleting anything}';

    protected $description = 'Delete AiTy Review batches (workspaces only, never their resources) reviewed or finished more than N days ago';

    public function handle(WorkspaceServiceInterface $workspaces): int
    {
        $days = $this->option('days') !== null
            ? (int) $this->option('days')
            : (int) config('autotagging.aity_batch_retention_days', 30);
        $dryRun = (bool) $this->option('dry-run');

        if ($days <= 0) {
            $this->info('AiTy batch retention is disabled (0 days) — nothing to do.');

            return Command::SUCCESS;
        }

        $cutoff = now()->subDays($days);

        if ($dryRun) {
            $this->warn('Dry-run mode — nothing will be deleted.');
        }

        $count = 0;

        $this->eligible($cutoff)->chunkById(100, function ($batches) use ($dryRun, $workspaces, $days, &$count) {
            foreach ($batches as $batch) {
                $count++;
                $this->line(sprintf(
                    '  %s #%d "%s" (%d resource(s), %s)',
                    $dryRun ? 'would delete' : 'deleting',
                    $batch->id,
                    $batch->name,
                    $batch->resources_count,
                    $batch->auto_approve_reviewed_at
                        ? 'reviewed '.$batch->auto_approve_reviewed_at->toDateString()
                        : 'auto-approve '.$batch->auto_approve_status.' '.$batch->updated_at?->toDateString(),
                ));

                if ($dryRun) {
                    continue;
                }

                $workspaces->deleteWorkspace((string) $batch->id);
                Log::info('Purged AiTy Review batch', [
                    'workspace_id' => $batch->id,
                    'organization_id' => $batch->organization_id,
                    'resources' => $batch->resources_count,
                    'reason' => "reviewed or finished more than {$days} day(s) ago",
                ]);
            }
        });

        $label = $dryRun ? 'Found' : 'Deleted';
        $this->info("{$label} {$count} AiTy Review batch(es) finished more than {$days} day(s) ago. Their resources were kept.");

        return Command::SUCCESS;
    }

    /**
     * @return Builder<Workspace>
     */
    private function eligible(\DateTimeInterface $cutoff): Builder
    {
        $inFlight = [AityStatus::QUEUED->value, AityStatus::AITY_IN_PROGRESS->value];

        return Workspace::query()
            ->where('purpose', WorkspacePurpose::AITY_REVIEW->value)
            // Finished with: opened for review, or the auto-approve job ended.
            ->where(fn (Builder $q) => $q
                ->whereNotNull('auto_approve_reviewed_at')
                ->orWhereIn('auto_approve_status', ['done', 'failed']))
            // Never while a job is queued or running.
            ->where(fn (Builder $q) => $q
                ->whereNull('auto_approve_status')
                ->orWhereNotIn('auto_approve_status', ['pending', 'running']))
            // Old enough: by the review date when there is one, else the last change.
            ->where(fn (Builder $q) => $q
                ->where('auto_approve_reviewed_at', '<=', $cutoff)
                ->orWhere(fn (Builder $q) => $q
                    ->whereNull('auto_approve_reviewed_at')
                    ->where('updated_at', '<=', $cutoff)))
            // Nor while any member is still being analysed.
            ->whereDoesntHave('resources', fn ($q) => $q->whereIn('resources.aity_status', $inFlight))
            ->withCount('resources');
    }
}
