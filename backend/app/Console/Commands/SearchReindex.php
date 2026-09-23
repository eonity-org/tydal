<?php

namespace App\Console\Commands;

use App\Models\Vault;
use App\Services\ElasticsearchService;
use Illuminate\Console\Command;

class SearchReindex extends Command
{
    protected $signature = 'search:reindex
        {--collection= : Only reindex a specific collection ID}
        {--vault= : Rebuild a vault\'s projected index (vault UUID, slug, or "all" for every non-delivery vault)}';

    protected $description = 'Rebuild Elasticsearch indexes from MySQL (rebuildable cache)';

    public function handle(ElasticsearchService $es): int
    {
        if ($this->option('vault') !== null) {
            return $this->reindexVaults($es, (string) $this->option('vault'));
        }

        $collectionId = $this->option('collection') ? (int) $this->option('collection') : null;

        if ($collectionId) {
            $this->info("Reindexing collection {$collectionId}…");
        } else {
            $this->info('Reindexing all resources…');
        }

        $result = $es->reindexAll($collectionId);

        $this->newLine();
        $this->table(
            ['Indexed', 'Skipped (no index)', 'Failed', 'Annotations Failed'],
            [[$result['indexed'], $result['skipped'], $result['failed'], $result['annotationsFailed'] ?? 0]]
        );

        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Recompose per-vault projected indexes (Epic 3.2) — drop, recreate from
     * the current resolved presentation, reindex, stamp indexed_at.
     */
    private function reindexVaults(ElasticsearchService $es, string $selector): int
    {
        $vaults = $selector === 'all'
            ? Vault::where('purpose', '!=', 'delivery')->get()
            : Vault::whereKey($selector)->orWhere('slug', $selector)->get();

        if ($vaults->isEmpty()) {
            $this->error("No vault matches \"{$selector}\".");

            return self::FAILURE;
        }

        foreach ($vaults as $vault) {
            if ($vault->isDelivery()) {
                $this->warn("  {$vault->slug}: delivery vault — no projected index.");

                continue;
            }

            $indexed = $es->reindexVault($vault);
            $vault->forceFill(['indexed_at' => now()])->saveQuietly();
            $this->info("  {$vault->slug}: {$indexed} document(s) → ".$es->buildVaultIndexName($vault));
        }

        return self::SUCCESS;
    }
}
