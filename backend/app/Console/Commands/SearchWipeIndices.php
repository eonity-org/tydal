<?php

namespace App\Console\Commands;

use App\Services\ElasticsearchService;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;

class SearchWipeIndices extends Command
{
    use ConfirmableTrait;

    protected $signature = 'search:wipe-indices {--force : Skip the confirmation prompt}';

    protected $description = 'DEV ONLY: delete every tydal_*/vault_* Elasticsearch index — migrate:fresh never touches ES, so stale/orphaned indices otherwise survive a DB reset';

    public function handle(ElasticsearchService $es): int
    {
        if (! $this->confirmToProceed(
            'This deletes ALL tydal_*/vault_* Elasticsearch indices on this cluster.'
        )) {
            return self::FAILURE;
        }

        $deleted = $es->deleteIndicesMatching('tydal_*,vault_*');

        if (empty($deleted)) {
            $this->info('✓ No tydal_*/vault_* indices found — nothing to wipe');
        } else {
            $this->info('✓ Wiped '.count($deleted).' index(es): '.implode(', ', $deleted));
        }

        return self::SUCCESS;
    }
}
