<?php

use App\Http\Controllers\API\TokenController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Token (API Key) Routes
|--------------------------------------------------------------------------
|
| Management of scoped personal access tokens for machine clients (MCP,
| integrations). Requires a full-access session token — scoped API keys
| cannot manage tokens.
|
*/

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/tokens', [TokenController::class, 'index'])
        ->name('tokens.index');

    Route::post('/tokens', [TokenController::class, 'store'])
        ->name('tokens.store');

    Route::delete('/tokens/{id}', [TokenController::class, 'destroy'])
        ->name('tokens.destroy');
});
