<?php

namespace App\Http\Controllers\API;

use App\Enums\OrganizationRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreOrganizationRequest;
use App\Http\Requests\UpdateOrganizationRequest;
use App\Models\Organization;
use App\Models\User;
use App\Services\Interfaces\OrganizationServiceInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OrganizationController extends Controller
{
    protected OrganizationServiceInterface $organizationService;

    public function __construct(OrganizationServiceInterface $organizationService)
    {
        $this->organizationService = $organizationService;
    }

    /**
     * Display a listing of the organizations for the authenticated user.
     */
    public function index(): JsonResponse
    {
        $organizations = $this->organizationService->getUserOrganizations();

        return response()->json([
            'success' => true,
            'data' => [
                'organizations' => $organizations,
            ],
            'message' => 'Organizations retrieved successfully',
        ]);
    }

    /**
     * Store a newly created organization in storage.
     */
    public function store(StoreOrganizationRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['slug'] = \Str::slug($data['name']);

        $organization = $this->organizationService->createOrganization($data);

        return response()->json([
            'success' => true,
            'data' => [
                'organization' => $organization,
            ],
            'message' => 'Organization created successfully',
        ], 201);
    }

    /**
     * Display the specified organization.
     */
    public function show(string $id): JsonResponse
    {
        $organization = $this->organizationService->getOrganizationById($id);

        if (! $organization) {
            return response()->json([
                'success' => false,
                'message' => 'Organization not found',
            ], 404);
        }

        $this->authorize('view', $organization);

        return response()->json([
            'success' => true,
            'data' => [
                'organization' => $organization,
                // What the organization settings page needs to say "3 of 5
                // used" before offering the button, rather than letting
                // someone fill in a form and meet a 422.
                'collections' => [
                    'used' => $organization->collections()->count(),
                    'quota' => $organization->collectionQuota(),
                ],
            ],
            'message' => 'Organization retrieved successfully',
        ]);
    }

    /**
     * Update the specified organization in storage.
     */
    public function update(UpdateOrganizationRequest $request, string $id): JsonResponse
    {
        $organization = $this->organizationService->getOrganizationById($id);

        if (! $organization) {
            return response()->json([
                'success' => false,
                'message' => 'Organization not found',
            ], 404);
        }

        $this->authorize('update', $organization);

        $data = $request->validated();
        if (isset($data['name'])) {
            $data['slug'] = \Str::slug($data['name']);
        }

        $organization = $this->organizationService->updateOrganization($id, $data);

        return response()->json([
            'success' => true,
            'data' => [
                'organization' => $organization,
            ],
            'message' => 'Organization updated successfully',
        ]);
    }

    /**
     * Remove the specified organization from storage.
     */
    public function destroy(string $id): JsonResponse
    {
        $organization = $this->organizationService->getOrganizationById($id);

        if (! $organization) {
            return response()->json([
                'success' => false,
                'message' => 'Organization not found',
            ], 404);
        }

        $this->authorize('delete', $organization);

        $deleted = $this->organizationService->deleteOrganization($id);

        return response()->json([
            'success' => $deleted,
            'message' => $deleted ? 'Organization deleted successfully' : 'Failed to delete organization',
        ]);
    }

    /**
     * Get users in an organization.
     */
    public function users(string $organizationId): JsonResponse
    {
        $organization = $this->organizationService->getOrganizationById($organizationId);

        if (! $organization) {
            return response()->json([
                'success' => false,
                'message' => 'Organization not found',
            ], 404);
        }

        // This endpoint had no authorization at all — any authenticated user
        // could read any organization's member list.
        $this->authorize('view', $organization);

        $users = $this->organizationService->getOrganizationUsers($organizationId);

        if (! $users) {
            return response()->json([
                'success' => false,
                'message' => 'Organization not found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'users' => $users,
            ],
            'message' => 'Organization users retrieved successfully',
        ]);
    }

    /**
     * Switch the current user's active organization.
     */
    public function switch(string $id): JsonResponse
    {
        $organization = $this->organizationService->getOrganizationById($id);

        if (! $organization) {
            return response()->json([
                'success' => false,
                'message' => 'Organization not found',
            ], 404);
        }

        $user = auth()->user();

        // Superadmins can switch to any organization
        if (! $user->is_superadmin) {
            // Check if user belongs to this organization
            if (! $user->organizations()->where('organizations.id', $id)->exists()) {
                return response()->json([
                    'success' => false,
                    'message' => 'You do not have access to this organization',
                ], 403);
            }
        }

        // Store in database (for API statelessness)
        $user->update(['last_organization_id' => $id]);

        // Refresh the user from database to get updated values
        $user->refresh();

        // Also set in session for web requests
        session()->put('current_organization_id', $id);

        return response()->json([
            'success' => true,
            'message' => 'Organization switched successfully',
            'data' => [
                'organization' => $organization,
            ],
        ]);
    }

    /**
     * Add a user to an organization.
     */
    public function addUser(Request $request, string $organizationId): JsonResponse
    {
        // Either identifier works. An organization admin has no way to look up
        // user ids — user search is a platform-only endpoint — so without the
        // email form they could not invite anybody. Requiring the exact address
        // keeps this from being a directory people can browse.
        $request->validate([
            'user_id' => 'required_without:email|exists:users,id',
            'email' => 'required_without:user_id|email',
            'role' => ['required', Rule::enum(OrganizationRole::class)],
        ]);

        $organization = $this->organizationService->getOrganizationById($organizationId);

        if (! $organization) {
            return response()->json([
                'success' => false,
                'message' => 'Organization not found',
            ], 404);
        }

        $this->authorize('update', $organization);

        $role = OrganizationRole::from($request->role);

        $userId = $request->input('user_id')
            ?? User::where('email', $request->input('email'))->value('id');

        if (! $userId) {
            return response()->json([
                'success' => false,
                'message' => 'No user with that email address. They need an account first.',
            ], 404);
        }

        if ($refusal = $this->refuseRoleAssignment($organization, $role)) {
            return $refusal;
        }

        if ($organization->users()->where('users.id', $userId)->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'That user is already a member of this organization.',
            ], 422);
        }

        $organization->users()->attach($userId, [
            'role' => $role->value,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'User added to organization successfully',
        ], 201);
    }

    /**
     * Update a user's role in an organization.
     */
    public function updateUser(Request $request, string $organizationId, string $userId): JsonResponse
    {
        $request->validate([
            'role' => ['required', Rule::enum(OrganizationRole::class)],
        ]);

        $organization = $this->organizationService->getOrganizationById($organizationId);

        if (! $organization) {
            return response()->json([
                'success' => false,
                'message' => 'Organization not found',
            ], 404);
        }

        $this->authorize('update', $organization);

        $role = OrganizationRole::from($request->role);
        $currentRole = $this->memberRole($organization, $userId);

        if (! $currentRole) {
            return response()->json([
                'success' => false,
                'message' => 'That user is not a member of this organization.',
            ], 404);
        }

        // Demoting the only owner would leave the organization with nobody able
        // to administer it.
        if ($currentRole === OrganizationRole::OWNER && $role !== OrganizationRole::OWNER
            && $this->countRole($organization, OrganizationRole::OWNER) <= 1) {
            return response()->json([
                'success' => false,
                'message' => 'This is the only owner. Promote someone else to owner first.',
            ], 422);
        }

        if ($refusal = $this->refuseRoleAssignment($organization, $role, $userId)) {
            return $refusal;
        }

        $organization->users()->updateExistingPivot($userId, [
            'role' => $role->value,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'User role updated successfully',
        ]);
    }

    /**
     * Remove a user from an organization.
     */
    public function removeUser(string $organizationId, string $userId): JsonResponse
    {
        $organization = $this->organizationService->getOrganizationById($organizationId);

        if (! $organization) {
            return response()->json([
                'success' => false,
                'message' => 'Organization not found',
            ], 404);
        }

        $this->authorize('update', $organization);

        $currentRole = $this->memberRole($organization, $userId);

        if ($currentRole === OrganizationRole::OWNER
            && $this->countRole($organization, OrganizationRole::OWNER) <= 1) {
            return response()->json([
                'success' => false,
                'message' => 'This is the only owner. Promote someone else to owner first.',
            ], 422);
        }

        $organization->users()->detach($userId);

        return response()->json([
            'success' => true,
            'message' => 'User removed from organization successfully',
        ]);
    }

    /**
     * Refuse an assignment the caller is not entitled to make, or that would
     * breach a per-role cap. Returns null when the assignment is allowed.
     *
     * Two rules, both from config/permissions.php: `role_hierarchy` (nobody
     * hands out their own rank — an admin cannot mint an owner) and
     * `role_limits` (one owner per organization). Platform admins are bound by
     * the caps but not by the hierarchy; that is what the `superadmin` row of
     * role_hierarchy says.
     */
    private function refuseRoleAssignment(
        Organization $organization,
        OrganizationRole $role,
        ?string $excludeUserId = null,
    ): ?JsonResponse {
        $actor = currentEffectiveRole();
        $mayAssign = $actor === 'superadmin'
            || (($actorRole = OrganizationRole::tryFromValue($actor)) && $actorRole->canAssign($role));

        if (! $mayAssign) {
            return response()->json([
                'success' => false,
                'message' => "You cannot assign the {$role->label()} role.",
            ], 403);
        }

        $limit = $role->limit();
        if ($limit !== null && $this->countRole($organization, $role, $excludeUserId) >= $limit) {
            return response()->json([
                'success' => false,
                'message' => "This organization already has its {$role->label()}. Change the existing one first.",
            ], 422);
        }

        return null;
    }

    /** The member's current role, or null when they are not a member. */
    private function memberRole(Organization $organization, string $userId): ?OrganizationRole
    {
        $pivot = $organization->users()->where('users.id', $userId)->first()?->pivot;
        $role = $pivot?->getAttribute('role');

        return OrganizationRole::tryFromValue(is_string($role) ? $role : null);
    }

    /** How many members hold this role, optionally ignoring one user. */
    private function countRole(Organization $organization, OrganizationRole $role, ?string $excludeUserId = null): int
    {
        return $organization->users()
            ->where('organization_user.role', $role->value)
            ->when($excludeUserId, fn ($q) => $q->where('users.id', '!=', $excludeUserId))
            ->count();
    }
}
