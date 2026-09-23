<?php

namespace App\Console\Commands;

use App\Models\SearchIndex;
use App\Services\ElasticsearchService;
use Illuminate\Console\Command;

class SearchSetupIndices extends Command
{
    protected $signature = 'search:setup-indices
        {--index=    : Only set up a specific index by name}
        {--recreate  : Drop and recreate the index (required when mappings conflict}';

    protected $description = 'Create or update Elasticsearch indexes from the search_indexes table';

    public function handle(ElasticsearchService $es): int
    {
        $query = SearchIndex::where('is_active', true);

        if ($name = $this->option('index')) {
            $query->where('index_name', $name);
        }

        $indexes = $query->get();

        if ($indexes->isEmpty()) {
            $this->warn('No active search indexes found.');

            return self::SUCCESS;
        }

        $this->info("Setting up {$indexes->count()} index(es)…");

        $ok = $fail = 0;

        $recreate = (bool) $this->option('recreate');

        foreach ($indexes as $index) {
            try {
                $es->setupIndex($index, $recreate);
                $this->line("  <fg=green>✓</> {$index->index_name}");

                $es->setupChunksIndex($index, $recreate);
                $chunksName = $es->buildChunksIndexName($index->index_name);
                $this->line("  <fg=green>✓</> {$chunksName}");

                $ok++;
            } catch (\Throwable $e) {
                $this->line("  <fg=red>✗</> {$index->index_name}: {$e->getMessage()}");
                $fail++;
            }
        }

        $this->newLine();
        $this->info("Done. OK: {$ok}  Failed: {$fail}");

        return $fail > 0 ? self::FAILURE : self::SUCCESS;
    }
}
