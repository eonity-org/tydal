<?php

use App\Http\Controllers\API\VaultController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Vault Routes
|--------------------------------------------------------------------------
|
| Platform admin routes for managing Vault configurations (superadmin only).
| Resource Vault links endpoint for authenticated users.
|
*/

// Platform Vault management (superadmin only)
Route::prefix('platform/vaults')
    ->middleware(['auth:sanctum', 'superadmin'])
    ->group(function () {
        Route::get('/', [VaultController::class, 'index'])
            ->name('platform.vaults.index');

        Route::post('/', [VaultController::class, 'store'])
            ->name('platform.vaults.store');

        // Clusters → auto-created vaults (Epic 4.5). Literal segment —
        // registered before the /{id} routes.
        Route::post('/clusters/rebuild', [VaultController::class, 'rebuildClusters'])
            ->name('platform.vaults.clusters.rebuild');

        // The capability matrix vocabulary + every purpose's preset, so the
        // admin UI renders what a preset grants (and previews a switch)
        // without restating the matrix in TypeScript. Literal segment.
        Route::get('/capabilities', [VaultController::class, 'capabilities'])
            ->name('platform.vaults.capabilities');

        Route::get('/{id}', [VaultController::class, 'show'])
            ->name('platform.vaults.show');

        Route::put('/{id}', [VaultController::class, 'update'])
            ->name('platform.vaults.update');

        Route::delete('/{id}', [VaultController::class, 'destroy'])
            ->name('platform.vaults.destroy');

        // Vault keys — private-vault access credentials (spec §7)
        Route::get('/{id}/keys', [VaultController::class, 'listKeys'])
            ->name('platform.vaults.keys.index');

        Route::post('/{id}/keys', [VaultController::class, 'storeKey'])
            ->name('platform.vaults.keys.store');

        Route::delete('/{id}/keys/{keyId}', [VaultController::class, 'revokeKey'])
            ->name('platform.vaults.keys.revoke');

        // Signed URLs — time-limited publish grants (Epic 5.4)
        Route::post('/{id}/signed-urls', [VaultController::class, 'signedUrl'])
            ->name('platform.vaults.signed-urls.store');

        // Revoke all outstanding grants (bump grant_epoch) — links untouched
        Route::post('/{id}/revoke-grants', [VaultController::class, 'revokeGrants'])
            ->name('platform.vaults.grants.revoke');

        // Rotate the salt — fresh secret + purge every link (nuclear reset)
        Route::post('/{id}/rotate-salt', [VaultController::class, 'rotateSalt'])
            ->name('platform.vaults.salt.rotate');

        // Schema overlays — per-vault semantic mapping (Epic 3.1)
        Route::get('/{id}/overlays', [VaultController::class, 'listOverlays'])
            ->name('platform.vaults.overlays.index');

        Route::put('/{id}/overlays/{schemeId}', [VaultController::class, 'putOverlay'])
            ->name('platform.vaults.overlays.put');
    });

// Authenticated routes (any user)
Route::middleware('auth:sanctum')->group(function () {
    // List active Vaults — for workspace association UI
    Route::get('/vaults', [VaultController::class, 'publicIndex'])
        ->name('vaults.index');

    // Publish/unpublish — org Admin (75)+ per spec §3 (org-scoped form;
    // the platform surface above stays superadmin)
    Route::post('/vaults/{id}/publish', [VaultController::class, 'publish'])
        ->name('vaults.publish');

    // Ask AITY about a vault (Epic 4.4) — org members only; retrieval is
    // scoped by the vault's own operation grammar.
    Route::post('/vaults/{id}/ask', [VaultController::class, 'ask'])
        ->name('vaults.ask');

    // Resource Vault links — lazy generation
    Route::get('/resources/{resourceId}/vault-links', [VaultController::class, 'resourceLinks'])
        ->name('resources.vault-links');
});
