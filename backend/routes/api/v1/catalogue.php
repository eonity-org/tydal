<?php

use App\Http\Controllers\API\CatalogueController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Catalogue Routes
|--------------------------------------------------------------------------
|
| Serves paginated resources + facet aggregations for a collection.
| The API contract is intentionally stable across Phase B (DB facets)
| and Phase C (Elasticsearch facets) — only the aggregation backend changes.
|
*/

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/catalogue/{id}', [CatalogueController::class, 'show'])
        ->name('catalogue.show')
        ->whereNumber('id');

    Route::post('/catalogue/{id}/ask', [CatalogueController::class, 'ask'])
        ->name('catalogue.ask')
        ->whereNumber('id');
});
