<?php

namespace App\Http\Controllers\API\Concerns;

use App\Jobs\SyncResourcesToElasticsearch;
use Illuminate\Http\JsonResponse;

/**
 * The one response shape every bulk action answers with.
 *
 * Authorization on a bulk write is per-resource, so "you may act on 18 of
 * these 20" is the normal outcome, not an error: a dashboard basket gathered
 * across collections routinely holds a few resources the caller cannot touch,
 * and refusing all twenty because of two would be useless. The caller gets
 * back what was applied and, item by item, why the rest were not, so the UI
 * can keep the skipped ones selected and say so.
 */
trait RespondsToBulkActions
{
    /**
     * Bring the given resources' search documents up to date. Small batches
     * run inline so the caller's refetch sees them — see the job's threshold.
     *
     * @param  array<int, string>  $resourceIds
     * @return string 'immediate' when the caller can refetch at once, 'queued' otherwise.
     */
    protected function syncResourceIndexes(array $resourceIds): string
    {
        return SyncResourcesToElasticsearch::run($resourceIds);
    }

    /**
     * @param  array<int, string>  $requested  Every id the caller asked for.
     * @param  array<int, string>  $applied  The subset actually written.
     * @param  string  $skipReason  Machine-readable reason for the remainder.
     */
    protected function bulkResponse(array $requested, array $applied, string $skipReason, string $indexing = 'immediate'): JsonResponse
    {
        $appliedSet = array_flip($applied);
        $skipped = [];
        foreach ($requested as $resourceId) {
            if (! isset($appliedSet[$resourceId])) {
                $skipped[] = ['id' => $resourceId, 'reason' => $skipReason];
            }
        }

        // 403 is reserved for "you could not act on a single one of these".
        $status = (empty($applied) && ! empty($requested)) ? 403 : 200;

        return response()->json([
            'success' => ! empty($applied),
            'requested' => count($requested),
            'applied' => count($applied),
            'skipped' => $skipped,
            // 'queued' tells the caller its refetch will race the reindex, so
            // it can say the list is still catching up rather than silently
            // showing stale rows.
            'indexing' => $indexing,
        ], $status);
    }
}
