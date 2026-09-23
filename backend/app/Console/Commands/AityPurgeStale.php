<?php

namespace App\Console\Commands;

use App\Models\File;
use App\Models\Resource;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Mark abandoned AITY work as failed so resource cards stop showing stale
 * "queued / extracting / analysing" status, and optionally drop the matching
 * jobs from the Redis queue.
 *
 * A file is "stale" when its processing_status.stage is in
 *   [queued, extracting, ai_analyzing]
 * and the recorded queued_at is older than --hours hours ago.
 *
 * A Redis job is "stale" when its createdAt epoch (the Laravel job envelope
 * field) is older than --hours hours.
 */
class AityPurgeStale extends Command
{
    protected $signature = 'aity:purge-stale
                            {--hours=24  : Threshold in hours; files/jobs older than this are considered stale}
                            {--queue=default : Redis queue name (without prefix) to scan}
                            {--dry-run   : Report counts without modifying anything}
                            {--no-jobs   : Skip Redis queue cleanup}
                            {--no-files  : Skip file processing_status cleanup}';

    protected $description = 'Mark stale AITY file states as failed and purge matching jobs from the Redis queue.';

    // Stages that are "in flight" — anything else is terminal.
    private const STALE_STAGES = ['queued', 'extracting', 'ai_analyzing'];

    // Job classes that are part of the AITY pipeline and safe to purge if stale.
    private const AITY_JOB_CLASSES = [
        'App\\Jobs\\ExtractFileText',
        'App\\Jobs\\TranscribeAudio',
        'App\\Jobs\\AnalyzeImageContent',
        'App\\Jobs\\AutoTagResource',
        'App\\Jobs\\AnalyzeResourceContent',
        'App\\Jobs\\EmbedFileChunks',
        'App\\Jobs\\ExtractEmbeddedPreview',
        'App\\Jobs\\UpsertResourceMetadataChunk',
    ];

    public function handle(): int
    {
        $hours = (float) $this->option('hours');
        $queue = (string) $this->option('queue');
        $dryRun = (bool) $this->option('dry-run');

        $threshold = now()->subMinutes((int) round($hours * 60));

        $this->info(sprintf(
            'Threshold: %s hours ago (%s)%s',
            $hours,
            $threshold->toIso8601String(),
            $dryRun ? '  [DRY RUN]' : ''
        ));

        if (! $this->option('no-files')) {
            $this->pruneFileStates($threshold, $dryRun);
        }

        if (! $this->option('no-jobs')) {
            $this->pruneRedisJobs($queue, $threshold, $dryRun);
        }

        return self::SUCCESS;
    }

    /**
     * Mark files stuck in non-terminal AITY stages older than the threshold as failed,
     * then recompute the parent resource's aity_status.
     */
    private function pruneFileStates(Carbon $threshold, bool $dryRun): void
    {
        $this->line('');
        $this->info('Scanning stale file processing_status…');

        // Pull only candidates whose stage is in flight; filter on queued_at in PHP because the
        // JSON timestamp format varies (ISO-8601 string), and SQL JSON comparison gets messy.
        $candidates = File::query()
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(processing_status, '$.stage')) IN ('queued', 'extracting', 'ai_analyzing')")
            ->get(['id', 'resource_id', 'filename', 'processing_status']);

        $stale = $candidates->filter(function (File $f) use ($threshold) {
            $queuedAt = $f->processing_status['queued_at'] ?? null;
            if (! is_string($queuedAt)) {
                return false;
            }
            try {
                return Carbon::parse($queuedAt)->lt($threshold);
            } catch (Throwable) {
                return false;
            }
        });

        $this->line(sprintf('  Candidates in flight: %d   |   Stale (older than threshold): %d', $candidates->count(), $stale->count()));

        if ($stale->isEmpty()) {
            $this->line('  Nothing to mark failed.');

            return;
        }

        $touchedResourceIds = [];

        foreach ($stale as $f) {
            $previousStage = $f->processing_status['stage'] ?? null;
            $this->line(sprintf(
                '  [%s] %s  stage=%s  queued_at=%s',
                substr((string) $f->id, -8),
                mb_strimwidth((string) $f->filename, 0, 48, '…'),
                $previousStage,
                $f->processing_status['queued_at'] ?? '?'
            ));

            if ($dryRun) {
                continue;
            }

            $f->processing_status = array_merge($f->processing_status ?? [], [
                'stage' => 'failed',
                'failed_at' => now()->toIso8601String(),
                'error' => 'Stale '.$previousStage.' — exceeded purge threshold; worker did not complete',
                'previous_stage' => $previousStage,
            ]);
            $f->save();
            $touchedResourceIds[(string) $f->resource_id] = true;
        }

        if ($dryRun) {
            $this->line('  (dry-run — no changes written)');

            return;
        }

        // Recompute parent resource statuses so aity_status moves out of queued/aity_in_progress.
        $recomputed = 0;
        foreach (array_keys($touchedResourceIds) as $rid) {
            $resource = Resource::find($rid);
            if ($resource) {
                $resource->recomputeAndSaveAityStatus();
                $recomputed++;
            }
        }
        $this->info(sprintf('  Marked %d file(s) failed, recomputed %d resource(s).', $stale->count(), $recomputed));
    }

    /**
     * Walk the Redis queue list, identify AITY jobs whose createdAt is older than the
     * threshold, and LREM them. Safe enough because we match exact payload bytes per item.
     */
    private function pruneRedisJobs(string $queueName, Carbon $threshold, bool $dryRun): void
    {
        $this->line('');
        $this->info('Scanning Redis queue for stale AITY jobs…');

        // Laravel prefixes Redis keys with the connection prefix; queue lists become
        //   {prefix}queues:{name}. The default Redis connection is what the queue driver
        //   uses (config/queue.php's redis connection defaults to 'default').
        $connection = Redis::connection();
        $listKey = 'queues:'.$queueName;

        try {
            $payloads = $connection->lrange($listKey, 0, -1);
        } catch (Throwable $e) {
            $this->error('  Could not read Redis queue: '.$e->getMessage());

            return;
        }

        $thresholdTs = $threshold->getTimestamp();
        $total = count($payloads);
        $matched = 0;
        $removed = 0;
        $kept = 0;

        foreach ($payloads as $payload) {
            $envelope = json_decode($payload, true);
            if (! is_array($envelope)) {
                $kept++;

                continue;
            }

            $displayName = $envelope['displayName'] ?? null;
            $createdAt = $envelope['createdAt'] ?? null;

            if (! in_array($displayName, self::AITY_JOB_CLASSES, true)) {
                $kept++;

                continue;
            }
            if (! is_int($createdAt)) {
                $kept++;

                continue;
            }
            if ($createdAt >= $thresholdTs) {
                $kept++;

                continue;
            }

            $matched++;

            $this->line(sprintf(
                '  Stale job: %s  uuid=%s  created=%s',
                $displayName,
                $envelope['uuid'] ?? '?',
                date('Y-m-d H:i:s', $createdAt)
            ));

            if ($dryRun) {
                continue;
            }

            // LREM count=1 removes the first matching item (exact byte match — safe).
            $deleted = (int) $connection->lrem($listKey, 1, $payload);
            $removed += $deleted;
        }

        $this->info(sprintf(
            '  Queue length: %d   |   AITY stale candidates: %d   |   Removed: %d   |   Kept: %d%s',
            $total,
            $matched,
            $removed,
            $kept,
            $dryRun ? '  [DRY RUN]' : ''
        ));
    }
}
