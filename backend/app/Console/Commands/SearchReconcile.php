<?php

namespace App\Console\Commands;

use App\Enums\ResourceState;
use App\Jobs\EmbedFileChunks;
use App\Jobs\UpsertResourceMetadataChunk;
use App\Models\FileChunk;
use App\Models\Resource;
use App\Models\SearchIndex;
use App\Services\ElasticsearchService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Detect (and optionally repair) MySQL ↔ Elasticsearch drift — Epic 1.3.
 *
 * Three drift classes per resource index:
 *   missing  — active resource with no ES document
 *   stale    — ES document whose updated_at differs from MySQL
 *   orphaned — ES document whose resource is gone or inactive
 *
 * Four drift classes per chunks index (…_chunks):
 *   missing        — file_chunks manifest row with no ES document (unembedded)
 *   orphaned       — ES content doc whose chunk_id is not in the manifest
 *                    (stale extraction generations — every re-extraction mints
 *                    fresh chunk UUIDs, so leftovers are otherwise invisible)
 *   meta missing   — active resource without its synthetic metadata chunk
 *   meta orphaned  — meta doc whose resource is gone or inactive
 *
 * Report-only by default; --fix reindexes missing/stale synchronously, purges
 * orphans, and dispatches embedding jobs for missing chunks (those repairs are
 * async — re-run once the queue settles to confirm). Exits non-zero when
 * unfixed drift remains (CI-able).
 */
class SearchReconcile extends Command
{
    protected $signature = 'search:reconcile
        {--collection= : Only reconcile the index of a specific collection ID}
        {--fix : Repair the drift (reindex missing/stale, purge orphans, dispatch embed jobs)}';

    protected $description = 'Detect and optionally repair MySQL ↔ Elasticsearch drift';

    public function handle(ElasticsearchService $es): int
    {
        $collectionId = $this->option('collection') !== null ? (int) $this->option('collection') : null;
        $fix = (bool) $this->option('fix');
        $driftTotal = 0;

        foreach (SearchIndex::where('is_active', true)->get() as $searchIndex) {
            $allCollectionIds = $searchIndex->collections()->pluck('id');
            $collectionIds = $allCollectionIds;

            if ($collectionId !== null) {
                $collectionIds = $collectionIds->intersect([$collectionId]);
            }

            if ($collectionIds->isEmpty()) {
                continue;
            }

            $this->info("Index: {$searchIndex->index_name}");

            $dbMap = Resource::whereIn('collection_id', $collectionIds)
                ->where('state', ResourceState::LIVE->value)
                ->pluck('updated_at', 'id');

            $esMap = $es->listIndexedResources($searchIndex->index_name);

            if (count($esMap) === 10000) {
                $this->warn('  ES listing hit the 10 000-doc cap — results may be partial.');
            }

            $missing = $dbMap->keys()->diff(array_keys($esMap));
            $orphaned = collect(array_keys($esMap))->diff($dbMap->keys());
            $stale = $dbMap->filter(function (Carbon $dbUpdated, string $id) use ($esMap) {
                if (! array_key_exists($id, $esMap)) {
                    return false;
                }
                $esUpdated = $esMap[$id];

                return $esUpdated === null || ! Carbon::parse($esUpdated)->equalTo($dbUpdated);
            })->keys();

            $this->line(sprintf(
                '  missing: %d · stale: %d · orphaned: %d (db: %d, es: %d)',
                $missing->count(), $stale->count(), $orphaned->count(), $dbMap->count(), count($esMap)
            ));

            $driftTotal += $missing->count() + $stale->count() + $orphaned->count();

            if ($fix) {
                foreach ($missing->merge($stale) as $resourceId) {
                    $resource = Resource::with('collection')->find($resourceId);
                    if ($resource) {
                        $es->indexResource($resource);
                    }
                }

                foreach ($orphaned as $docId) {
                    $es->purgeDocument($searchIndex->index_name, $docId);
                }

                if ($missing->count() + $stale->count() + $orphaned->count() > 0) {
                    $this->info(sprintf(
                        '  fixed: reindexed %d, purged %d',
                        $missing->count() + $stale->count(), $orphaned->count()
                    ));
                }
            }

            $driftTotal += $this->reconcileChunks($es, $searchIndex->index_name, $allCollectionIds, $fix);
        }

        if ($driftTotal === 0) {
            $this->info('No drift detected.');

            return self::SUCCESS;
        }

        if ($fix) {
            $this->info("Repaired {$driftTotal} drifted document(s).");

            return self::SUCCESS;
        }

        $this->warn("{$driftTotal} drifted document(s) found. Run with --fix to repair.");

        return self::FAILURE;
    }

