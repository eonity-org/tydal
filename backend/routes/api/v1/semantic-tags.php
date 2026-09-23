<?php

use App\Http\Controllers\API\SemanticTagController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/semantic-tags', [SemanticTagController::class, 'index'])->name('semantic-tags.index');
    Route::post('/semantic-tags', [SemanticTagController::class, 'store'])->name('semantic-tags.store');
    Route::put('/semantic-tags/{id}', [SemanticTagController::class, 'update'])->name('semantic-tags.update');
    Route::delete('/semantic-tags/{id}', [SemanticTagController::class, 'destroy'])->name('semantic-tags.destroy');

    // Sync the full set of semantic tags on a resource (replaces pivot rows)
    Route::put('/resources/{resourceId}/semantic-tags', [SemanticTagController::class, 'syncResource'])
        ->name('semantic-tags.sync-resource');
});
