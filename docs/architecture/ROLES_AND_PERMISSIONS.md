# 🔑 Roles & Permissions

Two axes, deliberately separate:

| | Held via | Scope | Named by |
|---|---|---|---|
| **Platform administration** | `users.is_superadmin` (boolean) | the whole installation | — |
| **Organization role** | `organization_user.role` (pivot) | one tenant | `App\Enums\OrganizationRole` |

A platform administrator is **not** an organization role and is never written to
the pivot. Mixing the two is what produced four disagreeing role vocabularies
and the habit of adding superadmins to every organization as a workaround.

> Earlier drafts described "numeric roles (Superadmin 999 / Owner 100 / …)".
> The numbers are still the `level` ordering in `config/permissions.php`, but the
> roles themselves are strings on a pivot — see below.

## The single source of truth

**`config/permissions.php`** holds every rule. A policy asks
`$user->can('resources.update')`; the `Gate::before` hook in
`AppServiceProvider` looks the ability up in the acting role's permission list.
Policies add only *context* on top — same organization, ownership, whether the
collection is active — and no longer carry their own role lists.

```
config/permissions.php
├── roles.{superadmin|owner|admin|editor|viewer}
│   ├── level         numeric ordering (999 / 100 / 75 / 50 / 25)
│   └── permissions   dotted strings; `resources.*` is a prefix wildcard
├── role_hierarchy    who may assign whom
├── default_role      role on joining an organization (viewer)
└── role_limits       per-role caps within one organization
```

`App\Support\Permissions` is the only place that knows how a role's list is
looked up and how a wildcard matches. Both the Gate and the `/me` payload go
through it, so the client and the server cannot disagree about what
`resources.*` covers.

## The platform → organization bridge

Three helpers, and the distinction between them matters:

| Helper | Answers |
|---|---|
| `currentOrganizationRole()` | the **membership** role, or `null` when not a member |
| `currentEffectiveRole()` | the role authorization resolves against — `'superadmin'` for a platform admin, else the membership role |
| `currentPermissions()` | the resolved permission strings for the effective role |

A platform administrator holding `null` for `currentOrganizationRole()` is
correct: they hold no membership. `currentEffectiveRole()` is what lets them work
in an organization they were never added to, resolving to the config's
`superadmin` block.

Every policy also has a `before()` hook granting platform administrators
outright. `CategoryPolicy` and `CollectionPolicy` were the only two without one,
which is why a platform admin outside an organization used to be hard-403'd on
every collection and category.

## Organization roles

| Role | Level | May |
|---|---|---|
| `owner` | 100 | everything, including deleting the organization and appointing another owner |
| `admin` | 75 | manage people and content; cannot delete the organization or mint an owner |
| `editor` | 50 | create and edit content, curate workspace membership |
| `viewer` | 25 | read, download |

Retired: `org-admin` and `org-member`. Neither could ever reach the database —
both writers also passed an `id` column the pivot does not have (its key is
composite), so every call threw. `OrganizationPolicy::update` demanded
`org-admin`, which made it permanently false and turned membership management
into a platform-administrator errand.

### Assignment rules

`role_hierarchy` decides who may hand out what: nobody assigns above their own
rank, **except** that an owner may appoint another owner. Without that exception,
combined with the "you cannot demote the last owner" guard, ownership could never
be transferred at all.

Owners are uncapped (`role_limits.owner => null`) for the same reason — a
single-owner cap makes an organization unable to survive losing its owner. What
still cannot happen is reaching **zero** owners.

### Authorship

Authorship protects a record from peers, not from the people who run the
organization:

- `editor` — may edit and delete **their own** resources
- `admin` / `owner` — may edit and delete **anything in their organization**

`ResourcePolicy::update`/`delete` previously required ownership unconditionally,
so an organization owner could not correct a colleague's typo — while
`restore`/`forceDelete` already allowed exactly that. The two halves now agree,
and `restore`/`forceDelete` gained the organization check they were missing (an
admin in one organization could permanently destroy another's trashed resource
given its id).

## The two administration surfaces

Both are built on `SettingsShell` (frame, second-row tab bar, panel switching),
so they navigate identically and gain tabs the same way.

| | `/admin` — Platform Administration | `/organization` — Organization settings |
|---|---|---|
| Audience | platform administrators | owner / admin of the current organization |
| Route guard | `PlatformRoute` redirects everyone else | reachable by any member; write controls gated |
| Endpoints | `/platform/*` | organization-scoped |
| Tabs | Users · Organizations · Collections · Schemes & Indexes · Vault Sharing · Tags · AI Services | Members · Collections · Tags |

**Tab bodies are not shared** where the endpoints differ: `/platform/users` and
`/organizations/{id}/users` have different columns (one picks an owning
organization) and different powers (one grants platform administration). A
`scope` prop threaded through every tab would put an `if (platform)` in each of
them, and each is somewhere a tenant-scoping mistake can hide.