    /**
     * Chunk-side drift for one index. Always scoped to ALL of the index's
     * collections (not the --collection filter): the chunks index is shared
     * per search index, so a narrower manifest would misread other
     * collections' documents as orphans — and --fix would purge them.
     *
     * @param  Collection<int, int>  $allCollectionIds
     */
    private function reconcileChunks(ElasticsearchService $es, string $indexName, Collection $allCollectionIds, bool $fix): int
    {
        $chunksIndex = $es->buildChunksIndexName($indexName);

        if (! $es->chunksIndexExists($chunksIndex)) {
            return 0;
        }

        $esChunks = $es->listIndexedChunks($chunksIndex);

        if (count($esChunks) === 10000) {
            $this->warn('  chunks listing hit the 10 000-doc cap — results may be partial.');
        }

        $activeIds = Resource::whereIn('collection_id', $allCollectionIds)
            ->where('state', ResourceState::LIVE->value)
            ->pluck('id');

        $manifest = FileChunk::whereIn('resource_id', $activeIds)->get(['id', 'source_file_id']);
        $manifestIds = $manifest->pluck('id');

        // Split ES docs: synthetic meta chunks vs extracted content chunks
        $esMetaResourceIds = [];
        $esContentIds = [];
        foreach (array_keys($esChunks) as $chunkId) {
            if (str_starts_with($chunkId, 'meta-')) {
                $esMetaResourceIds[] = substr($chunkId, 5);
            } else {
                $esContentIds[] = $chunkId;
            }
        }

        $missing = $manifestIds->diff($esContentIds);
        $orphaned = collect($esContentIds)->diff($manifestIds);
        $metaMissing = $activeIds->diff($esMetaResourceIds);
        $metaOrphaned = collect($esMetaResourceIds)->diff($activeIds);

        $this->line(sprintf(
            '  chunks — missing: %d · orphaned: %d · meta missing: %d · meta orphaned: %d (manifest: %d, es: %d)',
            $missing->count(), $orphaned->count(), $metaMissing->count(), $metaOrphaned->count(),
            $manifestIds->count(), count($esChunks)
        ));

        $drift = $missing->count() + $orphaned->count() + $metaMissing->count() + $metaOrphaned->count();

        if (! $fix || $drift === 0) {
            return $drift;
        }

        foreach ($orphaned as $chunkId) {
            $es->purgeDocument($chunksIndex, $chunkId);
        }
        foreach ($metaOrphaned as $resourceId) {
            $es->purgeDocument($chunksIndex, 'meta-'.$resourceId);
        }

        // Missing docs need the embedder — repairs go through the queue
        $filesToEmbed = $manifest->whereIn('id', $missing)->pluck('source_file_id')->unique();
        foreach ($filesToEmbed as $fileId) {
            EmbedFileChunks::dispatch($fileId);
        }
        foreach ($metaMissing as $resourceId) {
            UpsertResourceMetadataChunk::dispatch($resourceId);
        }

        $this->info(sprintf(
            '  fixed: purged %d chunk doc(s), dispatched %d embed + %d meta job(s) (async — re-run once the queue settles)',
            $orphaned->count() + $metaOrphaned->count(), $filesToEmbed->count(), $metaMissing->count()
        ));

        return $drift;
    }
}
