<?php

use App\Http\Controllers\API\OrganizationController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Organization Routes
|--------------------------------------------------------------------------
|
| Routes for organization management. All routes require authentication
| and organization context.
|
*/

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/organizations', [OrganizationController::class, 'index'])
        ->name('organizations.index');

    Route::post('/organizations', [OrganizationController::class, 'store'])
        ->name('organizations.store');

    Route::get('/organizations/{id}', [OrganizationController::class, 'show'])
        ->name('organizations.show');

    Route::put('/organizations/{id}', [OrganizationController::class, 'update'])
        ->name('organizations.update');

    Route::delete('/organizations/{id}', [OrganizationController::class, 'destroy'])
        ->name('organizations.destroy');

    Route::post('/organizations/{id}/switch', [OrganizationController::class, 'switch'])
        ->name('organizations.switch');

    Route::get('/organizations/{id}/users', [OrganizationController::class, 'users'])
        ->name('organizations.users');

    Route::post('/organizations/{id}/users', [OrganizationController::class, 'addUser'])
        ->name('organizations.users.add');

    Route::put('/organizations/{id}/users/{userId}', [OrganizationController::class, 'updateUser'])
        ->name('organizations.users.update');

    Route::delete('/organizations/{id}/users/{userId}', [OrganizationController::class, 'removeUser'])
        ->name('organizations.users.remove');
});
