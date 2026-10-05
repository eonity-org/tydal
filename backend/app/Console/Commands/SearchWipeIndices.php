<?php

namespace App\Console\Commands;

use App\Services\ElasticsearchService;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;

class SearchWipeIndices extends Command
{
    use ConfirmableTrait;

    protected $signature = 'search:wipe-indices {--force : Skip the confirmation prompt}';

    protected $description = 'DEV ONLY: delete this installation\'s {prefix}tydal_*/{prefix}vault_* Elasticsearch indices — migrate:fresh never touches ES, so stale/orphaned indices otherwise survive a DB reset';

    public function handle(ElasticsearchService $es): int
    {
        // Scoped by ELASTICSEARCH_INDEX_PREFIX. A prefix can't contain
        // wildcards or start with tydal_/vault_ (ElasticsearchService::
        // indexPrefix), so a prefixed pattern only ever reaches this
        // installation, and an unprefixed one never reaches a prefixed
        // installation. It CAN reach another unprefixed installation on the
        // same cluster — there is no way to tell their indices apart.
        $pattern = $es->wipePattern();
        $unprefixed = $es->indexPrefix() === '';

        if ($unprefixed) {
            $this->warn('ELASTICSEARCH_INDEX_PREFIX is empty: this also deletes the indices of any other unprefixed TYDAL installation on this cluster.');
        }

        if (! $this->confirmToProceed(
            "This deletes ALL {$pattern} Elasticsearch indices on this cluster."
        )) {
            return self::FAILURE;
        }

        $deleted = $es->deleteIndicesMatching($pattern);

        if (empty($deleted)) {
            $this->info("✓ No {$pattern} indices found — nothing to wipe");
        } else {
            $this->info('✓ Wiped '.count($deleted).' index(es): '.implode(', ', $deleted));
        }

        return self::SUCCESS;
    }
}
