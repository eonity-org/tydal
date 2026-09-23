<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforce abilities for scoped API keys.
 *
 * Session tokens carry ['*'] and pass every check, so SPA traffic is
 * unaffected. Scoped tokens (API keys minted via /tokens or `mcp:token`)
 * need one of:
 *
 *   read  — safe methods (GET / HEAD / OPTIONS)
 *   ask   — POST /workspaces/{id}/ask (read-like RAG endpoint)
 *   write — every other mutation
 *
 * Logout is always allowed (a token may revoke itself). Runs in the api
 * group, before route middleware, so the user is resolved explicitly
 * through the sanctum guard; unauthenticated requests pass through and
 * are handled by auth:sanctum as usual.
 */
class EnforceTokenAbilities
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->user('sanctum')?->currentAccessToken();

        // No token (guest / cookie session) or a full-access session token.
        if (! $token instanceof PersonalAccessToken || $token->can('*')) {
            return $next($request);
        }

        if ($request->routeIs('auth.logout')) {
            return $next($request);
        }

        $required = match (true) {
            $request->isMethodSafe() => 'read',
            $request->routeIs('workspaces.ask') => 'ask',
            default => 'write',
        };

        if (! $token->can($required)) {
            return response()->json([
                'success' => false,
                'message' => "Token is missing the '{$required}' ability.",
            ], 403);
        }

        return $next($request);
    }
}
