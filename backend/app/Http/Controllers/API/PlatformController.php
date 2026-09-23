<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Collection;
use App\Models\CollectionScheme;
use App\Models\File;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Interfaces\CollectionServiceInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class PlatformController extends Controller
{
    // ==========================================================================
    // ORGANIZATIONS
    // ==========================================================================

    /**
     * List all organizations with stats.
     */
    public function listOrganizations(Request $request): JsonResponse
    {
        $organizations = Organization::withCount(['resources', 'users', 'collections'])
            ->when($request->search, fn ($q) => $q->where('name', 'like', "%{$request->search}%"))
            ->orderBy('name')
            ->paginate($request->input('per_page', 20));

        return response()->json([
            'success' => true,
            'data' => ['organizations' => $organizations],
        ]);
    }

    /**
     * Show a single organization.
     */
    public function showOrganization(string $id): JsonResponse
    {
        $organization = Organization::withCount(['users', 'resources', 'collections'])
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => ['organization' => $organization],
        ]);
    }

    /**
     * Create a new organization.
     */
    public function createOrganization(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'type' => 'required|in:individual,business,educational,government,non_profit',
            'description' => 'nullable|string|max:1000',
            'owner_email' => 'required|email|exists:users,email',
        ]);

        return DB::transaction(function () use ($request) {
            $owner = User::where('email', $request->owner_email)->firstOrFail();

            $organization = Organization::create([
                'name' => $request->name,
                'slug' => str($request->name)->slug()->toString(),
                'type' => $request->type,
                'description' => $request->description,
                'is_active' => true,
            ]);

            $organization->users()->attach($owner->id, ['role' => 'owner']);

            Workspace::create([
                'organization_id' => $organization->id,
                'user_owner_id' => $owner->id,
                'name' => 'All Resources',
                'slug' => $organization->slug.'-all',
                'description' => 'All resources in this organization',
                'is_active' => true,
                'is_default' => true,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Organization created successfully',
                'data' => ['organization' => $organization->loadCount(['users', 'collections'])],
            ], 201);
        });
    }

    /**
     * Update an organization.
     */
    public function updateOrganization(Request $request, string $id): JsonResponse
    {
        $organization = Organization::findOrFail($id);

        $request->validate([
            'name' => 'sometimes|string|max:255',
            'type' => 'sometimes|in:individual,business,educational,government,non_profit',
            'description' => 'sometimes|nullable|string|max:1000',
            'is_active' => 'sometimes|boolean',
        ]);

        $data = $request->only(['name', 'type', 'description', 'is_active']);
        if (isset($data['name'])) {
            $data['slug'] = str($data['name'])->slug()->toString();
        }

        $organization->update($data);

        return response()->json([
            'success' => true,
            'message' => 'Organization updated successfully',
            'data' => ['organization' => $organization->loadCount(['users', 'collections'])],
        ]);
    }

    /**
     * Suspend an organization.
     */
    public function suspendOrganization(string $id): JsonResponse
    {
        $organization = Organization::findOrFail($id);
        $organization->update(['is_active' => false]);

        return response()->json([
            'success' => true,
            'message' => 'Organization suspended',
            'data' => ['organization' => $organization],
        ]);
    }

    /**
     * Activate a suspended organization.
     */
    public function activateOrganization(string $id): JsonResponse
    {
        $organization = Organization::findOrFail($id);
        $organization->update(['is_active' => true]);

        return response()->json([
            'success' => true,
            'message' => 'Organization activated',
            'data' => ['organization' => $organization],
        ]);
    }

    /**
     * Delete an organization.
     */
    public function deleteOrganization(string $id): JsonResponse
    {
        $organization = Organization::findOrFail($id);

        if ($organization->resources()->count() > 0) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete an organization that has resources',
            ], 422);
        }

        $organization->delete();

        return response()->json([
            'success' => true,
            'message' => 'Organization deleted successfully',
        ]);
    }

    /**
     * List workspaces belonging to an organization.
     */
    public function listOrgWorkspaces(string $id): JsonResponse
    {
        $organization = Organization::findOrFail($id);
        $workspaces = $organization->workspaces()->get(['id', 'name', 'slug']);

        return response()->json([
            'success' => true,
            'data' => ['workspaces' => $workspaces],
        ]);
    }

    // ==========================================================================
    // USERS
    // ==========================================================================

    /**
     * List all platform users.
     */
    public function listUsers(Request $request): JsonResponse
    {
        $users = User::withCount('organizations')
            ->when($request->search, fn ($q) => $q->where(function ($q) use ($request) {
                $q->where('name', 'like', "%{$request->search}%")
                    ->orWhere('email', 'like', "%{$request->search}%");
            }))
            ->when($request->has('is_superadmin'), fn ($q) => $q->where('is_superadmin', $request->boolean('is_superadmin')))
            ->orderBy('name')
            ->paginate($request->input('per_page', 20));

        return response()->json([
            'success' => true,
            'data' => ['users' => $users],
        ]);
    }

    /**
     * Show a single user.
     */
    public function showUser(string $id): JsonResponse
    {
        $user = User::withCount('organizations')
            ->with('organizations')
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => ['user' => $user],
        ]);
    }

    /**
     * Create a new user.
     */
    public function createUser(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8',
            'is_active' => 'boolean',
            'is_superadmin' => 'boolean',
            'organizations' => 'sometimes|array',
            'organizations.*.id' => 'required|uuid|exists:organizations,id',
            'organizations.*.role' => 'required|string|in:owner,admin,editor,viewer',
        ]);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'is_active' => $request->input('is_active', true),
            'is_superadmin' => $request->input('is_superadmin', false),
        ]);

        if ($request->has('organizations')) {
            $syncData = [];
            foreach ($request->organizations as $org) {
                $syncData[$org['id']] = ['role' => $org['role']];
            }
            $user->organizations()->sync($syncData);
        }

        return response()->json([
            'success' => true,
            'message' => 'User created successfully',
            'data' => ['user' => $user->loadCount('organizations')->load('organizations')],
        ], 201);
    }

    /**
     * Update a user.
     */
    public function updateUser(Request $request, string $id): JsonResponse
    {
        $user = User::findOrFail($id);

        $request->validate([
            'name' => 'sometimes|string|max:255',
            'email' => ['sometimes', 'email', Rule::unique('users', 'email')->ignore($id)],
            'password' => 'sometimes|string|min:8',
            'is_active' => 'sometimes|boolean',
            'is_superadmin' => 'sometimes|boolean',
            'organizations' => 'sometimes|array',
            'organizations.*.id' => 'required|uuid|exists:organizations,id',
            'organizations.*.role' => 'required|string|in:owner,admin,editor,viewer',
        ]);

        // Platform administration must not be able to lock itself out. Two
        // ways that could happen, both cheap to refuse: revoking your own
        // platform role mid-session, or removing the last one that exists.
        $losingPlatformRole = $request->has('is_superadmin')
            && ! $request->boolean('is_superadmin')
            && $user->is_superadmin;

        if ($losingPlatformRole) {
            if ((string) $user->id === (string) auth()->id()) {
                return response()->json([
                    'success' => false,
                    'message' => 'You cannot remove your own platform administrator role.',
                ], 422);
            }

            if (User::where('is_superadmin', true)->count() <= 1) {
                return response()->json([
                    'success' => false,
                    'message' => 'This is the only platform administrator. Appoint another one first.',
                ], 422);
            }
        }

        if ($request->has('is_active') && ! $request->boolean('is_active')
            && (string) $user->id === (string) auth()->id()) {
            return response()->json([
                'success' => false,
                'message' => 'You cannot deactivate your own account.',
            ], 422);
        }

        $data = $request->only(['name', 'email', 'is_active', 'is_superadmin']);
        if ($request->filled('password')) {
            $data['password'] = Hash::make($request->password);
        }

        $user->update($data);

        if ($request->has('organizations')) {
            $syncData = [];
            foreach ($request->organizations as $org) {
                $syncData[$org['id']] = ['role' => $org['role']];
            }
            $user->organizations()->sync($syncData);
        }

        return response()->json([
            'success' => true,
            'message' => 'User updated successfully',
            'data' => ['user' => $user->loadCount('organizations')->load('organizations')],
        ]);
    }

    /**
     * Delete a user (soft delete).
     */
    public function deleteUser(string $id): JsonResponse
    {
        $user = User::findOrFail($id);

        if ((string) $user->id === (string) auth()->id()) {
            return response()->json([
                'success' => false,
                'message' => 'You cannot delete your own account',
            ], 422);
        }

        $user->delete();

        return response()->json([
            'success' => true,
            'message' => 'User deleted successfully',
        ]);
    }

    // ==========================================================================
    // COLLECTIONS
    // ==========================================================================

    /**
     * List all collections across all organizations.
     */
    public function listCollections(Request $request): JsonResponse
    {
        $collections = Collection::with(['organization:id,name', 'scheme:id,name,display_name'])
            ->withCount('resources')
            ->when($request->search, fn ($q) => $q->where('collections.name', 'like', "%{$request->search}%"))
            ->when($request->organization_id, fn ($q) => $q->where('organization_id', $request->organization_id))
            ->orderBy('collections.name')
            ->paginate($request->input('per_page', 20));

        return response()->json([
            'success' => true,
            'data' => ['collections' => $collections],
        ]);
    }

    /**
     * Show a single collection.
     */
    public function showCollection(string $id): JsonResponse
    {
        $collection = Collection::with(['organization:id,name', 'scheme:id,name,display_name'])
            ->withCount('resources')
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => ['collection' => $collection],
        ]);
    }

    /**
     * Create a new collection (platform-level, bypasses org context).
     */
    public function createCollection(Request $request): JsonResponse
    {
        $request->validate([
            'organization_id' => 'required|uuid|exists:organizations,id',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'language' => 'required|string|max:10',
            'is_active' => 'boolean',
            'scheme_id' => 'nullable|uuid|exists:collection_schemes,id',
            'index_id' => 'nullable|uuid|exists:search_indexes,id',
        ]);

        $schemeId = $request->scheme_id
            ?? CollectionScheme::where('name', 'multimedia')->where('is_system', true)->value('id');

        // The admin form has never sent index_id, so every collection created
        // through it landed with a null index and fell back to database search
        // — no facets, no semantic retrieval, and no indication. Same default
        // as the org-side path: the most specific index this organization may
        // use. Explicit values are still honoured.
        $collection = Collection::create([
            'organization_id' => $request->organization_id,
            'user_owner_id' => auth()->id(),
            'name' => $request->name,
            'slug' => str($request->name)->slug()->toString(),
            'description' => $request->description,
            'language' => $request->input('language', 'en'),
            'is_active' => $request->input('is_active', true),
            'scheme_id' => $schemeId,
            'index_id' => $request->index_id
                ?? app(CollectionServiceInterface::class)->defaultIndexIdFor($request->organization_id),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Collection created successfully',
            'data' => ['collection' => $collection->load('organization:id,name')->loadCount('resources')],
        ], 201);
    }

    /**
     * Update a collection.
     */
    public function updateCollection(Request $request, string $id): JsonResponse
    {
        $collection = Collection::findOrFail($id);

        $request->validate([
            'name' => 'sometimes|string|max:255',
            'description' => 'sometimes|nullable|string|max:1000',
            'language' => 'sometimes|required|string|max:10',
            'is_active' => 'sometimes|boolean',
            'scheme_id' => 'sometimes|nullable|uuid|exists:collection_schemes,id',
            'index_id' => 'sometimes|nullable|uuid|exists:search_indexes,id',
        ]);

        $data = $request->only(['name', 'description', 'language', 'is_active', 'scheme_id', 'index_id']);
        if (isset($data['name'])) {
            $data['slug'] = $this->uniqueCollectionSlug($data['name'], $collection->organization_id, $collection->id);
        }

        $collection->update($data);

        return response()->json([
            'success' => true,
            'message' => 'Collection updated successfully',
            'data' => ['collection' => $collection->load('organization:id,name')->loadCount('resources')],
        ]);
    }

    /**
     * Duplicate a collection (structure only, no resources).
     */
    public function duplicateCollection(Request $request, string $id): JsonResponse
    {
        $source = Collection::findOrFail($id);

        $request->validate([
            'name' => 'required|string|max:255',
            'organization_id' => 'sometimes|uuid|exists:organizations,id',
        ]);

        $targetOrgId = $request->input('organization_id', $source->organization_id);

        $collection = Collection::create([
            'organization_id' => $targetOrgId,
            'user_owner_id' => auth()->id(),
            'name' => $request->name,
            'slug' => $this->uniqueCollectionSlug($request->name, $targetOrgId),
            'description' => $source->description,
            'language' => $source->language,
            'is_active' => $source->is_active,
            'scheme_id' => $source->scheme_id,
            'index_id' => $source->index_id,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Collection duplicated successfully',
            'data' => ['collection' => $collection->load('organization:id,name')->loadCount('resources')],
        ], 201);
    }

    /**
     * Delete a collection.
     */
    public function deleteCollection(string $id): JsonResponse
    {
        $collection = Collection::findOrFail($id);

        if ($collection->resources()->count() > 0) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete a collection that has resources',
            ], 422);
        }

        $collection->delete();

        return response()->json([
            'success' => true,
            'message' => 'Collection deleted successfully',
        ]);
    }

    // ==========================================================================
    // PLATFORM STATS & SETTINGS
    // ==========================================================================

    // ==========================================================================
    // HELPERS
    // ==========================================================================

    /**
     * Generate a slug that is unique within the given organization.
     * If the base slug is taken, appends -2, -3, … until free.
     *
     * @param  string  $name  Source name to slugify
     * @param  string  $organizationId  Organization scope
     * @param  int|null  $excludeId  Collection ID to exclude (for updates)
     */
    private function uniqueCollectionSlug(string $name, string $organizationId, ?int $excludeId = null): string
    {
        $base = str($name)->slug()->toString();
        $slug = $base;
        $i = 2;

        while (
            Collection::where('organization_id', $organizationId)
                ->where('slug', $slug)
                ->when($excludeId, fn ($q) => $q->where('id', '!=', $excludeId))
                ->exists()
        ) {
            $slug = "{$base}-{$i}";
            $i++;
        }

        return $slug;
    }

    // ==========================================================================
    // PLATFORM STATS & SETTINGS
    // ==========================================================================

    /**
     * Get platform-wide statistics.
     */
    public function platformStats(): JsonResponse
    {
        $stats = [
            'organizations' => [
                'total' => Organization::count(),
                'active' => Organization::where('is_active', true)->count(),
                'suspended' => Organization::where('is_active', false)->count(),
            ],
            'users' => [
                'total' => User::count(),
                'superadmins' => User::where('is_superadmin', true)->count(),
                'active' => User::where('is_active', true)->count(),
            ],
            'resources' => [
                'total' => \App\Models\Resource::count(),
            ],
            'storage' => [
                'total_files' => File::count(),
                'total_size' => File::sum('size'),
            ],
        ];

        return response()->json([
            'success' => true,
            'data' => ['stats' => $stats],
        ]);
    }

    /**
     * Get platform settings.
     */
    public function getSettings(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'settings' => [
                    'allow_registrations' => config('platform.allow_registrations', true),
                    'default_org_type' => config('platform.default_org_type', 'business'),
                    'max_orgs_per_user' => config('platform.max_orgs_per_user', 1),
                    'require_email_verification' => config('platform.require_email_verification', true),
                ],
            ],
        ]);
    }

    /**
     * Update platform settings.
     */
    public function updateSettings(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'allow_registrations' => 'sometimes|boolean',
            'default_org_type' => 'sometimes|in:individual,business,educational,government,non_profit',
            'max_orgs_per_user' => 'sometimes|integer|min:1|max:100',
            'require_email_verification' => 'sometimes|boolean',
        ]);

        foreach ($validated as $key => $value) {
            config(["platform.{$key}" => $value]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Settings updated successfully',
            'data' => ['settings' => $validated],
        ]);
    }
}
