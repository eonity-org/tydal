<?php

use App\Http\Controllers\API\CollectionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Collection Routes
|--------------------------------------------------------------------------
|
| Routes for collection management. All routes require authentication
| and organization context.
|
*/

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/collections', [CollectionController::class, 'index'])
        ->name('collections.index');

    Route::post('/collections', [CollectionController::class, 'store'])
        ->name('collections.store');

    Route::get('/collections/{id}', [CollectionController::class, 'show'])
        ->name('collections.show');

    Route::put('/collections/{id}', [CollectionController::class, 'update'])
        ->name('collections.update');

    Route::delete('/collections/{id}', [CollectionController::class, 'destroy'])
        ->name('collections.destroy');
});
