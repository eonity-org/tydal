<?php

use App\Http\Controllers\API\WorkspaceController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Workspace Routes
|--------------------------------------------------------------------------
|
| Routes for workspace management. All routes require authentication
| and organization context.
|
*/

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/workspaces', [WorkspaceController::class, 'index'])
        ->name('workspaces.index');

    Route::get('/workspaces/aity-batches', [WorkspaceController::class, 'aityBatches'])
        ->name('workspaces.aity-batches');

    Route::post('/workspaces', [WorkspaceController::class, 'store'])
        ->name('workspaces.store');

    Route::get('/workspaces/{id}', [WorkspaceController::class, 'show'])
        ->name('workspaces.show');

    Route::put('/workspaces/{id}', [WorkspaceController::class, 'update'])
        ->name('workspaces.update');

    Route::delete('/workspaces/{id}', [WorkspaceController::class, 'destroy'])
        ->name('workspaces.destroy');

    Route::get('/workspaces/{id}/catalogue', [WorkspaceController::class, 'catalogue'])
        ->name('workspaces.catalogue');

    Route::get('/workspaces/{id}/aity-status', [WorkspaceController::class, 'aityStatus'])
        ->name('workspaces.aity-status');

    Route::post('/workspaces/{id}/mark-reviewed', [WorkspaceController::class, 'markReviewed'])
        ->name('workspaces.mark-reviewed');

    Route::post('/workspaces/{id}/ask', [WorkspaceController::class, 'ask'])
        ->name('workspaces.ask');

    // Bulk membership — declared before the single-id routes so `bulk-attach`
    // is never read as a {resourceId}. POST for both because a request body on
    // DELETE travels badly through proxies and fetch().
    Route::post('/workspaces/{id}/resources/bulk-attach', [WorkspaceController::class, 'bulkAttachResources'])
        ->name('workspaces.resources.bulk-attach');

    Route::post('/workspaces/{id}/resources/bulk-detach', [WorkspaceController::class, 'bulkDetachResources'])
        ->name('workspaces.resources.bulk-detach');

    Route::post('/workspaces/{id}/resources', [WorkspaceController::class, 'addResource'])
        ->name('workspaces.resources.add');

    Route::delete('/workspaces/{id}/resources/{resourceId}', [WorkspaceController::class, 'removeResource'])
        ->name('workspaces.resources.remove');

    Route::get('/workspaces/{id}/vaults', [WorkspaceController::class, 'listVaults'])
        ->name('workspaces.vaults.index');

    Route::post('/workspaces/{id}/vaults', [WorkspaceController::class, 'attachVault'])
        ->name('workspaces.vaults.attach');

    Route::delete('/workspaces/{id}/vaults/{vaultId}', [WorkspaceController::class, 'detachVault'])
        ->name('workspaces.vaults.detach');
});
