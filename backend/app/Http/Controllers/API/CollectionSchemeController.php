<?php

namespace App\Http\Controllers\API;

use App\Enums\Visibility;
use App\Http\Controllers\Controller;
use App\Models\CollectionScheme;
use App\Models\Organization;
use App\Support\SchemaContract;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CollectionSchemeController extends Controller
{
    /**
     * List all collection schemes.
     */
    public function index(Request $request): JsonResponse
    {
        // Platform administrators see the whole catalogue — they curate it.
        // Everyone else sees what their organization is offered: the global
        // schemes plus any restricted to them.
        //
        // `?for_organization=1` forces the organization's own menu even for a
        // platform administrator. The organization settings page asks for it,
        // because that screen answers "what can THIS organization use", not
        // "what can I see" — otherwise an admin is offered another customer's
        // scheme while standing in someone else's organization.
        $organizationScoped = $request->boolean('for_organization')
            || ! auth()->user()?->isSuperAdmin();

        $schemes = CollectionScheme::query()
            ->when($request->has('system'), function ($query) use ($request) {
                return $query->where('is_system', $request->boolean('system'));
            })
            ->when($organizationScoped, fn ($query) => $query->visibleTo(currentOrganizationId()))
            ->with('organizations:id,name')
            // Non-zero means the field contract is locked: changing it would
            // leave the Elasticsearch mapping disagreeing with live documents.
            ->withCount('collections')
            ->get();

        return response()->json([
            'success' => true,
            'data' => ['schemes' => $schemes],
            'message' => 'Collection schemes retrieved successfully',
        ]);
    }

    /**
     * Get a specific collection scheme.
     */
    public function show(string $id): JsonResponse
    {
        $scheme = CollectionScheme::findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => ['scheme' => $scheme],
            'message' => 'Collection scheme retrieved successfully',
        ]);
    }

    /**
     * Store a new collection scheme.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:collection_schemes,name',
            'display_name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'fields' => 'required|array',
            // Field names are the DB field contract (matches schema:validate) and
            // flow into raw SQL in the catalogue's DB-facet path — constrain the
            // charset at the boundary, never accept SQL metacharacters.
            'fields.*.name' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]*$/'],
            'fields.*.type' => 'required|string',
            'accepted_mimetypes' => 'nullable|array',
            'is_system' => 'boolean',
        ]);

        $scheme = CollectionScheme::create($validated);

        return response()->json([
            'success' => true,
            'data' => ['scheme' => $scheme],
            'message' => 'Collection scheme created successfully',
        ], 201);
    }

    /**
     * Update a collection scheme.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $scheme = CollectionScheme::findOrFail($id);

        $validated = $request->validate(array_merge([
            'name' => 'sometimes|string|max:255|regex:/^[a-z][a-z0-9_]*$/|unique:collection_schemes,name,'.$id,
            'display_name' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'fields' => 'sometimes|array|min:1',
            'accepted_mimetypes' => 'nullable|array',
            'is_system' => 'sometimes|boolean',
            'visibility' => ['sometimes', Rule::enum(Visibility::class)],
            'organization_ids' => 'sometimes|array',
            'organization_ids.*' => 'uuid|exists:organizations,id',
        ], SchemaContract::fieldRules()));

        // `name` is the machine identifier, not the label: the platform looks
        // system schemes up by it (the 'multimedia' default, for one). Renaming
        // a system scheme would quietly break those lookups, so only
        // display_name moves there. Custom schemes rename freely.
        if (isset($validated['name']) && $scheme->is_system && $validated['name'] !== $scheme->name) {
            return response()->json([
                'success' => false,
                'message' => 'A system scheme keeps its identifier — change its display name instead.',
            ], 422);
        }

        if (isset($validated['fields']) && ($problems = SchemaContract::problems($validated['fields'])) !== []) {
            return response()->json([
                'success' => false,
                'message' => $problems[0],
                'errors' => ['fields' => $problems],
            ], 422);
        }

        // `fields` is the field contract: it drives the dynamic form, the
        // Elasticsearch mapping, the facet list, validation and mimetype
        // gating, all at once. Changing it under collections that already hold
        // resources leaves the mapping disagreeing with the documents, and only
        // `search:setup-indices --recreate` plus a full reindex puts that
        // right. So a scheme in use is closed to field edits — clone it
        // instead, which is what schemeClone() is for. Renaming and
        // re-describing stay open, since neither touches the contract.
        if (isset($validated['fields']) && $scheme->collections()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'This scheme is in use by '.$scheme->collections()->count().' collection(s), so its fields are locked. Clone it to make a variant.',
            ], 422);
        }

        $scheme->update(collect($validated)->except('organization_ids')->all());

        if ($request->has('organization_ids')) {
            $scheme->organizations()->sync($validated['organization_ids'] ?? []);
        }

        return response()->json([
            'success' => true,
            'data' => ['scheme' => $scheme->fresh()->load('organizations:id,name')],
            'message' => 'Collection scheme updated successfully',
        ]);
    }

    /**
     * Copy a scheme so one organization can vary it.
     *
     * The real workflow behind this: "I want multimedia, plus two fields, for
     * this customer." Editing the shared scheme would drag every other
     * organization's mapping along with it; a clone starts with no collections,
     * so its fields stay editable and nothing needs reindexing.
     *
     * The copy is restricted to the named organization by default — which is
     * what makes an org-specific field contract possible at all.
     */
    public function clone(Request $request, string $id): JsonResponse
    {
        $source = CollectionScheme::findOrFail($id);

        $validated = $request->validate([
            'organization_id' => 'required|uuid|exists:organizations,id',
            'name' => 'nullable|string|max:255|unique:collection_schemes,name',
            'display_name' => 'nullable|string|max:255',
        ]);

        $organization = Organization::findOrFail($validated['organization_id']);
        $name = $validated['name'] ?? Str::slug($organization->slug.'-'.$source->name, '_');

        if (CollectionScheme::where('name', $name)->exists()) {
            return response()->json([
                'success' => false,
                'message' => "A scheme named '{$name}' already exists. Give the clone a different name.",
            ], 422);
        }

        $clone = CollectionScheme::create([
            'name' => $name,
            'display_name' => $validated['display_name'] ?? $source->display_name.' ('.$organization->name.')',
            'description' => $source->description,
            'fields' => $source->fields,
            'accepted_mimetypes' => $source->accepted_mimetypes,
            'processing_config' => $source->processing_config,
            // Never a system scheme: those are the platform's own, and a copy
            // belongs to whoever asked for it.
            'is_system' => false,
            'visibility' => Visibility::RESTRICTED,
        ]);

        $clone->organizations()->attach($organization->id);

        return response()->json([
            'success' => true,
            'data' => ['scheme' => $clone->load('organizations:id,name')],
            'message' => "Cloned as '{$clone->display_name}', editable until a collection uses it.",
        ], 201);
    }

    /**
     * Delete a collection scheme.
     */
    public function destroy(string $id): JsonResponse
    {
        $scheme = CollectionScheme::findOrFail($id);

        if ($scheme->is_system) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete system schemes',
            ], 403);
        }

        $scheme->delete();

        return response()->json([
            'success' => true,
            'message' => 'Collection scheme deleted successfully',
        ]);
    }
}
