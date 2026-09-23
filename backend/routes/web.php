<?php

use App\Http\Controllers\API\VaultNamespaceController;
use App\Http\Controllers\API\VaultPublicController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

Route::view('/', 'welcome');

// Public vault link access — no authentication required.
// Flat form: delivery links (classic CDN).
Route::get('/vault/{hash}', [VaultPublicController::class, 'serve'])
    ->name('vault.serve');

Route::get('/vault/{hash}/info', [VaultPublicController::class, 'info'])
    ->name('vault.info');

Route::get('/vault/{hash}/download', [VaultPublicController::class, 'download'])
    ->name('vault.download');

// Machine form: vault-first resolution — vault policy is checked before the
// link is even looked up (VAULT_SYSTEM.md §4.1). Vault purposes are born here.
// Default GET follows the manifest rule (§5); operations share one grammar
// with the human form below. /h/{vaultHash} alone addresses the vault itself
// (index + vault operations) — the keyless-by-hash MCP connection point.
Route::get('/h/{vaultHash}', [VaultNamespaceController::class, 'hashVaultIndex'])
    ->name('h.index');

Route::get('/h/{vaultHash}/{op}', [VaultNamespaceController::class, 'hashVaultOperation'])
    ->whereIn('op', ['meta', 'tags', 'resources', 'search', 'embed', 'graph'])
    ->name('h.vault.operate');

// Reasoning is compute — POST, and throttled per IP on top of the ask policy.
Route::post('/h/{vaultHash}/ask', [VaultNamespaceController::class, 'hashVaultAsk'])
    ->middleware('throttle:20,1')
    ->name('h.vault.ask');

// Write at the boundary (VAULT_WRITE_METHODS.md §5) — POST, gated by a
// write-capable vault key and the vault purpose's method set. The `/w/` segment
// keeps it clear of the GET operation/link grammar above. Throttled per IP.
Route::post('/h/{vaultHash}/w/{method}', [VaultNamespaceController::class, 'hashVaultWrite'])
    ->middleware('throttle:30,1')
    ->name('h.vault.write');

// Non-destructive write-auth probe (GET, no method) — must precede the
// {linkHash} catch-all below so `/w` is not read as a link hash.
Route::get('/h/{vaultHash}/w', [VaultNamespaceController::class, 'hashVaultWriteInfo'])
    ->middleware('throttle:30,1')
    ->name('h.vault.write.info');

Route::get('/h/{vaultHash}/{linkHash}', [VaultNamespaceController::class, 'hashEntry'])
    ->name('h.serve');

Route::get('/h/{vaultHash}/{linkHash}/info', [VaultPublicController::class, 'infoByVaultHash'])
    ->name('h.info');

Route::get('/h/{vaultHash}/{linkHash}/{op}', [VaultNamespaceController::class, 'hashOperation'])
    ->whereIn('op', ['meta', 'tags', 'files', 'chunks', 'links', 'related', 'download', 'renditions', 'preview'])
    ->name('h.operate');

// Human form: /v/{orgSlug}/{vaultSlug}[/{resourceSlug}[/{fileSlug}]][/{op}]
// (VAULT_SYSTEM.md §4) — same resolved triple, same tier-gated grammar.
Route::prefix('v/{orgSlug}/{vaultSlug}')
    ->controller(VaultNamespaceController::class)
    ->group(function () {
        Route::get('/', 'vaultIndex')->name('v.index');

        Route::get('/{op}', 'vaultOperation')
            ->whereIn('op', ['meta', 'tags', 'resources', 'search', 'embed', 'graph'])
            ->name('v.operate');

        Route::post('/ask', 'vaultAsk')
            ->middleware('throttle:20,1')
            ->name('v.ask');

        // Write at the boundary — human form of /h/{vaultHash}/w/{method}.
        Route::post('/w/{method}', 'vaultWrite')
            ->middleware('throttle:30,1')
            ->name('v.write');

        // Write-auth probe (GET) — before {resourceSlug} so `/w` is not read
        // as a resource slug.
        Route::get('/w', 'vaultWriteInfo')
            ->middleware('throttle:30,1')
            ->name('v.write.info');

        Route::get('/{resourceSlug}', 'resourceEntry')->name('v.resource');

        Route::get('/{resourceSlug}/{op}', 'resourceOperation')
            ->whereIn('op', ['meta', 'tags', 'files', 'chunks', 'links', 'related', 'download', 'preview'])
            ->name('v.resource.operate');

        Route::get('/{resourceSlug}/{fileSlug}', 'fileEntry')->name('v.file');

        Route::get('/{resourceSlug}/{fileSlug}/{op}', 'fileOperation')
            ->whereIn('op', ['meta', 'chunks', 'download', 'renditions'])
            ->name('v.file.operate');
    });
