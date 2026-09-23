<?php

use App\Http\Controllers\API\ResourceController;
use App\Http\Controllers\API\ResourceGraphController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Resource Routes
|--------------------------------------------------------------------------
|
| Routes for resource management. All routes require authentication
| and organization context.
|
*/

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/resources/trashed', [ResourceController::class, 'trashed'])
        ->name('resources.trashed');

    Route::delete('/resources/trashed/purge', [ResourceController::class, 'purgeTrash'])
        ->name('resources.trashed.purge');

    Route::patch('/resources/trashed/restore-all', [ResourceController::class, 'restoreAll'])
        ->name('resources.trashed.restore-all');

    Route::patch('/resources/{id}/restore', [ResourceController::class, 'restore'])
        ->name('resources.restore');

    Route::delete('/resources/{id}/force', [ResourceController::class, 'forceDestroy'])
        ->name('resources.force-destroy');

    Route::get('/resources', [ResourceController::class, 'index'])
        ->name('resources.index');

    // Bulk AITY status — lightweight payload for dashboard polling. Returns
    // [{ id, aity_status, updated_at }, …] for the requested resource ids.
    Route::get('/resources/aity-status', [ResourceController::class, 'aityStatusBulk'])
        ->name('resources.aity-status.bulk');

    // Bulk actions on a set of resources — the dashboard basket. Registered
    // before the /resources/{id} patterns so the literal segment wins, and
    // POST (not PUT/DELETE) because they all carry an id list in the body.
    Route::post('/resources/bulk/state', [ResourceController::class, 'bulkState'])
        ->name('resources.bulk.state');
    Route::post('/resources/bulk/semantic-tags', [ResourceController::class, 'bulkSemanticTags'])
        ->name('resources.bulk.semantic-tags');

    Route::post('/resources', [ResourceController::class, 'store'])
        ->name('resources.store');

    Route::get('/resources/{id}', [ResourceController::class, 'show'])
        ->name('resources.show');

    // Lean, AI-agent-facing projection — files + Vault links inlined, chunk/
    // embedding availability, no pipeline-audit internals. See show() for
    // the full admin-review shape.
    Route::get('/resources/{id}/agent-view', [ResourceController::class, 'agentView'])
        ->name('resources.agent-view');

    Route::get('/resources/{id}/chunks', [ResourceController::class, 'chunks'])
        ->name('resources.chunks');

    // Resource graph (Epic 4.3) — manual edges + multi-hop neighborhood.
    // Auto edges (tags/semantic co-occurrence) come from `graph:rebuild`.
    Route::get('/resources/{id}/graph', [ResourceGraphController::class, 'show'])
        ->name('resources.graph');
    Route::get('/resources/{id}/relations', [ResourceGraphController::class, 'index'])
        ->name('resources.relations.index');
    Route::post('/resources/{id}/relations', [ResourceGraphController::class, 'store'])
        ->name('resources.relations.store');
    Route::delete('/resources/{id}/relations/{relationId}', [ResourceGraphController::class, 'destroy'])
        ->name('resources.relations.destroy');

    Route::put('/resources/{id}', [ResourceController::class, 'update'])
        ->name('resources.update');

    Route::delete('/resources/{id}', [ResourceController::class, 'destroy'])
        ->name('resources.destroy');

    // File management
    Route::post('/resources/{id}/files', [ResourceController::class, 'addFile'])
        ->name('resources.files.add');

    // Commit session-uncommitted files (clears uncommitted_at on the current user's pending uploads)
    Route::post('/resources/{id}/commit-files', [ResourceController::class, 'commitFiles'])
        ->name('resources.files.commit');

    // AITY enrichment — full pipeline re-trigger (Tika + LLM/vision)
    Route::post('/resources/{id}/aity-enrich', [ResourceController::class, 'aityEnrichResource'])
        ->name('resources.aity-enrich');
    Route::post('/resources/{id}/files/{fid}/aity-enrich', [ResourceController::class, 'aityEnrichFile'])
        ->name('resources.files.aity-enrich');

    // AITY retag — LLM only, reads existing extraction
    Route::post('/resources/{id}/aity-retag', [ResourceController::class, 'aityRetag'])
        ->name('resources.aity-retag');

    // AITY approve — single-resource run of the auto-approval synthesis. Unifies
    // per-file suggestions into resource-level metadata (multi-component synthesis).
    // ?apply=true writes it onto the resource; otherwise persists as suggestions only.
    Route::post('/resources/{id}/aity-approve', [ResourceController::class, 'aityApproveResource'])
        ->name('resources.aity-approve');

    // AITY status — per-file enrichment progress polling
    Route::get('/resources/{id}/files/{fid}/aity-status', [ResourceController::class, 'aityStatus'])
        ->name('resources.files.aity-status');

    // Processing activity log
    Route::get('/resources/{id}/activity', [ResourceController::class, 'activityLog'])
        ->name('resources.activity');

    // Clear AI suggestions (per file, called on save)
    Route::delete('/resources/{resourceId}/files/{fileId}/ai-suggestions', [ResourceController::class, 'clearFileAiSuggestions'])
        ->name('resources.files.ai-suggestions.clear');
    // Restore all processed AI suggestions for a resource
    Route::post('/resources/{resourceId}/ai-suggestions/restore', [ResourceController::class, 'restoreAiSuggestions'])
        ->name('resources.ai-suggestions.restore');

    Route::delete('/resources/{resourceId}/files/{fileId}', [ResourceController::class, 'removeFile'])
        ->name('resources.files.remove');

    Route::patch('/resources/{resourceId}/files/{fileId}', [ResourceController::class, 'updateFile'])
        ->name('resources.files.update');

    Route::patch('/resources/{resourceId}/files/{fileId}/canonical', [ResourceController::class, 'setFileCanonical'])
        ->name('resources.files.canonical');

    Route::patch('/resources/{resourceId}/files/{fileId}/snapshot', [ResourceController::class, 'setFileSnapshot'])
        ->name('resources.files.snapshot');

    Route::get('/resources/{resourceId}/files/{fileId}/download', [ResourceController::class, 'downloadFile'])
        ->name('resources.files.download');
});
