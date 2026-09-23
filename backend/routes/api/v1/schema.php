<?php

use App\Http\Controllers\API\CollectionSchemeController;
use App\Http\Controllers\API\SearchIndexController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Schema Routes
|--------------------------------------------------------------------------
|
| Collection schemes define the canonical field structure (fields JSON).
| Search indexes represent Elasticsearch indexes.
|
| Both are GLOBAL, cross-tenant infrastructure (no organization_id) — every
| org shares the same catalog. Reads are open to any authenticated user
| (forms and facets need the shared definitions); writes are platform-operator
| only, because mutating a shared object is inherently a cross-tenant action.
|
*/

// ── Reads — the shared catalog, readable by any authenticated user ──────────
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/collection-schemes', [CollectionSchemeController::class, 'index'])
        ->name('collection-schemes.index');
    Route::get('/collection-schemes/{id}', [CollectionSchemeController::class, 'show'])
        ->name('collection-schemes.show');

    Route::get('/search-indexes', [SearchIndexController::class, 'index'])
        ->name('search-indexes.index');
    Route::get('/search-indexes/{id}', [SearchIndexController::class, 'show'])
        ->name('search-indexes.show');
});

// ── Writes — global infrastructure, superadmin only ─────────────────────────
Route::middleware(['auth:sanctum', 'superadmin'])->group(function () {
    // Copy a scheme into one organization so it can be varied without
    // disturbing everybody else's field contract.
    Route::post('/collection-schemes/{id}/clone', [CollectionSchemeController::class, 'clone'])
        ->name('collection-schemes.clone');

    Route::post('/collection-schemes', [CollectionSchemeController::class, 'store'])
        ->name('collection-schemes.store');
    Route::put('/collection-schemes/{id}', [CollectionSchemeController::class, 'update'])
        ->name('collection-schemes.update');
    Route::delete('/collection-schemes/{id}', [CollectionSchemeController::class, 'destroy'])
        ->name('collection-schemes.destroy');

    Route::post('/search-indexes', [SearchIndexController::class, 'store'])
        ->name('search-indexes.store');
    Route::put('/search-indexes/{id}', [SearchIndexController::class, 'update'])
        ->name('search-indexes.update');
    Route::delete('/search-indexes/{id}', [SearchIndexController::class, 'destroy'])
        ->name('search-indexes.destroy');
});
