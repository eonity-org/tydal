<?php

use App\Enums\FileRelation;
use App\Enums\FileRole;
use App\Enums\OrganizationType;
use App\Enums\ResourceState;
use App\Enums\ResourceType;
use App\Enums\VaultState;
use App\Models\Organization;
use App\Services\CurrentOrganizationService;
use App\Services\VersionService;
use App\Support\Permissions;

if (! function_exists('currentOrganization')) {
    /**
     * Get the current organization for the authenticated user.
     */
    function currentOrganization(): ?Organization
    {
        return app(CurrentOrganizationService::class)->getCurrentOrganization();
    }
}

if (! function_exists('currentOrganizationId')) {
    /**
     * Get the current organization ID from session.
     */
    function currentOrganizationId(): ?string
    {
        return app(CurrentOrganizationService::class)->getCurrentOrganizationId();
    }
}

if (! function_exists('currentOrganizationRole')) {
    /**
     * The authenticated user's MEMBERSHIP role in the current organization —
     * the `organization_user.role` pivot, or null when they are not a member.
     *
     * A platform admin who is not a member gets null here. That is correct and
     * deliberate: they hold no membership. For "what may this person do", ask
     * currentEffectiveRole() instead.
     */
    function currentOrganizationRole(): ?string
    {
        return app(CurrentOrganizationService::class)->getUserRole();
    }
}

if (! function_exists('currentEffectiveRole')) {
    /**
     * The role authorization should actually be resolved against.
     *
     * A platform admin (`users.is_superadmin`) acts as the platform role in
     * every organization, whether or not they are a member of it. Everyone
     * else acts as their membership role.
     *
     * This is the bridge that used to be missing: organization *context* was
     * already superadmin-aware (CurrentOrganizationService::getCurrentOrganizationId),
     * but the *role* was a bare pivot lookup, so a platform admin outside an
     * organization resolved to null and was denied by every policy that asked
     * about roles. Hence the habit of adding superadmins to every organization.
     */
    function currentEffectiveRole(): ?string
    {
        $user = auth()->user();

        if ($user && $user->isSuperAdmin()) {
            return 'superadmin';
        }

        return currentOrganizationRole();
    }
}

if (! function_exists('currentPermissions')) {
    /**
     * Everything the authenticated user may do in the current organization,
     * as permission strings (wildcards included).
     *
     * Sent to the client on /me so the dashboard can hide controls the user
     * has no permission for, rather than letting them press a button and meet
     * a 403. The server remains the authority — this is advisory.
     *
     * @return array<int, string>
     */
    function currentPermissions(): array
    {
        return Permissions::forRole(currentEffectiveRole());
    }
}

if (! function_exists('version')) {
    /**
     * Get the current application version.
     *
     * @param  string  $format  The format to return (version-only, compact, full)
     */
    function version(string $format = 'compact'): string
    {
        try {
            return app(VersionService::class)->format($format) ?: '1.0.0';
        } catch (Exception $e) {
            return '1.0.0';
        }
    }
}

if (! function_exists('build')) {
    /**
     * Get the current build number (git commit hash).
     */
    function build(): string
    {
        try {
            return app(VersionService::class)->getBuild();
        } catch (Exception $e) {
            return 'unknown';
        }
    }
}

// ============================================================================
// Enum Helper Functions
// ============================================================================

if (! function_exists('organization_types')) {
    /**
     * Get all organization types
     *
     * @return array<string>
     */
    function organization_types(): array
    {
        return OrganizationType::values();
    }
}

if (! function_exists('resource_types')) {
    /**
     * Get all resource types
     *
     * @return array<string>
     */
    function resource_types(): array
    {
        return ResourceType::values();
    }
}

if (! function_exists('file_roles')) {
    /**
     * Get all file roles
     *
     * @return array<string>
     */
    function file_roles(): array
    {
        return FileRole::values();
    }
}

if (! function_exists('file_relations')) {
    /**
     * Get all file relation types
     *
     * @return array<string>
     */
    function file_relations(): array
    {
        return FileRelation::values();
    }
}

if (! function_exists('resource_states')) {
    /**
     * Get all resource lifecycle states
     *
     * @return array<string>
     */
    function resource_states(): array
    {
        return ResourceState::values();
    }
}

if (! function_exists('vault_states')) {
    /**
     * Get all vault boundary states
     *
     * @return array<string>
     */
    function vault_states(): array
    {
        return VaultState::values();
    }
}

if (! function_exists('multimedia_types')) {
    /**
     * Get all multimedia resource types
     *
     * @return array<string>
     */
    function multimedia_types(): array
    {
        return ResourceType::multimediaTypes();
    }
}

if (! function_exists('educational_types')) {
    /**
     * Get all educational resource types
     *
     * @return array<string>
     */
    function educational_types(): array
    {
        return ResourceType::educationalTypes();
    }
}

if (! function_exists('document_types')) {
    /**
     * Get all document resource types
     *
     * @return array<string>
     */
    function document_types(): array
    {
        return ResourceType::documentTypes();
    }
}

if (! function_exists('is_valid_resource_type')) {
    /**
     * Check if a string is a valid resource type
     */
    function is_valid_resource_type(string $type): bool
    {
        return ResourceType::isValid($type);
    }
}

if (! function_exists('is_valid_organization_type')) {
    /**
     * Check if a string is a valid organization type
     */
    function is_valid_organization_type(string $type): bool
    {
        return OrganizationType::isValid($type);
    }
}

if (! function_exists('is_multimedia')) {
    /**
     * Check if a resource type is multimedia
     */
    function is_multimedia(string $type): bool
    {
        return in_array($type, multimedia_types(), true);
    }
}

// ============================================================================
// File & Media Helper Functions
// ============================================================================

if (! function_exists('is_media_mime_type')) {
    /**
     * Check if a MIME type represents a media file (image, video, audio)
     */
    function is_media_mime_type(string $mimeType): bool
    {
        return str_starts_with($mimeType, 'image/') ||
               str_starts_with($mimeType, 'video/') ||
               str_starts_with($mimeType, 'audio/');
    }
}

if (! function_exists('is_document_mime_type')) {
    /**
     * Check if a MIME type represents a document
     */
    function is_document_mime_type(string $mimeType): bool
    {
        return str_starts_with($mimeType, 'application/') ||
               str_starts_with($mimeType, 'text/');
    }
}

if (! function_exists('get_media_conversion_sizes')) {
    /**
     * Get available media conversion sizes
     *
     * @return array<string>
     */
    function get_media_conversion_sizes(): array
    {
        return ['thumbnail', 'small', 'medium', 'large'];
    }
}
