<?php

use App\Http\Middleware\EnforceTokenAbilities;
use App\Http\Middleware\IsSuperAdmin;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withSchedule(function (Schedule $schedule) {
        $schedule->command('resource:prune')->dailyAt('03:00');
        $schedule->command('files:purge-uncommitted')->dailyAt('03:30');
        // Abandoned create-mode drafts (tab close / crash). 1h TTL → run hourly.
        $schedule->command('resources:purge-drafts')->hourly();
    })
    ->withMiddleware(function (Middleware $middleware) {
        // Register custom middleware aliases
        $middleware->alias([
            'superadmin' => IsSuperAdmin::class,
        ]);

        // Scoped API keys (read / ask / write) are checked on every API
        // request; session tokens carry ['*'] and are unaffected.
        $middleware->api(append: [EnforceTokenAbilities::class]);

        // The public vault grammar is a sessionless machine surface (gated by
        // published/key + the ask policy) — CSRF is meaningless for its POSTs.
        $middleware->validateCsrfTokens(except: [
            'v/*/*/ask',
            'h/*/ask',
            // Boundary write methods — same sessionless machine surface, gated
            // by a write vault key, not a session (VAULT_WRITE_METHODS.md §5).
            'v/*/*/w/*',
            'h/*/w/*',
        ]);

        // This is a pure-API app — there is no `login` route to redirect to.
        // Returning null makes the auth middleware skip the redirect; if the
        // request expects JSON (or hits /api/*) Laravel returns 401 directly.
        $middleware->redirectGuestsTo(function (Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return null;
            }

            // No web login page either; fall back to root.
            return '/';
        });
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Force AuthenticationException to render as JSON for API requests so
        // the SPA receives a clean 401 instead of a redirect attempt.
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated.',
                ], 401);
            }

            return null;
        });
    })->create();
