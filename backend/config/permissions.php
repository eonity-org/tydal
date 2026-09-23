<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Roles and their permissions — the single source of truth
    |--------------------------------------------------------------------------
    |
    | Every authorization decision resolves through this file. A policy asks
    | `$user->can('resources.update')`; the Gate::before hook in
    | AppServiceProvider looks the ability up in the acting role's list here.
    | Policies add only CONTEXT on top (same organization, ownership, whether
    | the collection is active) — they no longer carry their own role lists.
    |
    | Two axes live here, and they are not the same thing:
    |   - `superadmin` is a PLATFORM role, held via `users.is_superadmin`. It is
    |     never written to `organization_user.role`. It is how a platform admin
    |     works inside an organization without being a member of it.
    |   - owner / admin / editor / viewer are ORGANIZATION roles, held via the
    |     `organization_user` pivot and named by App\Enums\OrganizationRole.
    |
    | `level` orders the roles; `role_hierarchy` says who may assign whom.
    | A `*` suffix is a prefix wildcard: `resources.*` grants `resources.update`.
    |
    */

    'roles' => [
        'superadmin' => [
            'name' => 'Platform Superadmin',
            'description' => 'Full platform-wide access across all organizations',
            'level' => 999,
            'is_platform_role' => true, // Marks this as a platform-level role
            'permissions' => [
                // Platform-level operations
                'platform.*',
                'platform.organizations.create',
                'platform.organizations.suspend',
                'platform.organizations.delete',
                'platform.organizations.view-all',
                'platform.users.manage',
                'platform.billing.manage',
                'platform.settings.*',
                'platform.analytics.*',
                'platform.support.*',

                // All organization-level permissions (bypass org restrictions)
                'organizations.*',
                'users.*',
                'workspaces.*',
                'collections.*',
                'resources.*',
                'categories.*',
                'tags.*',
                'settings.*',
            ],
        ],

        'owner' => [
            'name' => 'Owner',
            'description' => 'Full control over the organization',
            'level' => 100,
            'permissions' => [
                // Organization management
                'organizations.*',
                'organizations.delete',

                // User management
                'users.*',
                'users.invite',
                'users.remove',
                'users.update-role',

                // Full workspace, collection, resource control
                'workspaces.*',
                'collections.*',
                'resources.*',
                'categories.*',
                'tags.*',

                // Settings
                'settings.*',
            ],
        ],

        'admin' => [
            'name' => 'Administrator',
            'description' => 'Administrative access to all resources',
            'level' => 75,
            'permissions' => [
                // Most organization management (cannot delete org)
                'organizations.view',
                'organizations.update',

                // User management (cannot remove owner)
                'users.view',
                'users.invite',
                'users.remove',
                'users.update-role',

                // Full workspace, collection, resource control
                'workspaces.*',
                'collections.*',
                'resources.*',
                'categories.*',
                'tags.*',

                // Settings
                'settings.view',
                'settings.update',
            ],
        ],

        'editor' => [
            'name' => 'Editor',
            'description' => 'Can create and edit content',
            'level' => 50,
            'permissions' => [
                // View organization
                'organizations.view',

                // Workspaces: may curate membership of one, but creating and
                // deleting workspaces is an admin act — a workspace is what a
                // vault projects, so spawning one is a publishing decision.
                'workspaces.view',
                'workspaces.manage-resources',

                // Collections: read and edit, but not create. A collection
                // carries a scheme and an ES index; creating one is structural.
                'collections.view',
                'collections.update',

                // Resource management
                'resources.view',
                'resources.create',
                'resources.update',
                'resources.delete',
                'resources.upload',

                // Category management
                'categories.view',
                'categories.create',
                'categories.update',
                'categories.delete',

                // Tag management
                'tags.view',
                'tags.create',
                'tags.update',
                'tags.delete',
            ],
        ],

        'viewer' => [
            'name' => 'Viewer',
            'description' => 'Read-only access to content',
            'level' => 25,
            'permissions' => [
                // View only
                'organizations.view',
                'workspaces.view',
                'collections.view',
                'resources.view',
                'resources.download',
                'categories.view',
                'tags.view',
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Who may assign whom
    |--------------------------------------------------------------------------
    |
    | Enforced by OrganizationRole::canAssign() and by the membership endpoints
    | in OrganizationController. Nobody can hand out their own rank or higher,
    | so an admin cannot mint an owner and an editor cannot promote anyone.
    |
    */

    'role_hierarchy' => [
        'superadmin' => ['owner', 'admin', 'editor', 'viewer'],
        // An owner may appoint another owner. Without that, and with the
        // "you cannot demote the last owner" guard, ownership could never be
        // handed over at all except by a platform admin — a deadlock.
        'owner' => ['owner', 'admin', 'editor', 'viewer'],
        'admin' => ['editor', 'viewer'],
        'editor' => [],
        'viewer' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Default role on joining an organization
    |--------------------------------------------------------------------------
    |
    | Read by OrganizationRole::default(); also the DB default on the
    | `organization_user.role` column.
    |
    */

    'default_role' => 'viewer',

    /*
    |--------------------------------------------------------------------------
    | Per-role caps within one organization (null = unlimited)
    |--------------------------------------------------------------------------
    |
    | Read by OrganizationRole::limit() and enforced on the membership
    | endpoints.
    |
    | Owners are deliberately uncapped. Capping them at one, as this file
    | originally did, means an organization can never survive losing its owner
    | and can never transfer ownership; a second owner is the ordinary remedy.
    | What still cannot happen is reaching ZERO owners — the membership
    | endpoints refuse to demote or remove the last one.
    |
    */

    'role_limits' => [
        'owner' => null,
        'admin' => null,
        'editor' => null,
        'viewer' => null,
    ],
];
