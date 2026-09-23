<?php

namespace App\Enums;

use App\Enums\Traits\HasEnumHelpers;

/**
 * What a user may do *inside one organization* — the `organization_user.role`
 * pivot.
 *
 * Deliberately not the same axis as platform administration. `users.is_superadmin`
 * is a capability over the whole installation and is never written here; it
 * resolves to the `superadmin` block of config/permissions.php instead (see
 * currentEffectiveRole() in app/helpers.php). Mixing the two is what produced
 * the four disagreeing role vocabularies this enum replaces.
 *
 * The permission lists and the numeric levels live in config/permissions.php,
 * which stays the single source of truth; this enum only names the roles and
 * answers ordering questions about them.
 *
 * Retired values: `org-admin` and `org-member`. Both were written by code that
 * also passed a non-existent `id` column to the pivot, so neither could ever
 * reach the database — see the repair in OrganizationService::createOrganization.
 */
enum OrganizationRole: string
{
    use HasEnumHelpers;

    /** Full control, including deleting the organization. One per org. */
    case OWNER = 'owner';

    /** Administers people and content, but cannot delete the organization. */
    case ADMIN = 'admin';

    /** Creates and edits content. */
    case EDITOR = 'editor';

    /** Read-only. */
    case VIEWER = 'viewer';

    /** Rank, from config/permissions.php. Higher wins. */
    public function level(): int
    {
        return (int) config("permissions.roles.{$this->value}.level", 0);
    }

    public function label(): string
    {
        return (string) config("permissions.roles.{$this->value}.name", ucfirst($this->value));
    }

    /** Is this role at least as powerful as $other? */
    public function atLeast(self $other): bool
    {
        return $this->level() >= $other->level();
    }

    /**
     * May this role assign $other to somebody?
     *
     * Reads `role_hierarchy` from the config, so an admin cannot mint an owner
     * even though an admin may otherwise manage membership.
     */
    public function canAssign(self $other): bool
    {
        $assignable = (array) config("permissions.role_hierarchy.{$this->value}", []);

        return in_array($other->value, $assignable, true);
    }

    /**
     * The roles this role may hand out, in descending rank — what the member
     * management UI should offer.
     *
     * @return array<int, self>
     */
    public function assignableRoles(): array
    {
        $roles = array_values(array_filter(
            self::cases(),
            fn (self $candidate) => $this->canAssign($candidate),
        ));

        usort($roles, fn (self $a, self $b) => $b->level() <=> $a->level());

        return $roles;
    }

    /** The role a user gets on joining an organization with nothing specified. */
    public static function default(): self
    {
        return self::tryFrom((string) config('permissions.default_role', 'viewer')) ?? self::VIEWER;
    }

    /**
     * How many users of this role an organization may have, or null for no
     * limit. `owner` is capped at 1 by config.
     */
    public function limit(): ?int
    {
        $limit = config("permissions.role_limits.{$this->value}");

        return $limit === null ? null : (int) $limit;
    }
}
