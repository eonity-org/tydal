<?php

namespace App\Jobs;

use App\Models\Resource;
use App\Services\ElasticsearchService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Bring a batch of resources' search documents back in line with the database.
 *
 * The single-resource jobs are dispatched synchronously from the model's boot
 * hooks so an interactive edit is searchable the moment it returns. A bulk
 * action cannot afford that: 200 resources would mean 200 inline Elasticsearch
 * round-trips inside one HTTP request. Bulk writers therefore save quietly
 * (`updateQuietly` / pivot writes, neither of which fires the hooks) and queue
 * this job once for the whole batch.
 *
 * It syncs rather than indexes: a bulk state change to `archived` has to
 * REMOVE documents, so the direction is decided per resource from its state,
 * not by the caller. Resources that vanished between the write and the worker
 * picking the job up are treated as deletions.
 *
 * Requires a running queue worker — see the dev setup in CLAUDE.md.
 */
class SyncResourcesToElasticsearch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 10;

    /**
     * Batches at or under this size run inline, so a caller that reindexes and
     * then immediately re-queries sees its own change.
     *
     * Every other write in TYDAL indexes synchronously (the model boot hooks
     * use dispatchSync), so the dashboard is built to refetch straight away.
     * Queueing every bulk reindex broke that contract: the grid and the facets
     * came back showing pre-change data. A basket is capped at 200 but is
     * usually a handful, so the common case keeps read-your-writes and only
     * genuinely large batches pay with a delay — and those say so.
     */
    public const SYNC_THRESHOLD = 25;

    /** @param  array<int, string>  $resourceIds */
    public function __construct(private readonly array $resourceIds) {}

    /**
     * Reindex now if the batch is small, otherwise hand it to the queue.
     *
     * @param  array<int, string>  $resourceIds
     * @return string 'immediate' when the caller may refetch at once, 'queued' otherwise.
     */
    public static function run(array $resourceIds): string
    {
        if (empty($resourceIds)) {
            return 'immediate';
        }

        if (count($resourceIds) <= self::SYNC_THRESHOLD) {
            static::dispatchSync($resourceIds);

            return 'immediate';
        }

        static::dispatch($resourceIds);

        return 'queued';
    }

    /** @return array<int, string> */
    public function getResourceIds(): array
    {
        return $this->resourceIds;
    }

    public function handle(ElasticsearchService $es): void
    {
        if (empty($this->resourceIds)) {
            return;
        }

        $found = Resource::with('collection.searchIndex')
            ->whereIn('id', $this->resourceIds)
            ->get()
            ->keyBy('id');

        $touchedIndexes = [];

        foreach ($this->resourceIds as $resourceId) {
            $resource = $found->get($resourceId);

            if (! $resource || ! $resource->state->isVisible()) {
                $es->deleteResource($resourceId);
                $es->removeResourceFromVaultIndexes($resourceId);

                continue;
            }

            $es->indexResource($resource);
            $es->indexResourceIntoVaults($resource);

            $indexName = $resource->collection?->searchIndex?->index_name;
            if ($indexName) {
                $touchedIndexes[$indexName] = true;
            }
        }

        // Elasticsearch is near-real-time: a write is not visible to a search
        // until the next refresh, up to a second later by default. Nothing in
        // the indexing path asks for one, so a caller that reindexes and then
        // immediately re-queries — exactly what the dashboard does after a
        // bulk action — could read its own change back as stale. Refreshing
        // the indices this run actually touched closes that window without
        // forcing a refresh on every single-document write.
        foreach (array_keys($touchedIndexes) as $indexName) {
            $es->refreshIndex($indexName);
        }
    }
}
