<?php

use App\Http\Controllers\API\AityController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| AITY Routes
|--------------------------------------------------------------------------
|
| General-purpose AITY endpoints not tied to a specific resource or collection.
| All routes require authentication and organisation context.
|
*/

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/aity/chat', [AityController::class, 'chat'])->name('aity.chat');
    Route::post('/aity/auto-approve', [AityController::class, 'autoApprove'])->name('aity.auto-approve');
    Route::post('/aity/auto-approve/stream', [AityController::class, 'autoApproveStream'])->name('aity.auto-approve.stream');
    Route::post('/aity/auto-approve/dispatch', [AityController::class, 'dispatchAutoApprove'])->name('aity.auto-approve.dispatch');
});
