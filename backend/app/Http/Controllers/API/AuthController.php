<?php

namespace App\Http\Controllers\API;

use App\Enums\OrganizationRole;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\User;
use App\Services\CurrentOrganizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class AuthController extends Controller
{
    protected CurrentOrganizationService $organizationService;

    public function __construct(CurrentOrganizationService $organizationService)
    {
        $this->organizationService = $organizationService;
    }

    /**
     * Register a new user.
     */
    public function register(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
            'password' => ['required', 'string', 'min:8'],
            'organization_id' => ['nullable', 'uuid', 'exists:organizations,id'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'organization_id' => $request->organization_id,
        ]);

        // If organization provided, attach user to it at the configured default
        // role. (Previously 'org-member' plus a non-existent 'id' column, which
        // made every registration-with-organization fail — see the same repair
        // in OrganizationService::createOrganization.)
        if ($request->organization_id) {
            $organization = Organization::find($request->organization_id);
            if ($organization) {
                $organization->users()->attach($user->id, [
                    'role' => OrganizationRole::default()->value,
                ]);
            }
        }

        // Create Sanctum token
        $token = $user->createToken('auth-token', ['*'], now()->addHour());

        return response()->json([
            'success' => true,
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'organizations' => $user->organizations,
                ],
                'token' => $token->plainTextToken,
                'token_type' => 'Bearer',
                'expires_in' => 3600,
            ],
            'message' => 'User registered successfully',
        ], 201);
    }

    /**
     * Login user and create token.
     */
    public function login(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        // Attempt to authenticate user
        if (! Auth::attempt($request->only('email', 'password'))) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid credentials',
            ], 401);
        }

        $user = Auth::user();

        // Check if user is active
        if (! $user->is_active) {
            Auth::logout();

            return response()->json([
                'success' => false,
                'message' => 'Account is disabled',
            ], 403);
        }

        // Rotate session tokens only — named API keys (e.g. MCP clients)
        // must survive interactive logins.
        $user->tokens()->where('name', 'auth-token')->delete();
        $token = $user->createToken('auth-token', ['*'], now()->addHour());

        // Load user's organizations
        $user->load('organizations');

        // Try to load last organization
        if (! $this->organizationService->loadLastOrganization($user)) {
            // No last organization, use first org
            $firstOrg = $user->organizations()->first();
            if ($firstOrg) {
                $this->organizationService->setCurrentOrganization($firstOrg);
            }
        }

        return response()->json([
            'success' => true,
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'is_superadmin' => $user->is_superadmin,
                    'is_active' => $user->is_active,
                    'organizations' => $user->organizations,
                    'current_organization_id' => $this->organizationService->getCurrentOrganizationId(),
                    'current_organization_role' => $this->organizationService->getUserRole(),
                    // What the dashboard uses to decide which write controls to
                    // show. Advisory only — every endpoint still authorizes.
                    'permissions' => currentPermissions(),
                ],
                'token' => $token->plainTextToken,
                'token_type' => 'Bearer',
                'expires_in' => 3600,
            ],
            'message' => 'Login successful',
        ]);
    }

    /**
     * Get authenticated user.
     */
    public function me(Request $request): JsonResponse
    {
        $user = Auth::user();

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated',
            ], 401);
        }

        $user->load('organizations');

        return response()->json([
            'success' => true,
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'is_superadmin' => $user->is_superadmin,
                    'is_active' => $user->is_active,
                    'organizations' => $user->organizations,
                    'current_organization_id' => $this->organizationService->getCurrentOrganizationId(),
                    'current_organization_role' => $this->organizationService->getUserRole(),
                    // What the dashboard uses to decide which write controls to
                    // show. Advisory only — every endpoint still authorizes.
                    'permissions' => currentPermissions(),
                ],
            ],
            'message' => 'User retrieved successfully',
        ]);
    }

    /**
     * Logout user and revoke token.
     */
    public function logout(Request $request): JsonResponse
    {
        $user = Auth::user();

        if ($user) {
            // Revoke current token
            $request->user()->currentAccessToken()->delete();
        }

        return response()->json([
            'success' => true,
            'message' => 'Logged out successfully',
        ]);
    }

    /**
     * Refresh token.
     */
    public function refresh(Request $request): JsonResponse
    {
        $user = Auth::user();

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated',
            ], 401);
        }

        // Scoped API keys must not refresh: the refreshed token carries ['*'],
        // so allowing it would let a limited key escalate to full access.
        $current = $request->user()->currentAccessToken();
        if (! $current->can('*')) {
            return response()->json([
                'success' => false,
                'message' => 'API keys cannot be refreshed. Issue a new key instead.',
            ], 403);
        }

        // Delete current token and create new one
        $current->delete();
        $token = $user->createToken('auth-token', ['*'], now()->addHour());

        return response()->json([
            'success' => true,
            'data' => [
                'token' => $token->plainTextToken,
                'token_type' => 'Bearer',
                'expires_in' => 3600,
            ],
            'message' => 'Token refreshed successfully',
        ]);
    }
}
