<?php

use App\Http\Controllers\API\AuthController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Authentication Routes
|--------------------------------------------------------------------------
|
| Routes for user authentication and session management using Laravel Sanctum.
|
*/

// Per-IP ceilings. `login` also counts failed attempts per email + address in
// the controller (5 a minute); these stop one address from sweeping many
// emails, or from creating accounts in bulk.
Route::post('/register', [AuthController::class, 'register'])
    ->middleware('throttle:10,1')
    ->name('auth.register');

Route::post('/login', [AuthController::class, 'login'])
    ->middleware('throttle:20,1')
    ->name('auth.login');

// Identity check for products using TYDAL as their identity provider (e.g.
// Full Frame's studio): no token, no session. Per-email limit in the
// controller; this is the per-IP ceiling for the product's server.
Route::post('/auth/identify', [AuthController::class, 'identify'])
    ->middleware('throttle:60,1')
    ->name('auth.identify');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [AuthController::class, 'me'])
        ->name('auth.me');

    Route::post('/logout', [AuthController::class, 'logout'])
        ->name('auth.logout');

    Route::post('/refresh', [AuthController::class, 'refresh'])
        ->name('auth.refresh');
});
