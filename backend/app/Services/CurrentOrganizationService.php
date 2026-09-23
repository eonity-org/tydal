<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

class CurrentOrganizationService
{
    private const SESSION_KEY = 'current_organization_id';

    /**
     * Cache for the current organization during the request.
     */
    private ?Organization $cachedOrganization = null;

    /**
     * Flag to track if we've already attempted to load from database.
     */
    private bool $hasAttemptedDatabaseLoad = false;

    /**
     * Set the current organization for the authenticated user.
     */
    public function setCurrentOrganization(string|Organization $organization): void
    {
        $organizationId = $organization instanceof Organization
            ? $organization->id
            : $organization;

        // Store in session
        session()->put(self::SESSION_KEY, $organizationId);

        // Clear cache to force reload
        $this->cachedOrganization = null;
        $this->hasAttemptedDatabaseLoad = false;

        // Update user's last_organization_id in database
        if (Auth::check()) {
            Auth::user()->update([
                'last_organization_id' => $organizationId,
            ]);
        }
    }

    /**
     * Get the current organization from session.
     * Automatically attempts to restore from database if not in session.
     */
    public function getCurrentOrganization(): ?Organization
    {
        // Return cached organization if available
        if ($this->cachedOrganization !== null) {
            return $this->cachedOrganization;
        }

        $organizationId = $this->getCurrentOrganizationId();

        // If no organization in session and we haven't tried loading from DB yet
        if (! $organizationId && ! $this->hasAttemptedDatabaseLoad && Auth::check()) {
            $this->hasAttemptedDatabaseLoad = true;
            $this->loadLastOrganization(Auth::user());
            $organizationId = $this->getCurrentOrganizationId();
        }

        if (! $organizationId) {
            $this->cachedOrganization = null;

            return null;
        }

        $this->cachedOrganization = Organization::find($organizationId);

        return $this->cachedOrganization;
    }

    /**
     * Get the current organization ID from session or database.
     */
    public function getCurrentOrganizationId(): ?string
    {
        // Stateless Bearer-token clients (MCP, integrations) never hit
        // /login, so they have no session and no last_organization_id.
        // They declare org context explicitly per request instead. An
        // inaccessible org named here is ignored (falls through below)
        // rather than trusted, so a bad header can't leak another org's
        // data.
        if ($headerOrgId = $this->getHeaderOrganizationId()) {
            return $headerOrgId;
        }

        // First try session
        $orgId = session(self::SESSION_KEY);
        if ($orgId) {
            return $orgId;
        }

        // For API requests without session cookies, query database directly
        if (Auth::check()) {
            // Fresh query to get latest last_organization_id from database
            $user = User::find(Auth::id());
            $lastOrgId = $user?->last_organization_id;

            if ($lastOrgId) {
                // Verify user still has access to this organization
                $userModel = Auth::user();
                if ($userModel->is_superadmin || ($user && $this->hasOrganizationAccess($user, $lastOrgId))) {
                    // Set in session for future requests
                    session()->put(self::SESSION_KEY, $lastOrgId);

                    return $lastOrgId;
                }
            }
        }

        return null;
    }

    /**
     * Resolve the X-Organization-ID header to an org id, if present and the
     * authenticated user actually has access to it.
     */
    private function getHeaderOrganizationId(): ?string
    {
        $headerOrgId = request()->header('X-Organization-ID');

        if (! $headerOrgId || ! Auth::check()) {
            return null;
        }

        $user = Auth::user();
        if ($user->is_superadmin || $this->hasOrganizationAccess($user, $headerOrgId)) {
            return $headerOrgId;
        }

        return null;
    }

    /**
     * Get the user's role in the current organization.
     */
    public function getUserRole(): ?string
    {
        $organization = $this->getCurrentOrganization();

        if (! $organization || ! Auth::check()) {
            return null;
        }

        $user = Auth::user();

        // Get the pivot record from the relationship
        $pivot = $organization->users()
            ->where('users.id', $user->id)
            ->first()
            ?->pivot;

        return $pivot?->role ?? null;
    }

    /**
     * Clear the current organization from session.
     */
    public function clearCurrentOrganization(): void
    {
        session()->forget(self::SESSION_KEY);
        $this->cachedOrganization = null;
        $this->hasAttemptedDatabaseLoad = false;
    }

    /**
     * Check if a user has access to a specific organization.
     */
    public function hasOrganizationAccess(User $user, string $organizationId): bool
    {
        return $user->organizations()
            ->where('organizations.id', $organizationId)
            ->where('organizations.is_active', true)
            ->exists();
    }

    /**
     * Load the user's last selected organization into session.
     */
    public function loadLastOrganization(User $user): bool
    {
        if (! $user->last_organization_id) {
            return false;
        }

        // Verify user still has access to this organization
        if (! $this->hasOrganizationAccess($user, $user->last_organization_id)) {
            return false;
        }

        session()->put(self::SESSION_KEY, $user->last_organization_id);

        return true;
    }
}
