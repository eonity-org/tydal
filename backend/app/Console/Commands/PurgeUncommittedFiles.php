<?php

namespace App\Console\Commands;

use App\Models\File;
use App\Services\Interfaces\ResourceServiceInterface;
use App\Services\ResourceEventLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class PurgeUncommittedFiles extends Command
{
    protected $signature = 'files:purge-uncommitted
                            {--older-than=24 : TTL in hours; files older than this are purged}
                            {--dry-run       : Report counts without deleting anything}';

    protected $description = 'Hard-delete files left in an uncommitted edit-modal session past the TTL';

    public function handle(ResourceEventLogger $eventLogger): int
    {
        $hours = (int) $this->option('older-than');
        $dryRun = (bool) $this->option('dry-run');
        $cutoff = now()->subHours($hours);
        $count = 0;

        if ($dryRun) {
            $this->warn('Dry-run mode — nothing will be deleted.');
        }

        File::uncommitted()
            ->where('uncommitted_at', '<=', $cutoff)
            ->chunkById(100, function ($files) use ($dryRun, &$count, $eventLogger) {
                foreach ($files as $file) {
                    $count++;
                    if ($dryRun) {
                        continue;
                    }
                    $this->purgeFile($file, $eventLogger);
                }
            });

        $label = $dryRun ? 'Found' : 'Purged';
        $this->info("{$label} {$count} uncommitted file(s) older than {$hours}h.");

        return Command::SUCCESS;
    }

    private function purgeFile(File $file, ResourceEventLogger $eventLogger): void
    {
        $resourceId = $file->resource_id;
        $filename = $file->filename;
        $fileId = $file->id;

        // Remove derived artifacts (on-disk archives + FileChunk rows + system_files rows)
        // before the FK gets nulled by the cascade on $file->delete().
        app(ResourceServiceInterface::class)->purgeFileArtifacts($file);

        if ($file->media_id) {
            // Spatie MediaLibrary owns the on-disk bytes for image/video/audio — delete the
            // Media row to trigger its own storage cleanup.
            Media::where('id', $file->media_id)->each(fn ($m) => $m->delete());
        } else {
            // Plain files were uploaded to {disk}/{path} directly; delete the blob.
            try {
                Storage::disk($file->disk)->delete($file->path);
            } catch (\Throwable $e) {
                $this->warn("Could not delete blob for file {$fileId}: ".$e->getMessage());
            }
        }

        $file->delete();

        $eventLogger->log(
            resourceId: $resourceId,
            eventType: 'file_uncommitted_purged',
            actorType: ResourceEventLogger::ACTOR_SYSTEM,
            payload: [
                'file_id' => $fileId,
                'filename' => $filename,
                'reason' => 'uncommitted past TTL',
            ],
        );
    }
}
