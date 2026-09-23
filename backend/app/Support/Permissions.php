<?php

namespace App\Support;

/**
 * Reading config/permissions.php — the one place that knows how a role's
 * permission list is looked up and how a wildcard matches.
 *
 * Both the Gate and the /me payload go through here. The dashboard hides
 * controls the user has no permission for, which means the client evaluates
 * the same strings the server does; if the two ever disagreed about what
 * `resources.*` covers, the UI would offer buttons the API refuses, or hide
 * ones it would have allowed. One matcher, shared, so that cannot drift.
 */
final class Permissions
{
    /**
     * The permission strings granted to a role, verbatim — wildcards included.
     *
     * @return array<int, string>
     */
    public static function forRole(?string $role): array
    {
        if (! $role) {
            return [];
        }

        return array_values((array) config("permissions.roles.{$role}.permissions", []));
    }

    /**
     * Does this permission list grant the ability?
     *
     * `resources.*` grants `resources.update` and the bare `resources`.
     *
     * @param  array<int, string>  $permissions
     */
    public static function allows(array $permissions, string $ability): bool
    {
        foreach ($permissions as $permission) {
            if ($permission === $ability) {
                return true;
            }

            if (str_ends_with($permission, '.*')) {
                $prefix = substr($permission, 0, -2);
                if (str_starts_with($ability, $prefix.'.') || $ability === $prefix) {
                    return true;
                }
            }
        }

        return false;
    }
}