**A tab body IS shared when its API is already organization-scoped.** `Tags` is
the case: `/semantic-tags` is plain `auth:sanctum` and the controller filters by
`currentOrganizationId()`, so semantic tags belong to a tenant, not the platform.
The same component serves both surfaces; in the platform panel it carries a note
saying so, because the surrounding title implies otherwise.

## Permission-aware UI

`/me` and `/login` return `permissions` — the resolved list for the effective
role. The dashboard uses it to decide **which controls to render**, never whether
an action is allowed; the API authorizes every request regardless.

Three behaviours, chosen by *why* the user cannot act:

| Situation | Control | Example |
|---|---|---|
| The role will never grant it | **hidden** | a viewer sees no "New Resource" |
| Theirs, but not available yet | **disabled + reason** | "Choose a collection first — every resource belongs to one" |
| The client cannot know | **acts, then 403** | an editor pressing Edit on a colleague's resource |

A permanently dead control is furniture, not information. A contextual block is a
precondition the user can clear, so it says what to do. The 403 stays reachable
because roles change mid-session and per-record rules are not knowable from a
toolbar.

Frontend helpers live in `src/constants/roles.ts` (`isPlatformAdmin`,
`canManageOrg`, `hasOrgRoleAtLeast`, `assignableRoles`, `can`) and
`src/hooks/usePermissions.ts`. `current_organization_role` is typed as the role
union, so a stray value is a compile error. Permissions are per-organization, so
`invalidatePermissions()` fires on organization switch and notifies mounted
consumers — a cache that was merely dropped left components holding the previous
organization's answer.

## Quotas

`organizations.collection_quota` caps how many collections a tenant's own people
may create; `null` falls back to `config('tydal.collection_quota')` (5). A
collection pins a field contract, a search index and the facets of everything in
it, so self-service creation is bounded. Platform administrators are exempt — the
cap governs a tenant's people, not the operators.

## TYDAL as identity provider for products

A product can let people sign in with their TYDAL account without ever holding
a TYDAL credential for them. `POST /api/v1/auth/identify` takes an email and
password and returns the user and their organizations with roles
(`{ data: { user, organizations: [{ id, slug, name, role }] } }`). It creates **no
token and no session** — unlike `/login`, which rotates the user's session
tokens and would sign them out of the SPA — so the product only learns *who*
someone is. It is rate-limited per email (5 attempts a minute: a product calls
from one server address for all its users) under a per-IP ceiling.

Full Frame's studio uses it: owner / admin / editor manage their organization's
exhibitions, a viewer gets a read-only studio, a platform admin signs in as the
installation admin. Exhibitions are filed under the vault's organization, which
the write-key probe (`GET …/w`) reports to write-key holders only. Revoking
access in TYDAL takes effect at the product's next sign-in (Full Frame keeps
curator sessions to 12 hours). `exhibitions:create --curator=email` creates or
reuses the user and adds them to the organization.

**Sign-in rate limits.** `/login` allows 5 failed attempts per email *and*
address a minute (a stranger can't lock an owner out from elsewhere), under a
per-address ceiling of 20 a minute against trying many emails; a success clears
the count. `/auth/identify` counts per email (5 a minute, 60 per address).
`/register` is capped at 10 a minute per address. A limited request gets 429
with `Retry-After`, and the SPA shows TYDAL's message.

## Guards worth knowing

- A platform administrator cannot **revoke their own** platform role, nor remove
  the **last** one that exists, nor deactivate their own account.
- The **last owner** of an organization cannot be demoted or removed.
- An organization's member list requires `organizations.view` — it previously had
  no authorization at all, so any authenticated user could read any
  organization's members.
- Resource and category **creation** is authorized in the FormRequest, before
  validation: neither used to be authorized at all, so a viewer could create
  both, and an unauthorized caller now gets a 403 rather than a field-by-field
  validation report.

## Removed

- `spatie/laravel-permission` — installed but entirely inert: `User` never used
  `HasRoles`, there was no `config/permission.php`, no Spatie migrations, and its
  `Gate::before` hook was never registered.
- `CheckOrganizationRole`, `EnsureHasOrganization`, `SetCurrentOrganization` —
  three middleware classes never aliased in `bootstrap/app.php` and never
  referenced by any route. Organization context resolves lazily instead.

## Tests

`backend/tests/Feature/RolesAndPermissionsTest.php` pins the behaviours that were
previously wrong: the platform bridge, organization membership management, the
hierarchy and last-owner guards, the authorship rule from both sides, the
cross-organization trash hole, the repaired organization-creation and
registration writes, viewer denial, and the wildcard semantics the client
mirrors.
