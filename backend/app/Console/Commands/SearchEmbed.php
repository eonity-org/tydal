<?php

namespace App\Console\Commands;

use App\Enums\SystemFilePurpose;
use App\Jobs\EmbedFileChunks;
use App\Models\Collection;
use App\Models\SystemFile;
use Illuminate\Console\Command;

class SearchEmbed extends Command
{
    protected $signature = 'search:embed
        {--collection= : Only embed files in a specific collection (ID)}
        {--file=       : Only embed a specific source file (UUID)}';

    protected $description = 'Dispatch EmbedFileChunks jobs for files with extracted text';

    public function handle(): int
    {
        if ($fileId = $this->option('file')) {
            return $this->embedSingleFile($fileId);
        }

        return $this->embedAll($this->option('collection'));
    }

    private function embedSingleFile(string $fileId): int
    {
        $exists = SystemFile::where('source_file_id', $fileId)
            ->where('purpose', SystemFilePurpose::EXTRACTED_TEXT->value)
            ->where('is_active', true)
            ->exists();

        if (! $exists) {
            $this->warn("No active extracted_text record found for file {$fileId}.");

            return self::FAILURE;
        }

        EmbedFileChunks::dispatch($fileId);
        $this->info("Queued embedding for file {$fileId}.");

        return self::SUCCESS;
    }

    private function embedAll(?string $collectionId): int
    {
        $query = SystemFile::where('purpose', SystemFilePurpose::EXTRACTED_TEXT->value)
            ->where('is_active', true)
            ->with('resource');

        if ($collectionId !== null) {
            // Filter by collection: join through resources
            $resourceIds = \App\Models\Resource::where('collection_id', (int) $collectionId)
                ->pluck('id');
            $query->whereIn('resource_id', $resourceIds);
        }

        $total = $query->count();
        $queued = 0;

        if ($total === 0) {
            $this->warn('No extracted text files found to embed.');

            return self::SUCCESS;
        }

        $this->info("Queuing embeddings for {$total} file(s)…");
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $query->chunkById(100, function ($systemFiles) use (&$queued, $bar) {
            foreach ($systemFiles as $sf) {
                EmbedFileChunks::dispatch($sf->source_file_id);
                $queued++;
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine();
        $this->info("Done. {$queued} job(s) queued.");

        return self::SUCCESS;
    }
}
