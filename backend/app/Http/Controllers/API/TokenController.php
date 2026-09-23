<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Personal Access Token (API key) management.
 *
 * Only full-access session tokens may manage keys — a scoped key that could
 * mint or revoke keys would be able to escalate its own privileges.
 */
class TokenController extends Controller
{
    /** Abilities grantable to an API key. */
    public const ABILITIES = ['read', 'ask', 'write'];

    /** Reserved name used by login/refresh session tokens. */
    public const SESSION_TOKEN_NAME = 'auth-token';

    /**
     * List the authenticated user's tokens (values are never returned).
     */
    public function index(Request $request): JsonResponse
    {
        if ($denied = $this->requireFullAccess($request)) {
            return $denied;
        }

        $tokens = $request->user()->tokens()
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (PersonalAccessToken $token) => $this->present($token));

        return response()->json([
            'success' => true,
            'data' => ['tokens' => $tokens],
            'message' => 'Tokens retrieved successfully',
        ]);
    }

    /**
     * Create a scoped API key. The plaintext value is returned once.
     */
    public function store(Request $request): JsonResponse
    {
        if ($denied = $this->requireFullAccess($request)) {
            return $denied;
        }

        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:64', 'not_in:'.self::SESSION_TOKEN_NAME],
            'abilities' => ['sometimes', 'array', 'min:1'],
            'abilities.*' => ['string', 'in:'.implode(',', self::ABILITIES)],
            'expires_in_days' => ['nullable', 'integer', 'min:1', 'max:365'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = $request->user();

        if ($user->tokens()->where('name', $request->name)->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'A token with this name already exists',
            ], 422);
        }

        $abilities = $request->input('abilities', ['read', 'ask']);
        $expiresAt = $request->filled('expires_in_days')
            ? now()->addDays((int) $request->input('expires_in_days'))
            : null;

        $token = $user->createToken($request->name, $abilities, $expiresAt);

        return response()->json([
            'success' => true,
            'data' => [
                'token' => $token->plainTextToken,
                ...$this->present($token->accessToken),
            ],
            'message' => 'Token created — store it now, it will not be shown again',
        ], 201);
    }

    /**
     * Revoke a token by id.
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        if ($denied = $this->requireFullAccess($request)) {
            return $denied;
        }

        $token = $request->user()->tokens()->find($id);

        if (! $token) {
            return response()->json([
                'success' => false,
                'message' => 'Token not found',
            ], 404);
        }

        $current = $request->user()->currentAccessToken();
        if ($current->getKey() === $token->getKey()) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot revoke the token in use — use /logout instead',
            ], 422);
        }

        $token->delete();

        return response()->json([
            'success' => true,
            'message' => 'Token revoked successfully',
        ]);
    }

    /**
     * Reject scoped API keys — token management requires a session token.
     */
    private function requireFullAccess(Request $request): ?JsonResponse
    {
        $token = $request->user()->currentAccessToken();

        if (! $token->can('*')) {
            return response()->json([
                'success' => false,
                'message' => 'API keys cannot manage tokens — log in with a session token',
            ], 403);
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function present(PersonalAccessToken $token): array
    {
        return [
            'id' => (string) $token->getKey(),
            'is_session' => $token->getAttribute('name') === self::SESSION_TOKEN_NAME,
            ...$token->only(['name', 'abilities', 'last_used_at', 'expires_at', 'created_at']),
        ];
    }
}
