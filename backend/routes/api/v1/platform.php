<?php

use App\Http\Controllers\API\AiTestController;
use App\Http\Controllers\API\PlatformController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Platform Superadmin Routes
|--------------------------------------------------------------------------
|
| All routes are prefixed with /platform and require superadmin access.
| These routes expose platform-wide operations: managing all users,
| all organizations, all collections, and platform settings.
|
*/

Route::prefix('platform')
    ->middleware(['auth:sanctum', 'superadmin'])
    ->group(function () {

        // ---------------------------------------------------------------
        // Organizations
        // ---------------------------------------------------------------
        Route::get('/organizations', [PlatformController::class, 'listOrganizations'])
            ->name('platform.organizations.list');

        Route::post('/organizations', [PlatformController::class, 'createOrganization'])
            ->name('platform.organizations.create');

        Route::get('/organizations/{id}', [PlatformController::class, 'showOrganization'])
            ->name('platform.organizations.show');

        Route::put('/organizations/{id}', [PlatformController::class, 'updateOrganization'])
            ->name('platform.organizations.update');

        Route::delete('/organizations/{id}', [PlatformController::class, 'deleteOrganization'])
            ->name('platform.organizations.delete');

        Route::post('/organizations/{id}/suspend', [PlatformController::class, 'suspendOrganization'])
            ->name('platform.organizations.suspend');

        Route::post('/organizations/{id}/activate', [PlatformController::class, 'activateOrganization'])
            ->name('platform.organizations.activate');

        Route::get('/organizations/{id}/workspaces', [PlatformController::class, 'listOrgWorkspaces'])
            ->name('platform.organizations.workspaces');

        // ---------------------------------------------------------------
        // Users
        // ---------------------------------------------------------------
        Route::get('/users', [PlatformController::class, 'listUsers'])
            ->name('platform.users.list');

        Route::post('/users', [PlatformController::class, 'createUser'])
            ->name('platform.users.create');

        Route::get('/users/{id}', [PlatformController::class, 'showUser'])
            ->name('platform.users.show');

        Route::put('/users/{id}', [PlatformController::class, 'updateUser'])
            ->name('platform.users.update');

        Route::delete('/users/{id}', [PlatformController::class, 'deleteUser'])
            ->name('platform.users.delete');

        // ---------------------------------------------------------------
        // Collections
        // ---------------------------------------------------------------
        Route::get('/collections', [PlatformController::class, 'listCollections'])
            ->name('platform.collections.list');

        Route::post('/collections', [PlatformController::class, 'createCollection'])
            ->name('platform.collections.create');

        Route::get('/collections/{id}', [PlatformController::class, 'showCollection'])
            ->name('platform.collections.show');

        Route::put('/collections/{id}', [PlatformController::class, 'updateCollection'])
            ->name('platform.collections.update');

        Route::delete('/collections/{id}', [PlatformController::class, 'deleteCollection'])
            ->name('platform.collections.delete');

        Route::post('/collections/{id}/duplicate', [PlatformController::class, 'duplicateCollection'])
            ->name('platform.collections.duplicate');

        // ---------------------------------------------------------------
        // AI Services — connectivity test (superadmin only)
        // ---------------------------------------------------------------
        Route::get('/ai/config', [AiTestController::class, 'config'])->name('platform.ai.config');
        Route::get('/ai/ollama/models', [AiTestController::class, 'ollamaModels'])->name('platform.ai.ollama.models');
        Route::post('/ai/test/embedding', [AiTestController::class, 'testEmbedding'])->name('platform.ai.test.embedding');
        Route::post('/ai/test/chat', [AiTestController::class, 'testChat'])->name('platform.ai.test.chat');
        Route::post('/ai/test/vision', [AiTestController::class, 'testVision'])->name('platform.ai.test.vision');

        // ---------------------------------------------------------------
        // Platform stats & settings
        // ---------------------------------------------------------------
        Route::get('/stats', [PlatformController::class, 'platformStats'])
            ->name('platform.stats');

        Route::get('/settings', [PlatformController::class, 'getSettings'])
            ->name('platform.settings.get');

        Route::put('/settings', [PlatformController::class, 'updateSettings'])
            ->name('platform.settings.update');
    });
