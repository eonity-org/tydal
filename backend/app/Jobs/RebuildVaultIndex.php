<?php

namespace App\Jobs;

use App\Models\Vault;
use App\Services\ElasticsearchService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Recompose one vault's projected ES index (Epic 3.2) — drop, recreate from
 * the current resolved presentation, reindex every projected resource, then
 * stamp indexed_at so vault search switches to the ES path.
 */
class RebuildVaultIndex implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $backoff = 15;

    public function __construct(private readonly string $vaultId) {}

    public function handle(ElasticsearchService $es): void
    {
        $vault = Vault::find($this->vaultId);

        if (! $vault) {
            return;
        }

        $indexed = $es->reindexVault($vault);

        // Delivery vaults produce no presentation → no index, stays NULL
        $vault->forceFill(['indexed_at' => $indexed > 0 || ! $vault->isDelivery() ? now() : null])->saveQuietly();
    }
}
