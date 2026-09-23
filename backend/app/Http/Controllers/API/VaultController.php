<?php

namespace App\Http\Controllers\API;

use App\Enums\VaultCapability;
use App\Enums\VaultPurpose;
use App\Enums\VaultState;
use App\Http\Controllers\Controller;
use App\Http\Requests\AskVaultRequest;
use App\Http\Requests\StoreVaultRequest;
use App\Http\Requests\UpdateVaultRequest;
use App\Jobs\RebuildVaultIndex;
use App\Models\CollectionScheme;
use App\Models\Organization;
use App\Models\Resource;
use App\Models\Vault;
use App\Models\VaultKey;
use App\Models\VaultLink;
use App\Models\VaultSchemaOverlay;
use App\Services\ClusterVaultService;
use App\Services\Interfaces\VaultServiceInterface;
use App\Services\VaultAskService;
use App\Services\VaultLinkService;
use App\Services\VaultSchemaResolver;
use App\Services\VaultSignatureService;
use App\Values\VaultPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class VaultController extends Controller
{
    public function __construct(
        protected VaultServiceInterface $vaultService,
        protected VaultLinkService $vaultLinkService
    ) {}

    /**
     * List active Vaults for authenticated users (workspace association UI).
     */
    public function publicIndex(): JsonResponse
    {
        // Vaults are org-scoped (spec §2): the association UI only ever
        // offers the caller's own organization's vaults, and only the
        // identity columns it needs.
        $vaults = Vault::query()->whereIn('state', [VaultState::PRIVATE->value, VaultState::PUBLIC->value])
            ->where('organization_id', currentOrganizationId())
            ->orderBy('name')
            ->get(['id', 'organization_id', 'name', 'slug', 'description', 'purpose', 'state']);

        return response()->json([
            'success' => true,
            'data' => ['vaults' => $vaults],
            'message' => 'Vaults retrieved successfully',
        ]);
    }

    /**
     * Move a vault between states — the org-scoped form. Spec §3 grants this
     * to org Admin (75)+ ("changes what the outside world can reach"); the
     * platform surface stays superadmin-only. Used by external products (e.g.
     * Full Frame's opening flow) with an org-scoped service token.
     */
    public function publish(Request $request, string $id): JsonResponse
    {
        $vault = $this->vaultService->getVaultById($id);
        if (! $vault || $vault->organization_id !== currentOrganizationId()) {
            return response()->json(['success' => false, 'message' => 'Vault not found'], 404);
        }

        if (! $request->user()->is_superadmin
            && ! in_array(currentOrganizationRole(), ['owner', 'admin'], true)) {
            return response()->json(['success' => false, 'message' => 'Admin role required'], 403);
        }

        $request->validate(['state' => ['required', Rule::enum(VaultState::class)]]);
        $vault->update(['state' => $request->string('state')->toString()]);

        return response()->json([
            'success' => true,
            'data' => ['vault' => $vault->fresh()],
            'message' => 'Vault state updated',
        ]);
    }

    /**
     * List all Vault configurations (platform admin).
     */
    public function index(Request $request): JsonResponse
    {
        $vaults = $this->vaultService->listVaults(
            search: (string) $request->input('search', ''),
            perPage: (int) $request->input('per_page', 20),
            page: (int) $request->input('page', 1),
        );

        return response()->json([
            'success' => true,
            'data' => ['vaults' => $vaults->items()],
            'meta' => [
                'pagination' => [
                    'current_page' => $vaults->currentPage(),
                    'per_page' => $vaults->perPage(),
                    'total' => $vaults->total(),
                    'has_more' => $vaults->hasMorePages(),
                ],
            ],
            'message' => 'Vaults retrieved successfully',
        ]);
    }

    /**
     * Create a new Vault configuration.
     */
    public function store(StoreVaultRequest $request): JsonResponse
    {
        $vault = $this->vaultService->createVault($request->validated());

        // A new vault has no projected index yet (indexed_at NULL → search runs
        // on the DB fallback: keyword-only, no facets, no semantic mode even
        // though /meta advertises it). Compose it now, like purpose changes do.
        RebuildVaultIndex::dispatch($vault->id);

        return response()->json([
            'success' => true,
            'data' => ['vault' => $vault],
            'message' => 'Vault created successfully',
        ], 201);
    }

    /**
     * Show a single Vault configuration.
     */
    public function show(string $id): JsonResponse
    {
        $vault = $this->vaultService->getVaultById($id);

        if (! $vault) {
            return response()->json(['success' => false, 'message' => 'Vault not found'], 404);
        }

        return response()->json([
            'success' => true,
            'data' => ['vault' => $vault],
            'message' => 'Vault retrieved successfully',
        ]);
    }

    /**
     * Update a Vault configuration. Purges existing links if the salt or the
     * purpose changes — both are hash-domain changes (VAULT_SYSTEM.md §1):
     * every previously minted link is invalidated and regenerated lazily.
     *
     * A capability change is deliberately *not* a purge: tuning
     * `exposure_policy` re-gates what the boundary answers, but the hash domain
     * is untouched, so links a consumer already holds stay valid. The two role
     * filters are the exception to the no-op case — they decide what the
     * projected index contains, so they trigger a rebuild without a purge.
     */
    public function update(UpdateVaultRequest $request, string $id): JsonResponse
    {
        $existing = $this->vaultService->getVaultById($id);
        if (! $existing) {
            return response()->json(['success' => false, 'message' => 'Vault not found'], 404);
        }

        $data = $request->validated();
        $saltChanged = isset($data['salt']) && $data['salt'] !== $existing->salt;
        $purposeChanged = isset($data['purpose']) && $data['purpose'] !== $existing->purpose->value;
        $projectionChanged = $this->projectionRolesChanged($existing, $data);

        $vault = $this->vaultService->updateVault($id, $data);

        if ($saltChanged || $purposeChanged) {
            $this->vaultLinkService->purgeLinksForVault($id);
        }

        if (($purposeChanged || $projectionChanged) && $vault) {
            // Purpose decides the slot vocabulary and the role filters decide
            // the contents — either way, recompose the projected index.
            $vault->forceFill(['indexed_at' => null])->saveQuietly();
            RebuildVaultIndex::dispatch($vault->id);
        }

        $purgeReason = match (true) {
            $saltChanged && $purposeChanged => ' (existing links purged due to salt and purpose change)',
            $saltChanged => ' (existing links purged due to salt change)',
            $purposeChanged => ' (existing links purged due to purpose change)',
            default => '',
        };

        return response()->json([
            'success' => true,
            'data' => ['vault' => $vault],
            'message' => 'Vault updated successfully'.$purgeReason,
        ]);
    }

    /**
     * The capability vocabulary and every purpose's preset — the matrix the
     * admin UI renders. Served from the enum and VaultPolicy so the frontend
     * never restates the defaults (they used to be duplicated as a hardcoded
     * VAULT_WRITE_METHODS table in vaultService.ts, free to drift).
     */
    public function capabilities(): JsonResponse
    {
        $capabilities = array_map(fn (VaultCapability $c) => [
            'key' => $c->value,
            'label' => $c->label(),
            'group' => $c->group(),
        ], VaultCapability::cases());

        $presets = [];
        foreach (VaultPurpose::cases() as $purpose) {
            $presets[$purpose->value] = [
                'label' => $purpose->label(),
                'values' => VaultPolicy::presetFor($purpose),
            ];
        }

        return response()->json([
            'success' => true,
            'data' => ['capabilities' => $capabilities, 'presets' => $presets],
            'message' => 'Vault capability matrix retrieved successfully',
        ]);
    }

    /**
     * Did this update change which file roles the vault projects? Compared on
     * *effective* values so that clearing an override back to its preset only
     * counts when the resulting roles actually differ.
     *
     * @param  array<string, mixed>  $data
     */
    private function projectionRolesChanged(Vault $existing, array $data): bool
    {
        if (! array_key_exists('exposure_policy', $data)) {
            return false;
        }

        $purpose = isset($data['purpose'])
            ? VaultPurpose::from($data['purpose'])
            : $existing->purpose;

        $before = $existing->policy();
        $after = VaultPolicy::for($purpose, $data['exposure_policy']);

        foreach ([VaultCapability::ADDRESS_ROLES, VaultCapability::CHUNK_ROLES] as $capability) {
            if ($before->value($capability) !== $after->value($capability)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Delete a Vault configuration (cascades to vault_links).
     */
    public function destroy(string $id): JsonResponse
    {
        $deleted = $this->vaultService->deleteVault($id);

        return response()->json([
            'success' => $deleted,
            'message' => $deleted ? 'Vault deleted successfully' : 'Vault not found',
        ], $deleted ? 200 : 404);
    }

    /**
     * Get or create all Vault links for a resource (lazy generation).
     * Called when the Vault tab is opened in the resource detail modal.
     *
     * Link hashes are the resource's public addresses — minting and listing
     * them is an org-member action (spec §2), not merely an authenticated one.
     * Unknown and foreign resources answer alike so the endpoint cannot be
     * used to probe which UUIDs exist.
     */
    public function resourceLinks(Request $request, string $resourceId): JsonResponse
    {
        $resource = Resource::select('id', 'organization_id')->find($resourceId);
        if (! $resource || ! $request->user()->canAccessOrganization($resource->organization_id)) {
            return response()->json(['success' => false, 'message' => 'Resource not found'], 404);
        }

        $links = $this->vaultLinkService->getOrCreateLinksForResource($resourceId);

        return response()->json([
            'success' => true,
            'data' => $links,
            'message' => 'Vault links retrieved successfully',
        ]);
    }

    // -------------------------------------------------------------------------
    // Vault keys — access to private vaults (VAULT_SYSTEM.md §7)
    // -------------------------------------------------------------------------

    /**
     * List a vault's keys (prefixes only — plaintext is never recoverable).
     */
    public function listKeys(string $id): JsonResponse
    {
        $vault = $this->vaultService->getVaultById($id);
        if (! $vault) {
            return response()->json(['success' => false, 'message' => 'Vault not found'], 404);
        }

        $keys = VaultKey::where('vault_id', $vault->id)
            ->orderByDesc('created_at')
            ->get(['id', 'name', 'key_prefix', 'abilities', 'last_used_at', 'revoked_at', 'created_at']);

        return response()->json([
            'success' => true,
            'data' => ['keys' => $keys],
            'message' => 'Vault keys retrieved successfully',
        ]);
    }

    /**
     * Mint a new key. The plaintext appears in this response only.
     */
    public function storeKey(Request $request, string $id): JsonResponse
    {
        $vault = $this->vaultService->getVaultById($id);
        if (! $vault) {
            return response()->json(['success' => false, 'message' => 'Vault not found'], 404);
        }

        // A key may carry `read` and/or the write methods this vault's purpose
        // exposes (VAULT_WRITE_METHODS.md §4). Anything outside that set — e.g.
        // a write ability on a delivery vault that exposes none — is rejected.
        $allowedAbilities = array_merge(
            ['read'],
            array_map(fn (string $m): string => "w:$m", $vault->purpose->writeMethods()),
        );

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'abilities' => ['sometimes', 'array', 'min:1'],
            'abilities.*' => ['string', Rule::in($allowedAbilities)],
        ]);

        $abilities = $validated['abilities'] ?? ['read'];

        [$key, $plaintext] = VaultKey::mint($vault, $validated['name'], $abilities);

        return response()->json([
            'success' => true,
            'data' => [
                'key' => [
                    'id' => $key->id,
                    'name' => $key->name,
                    'key_prefix' => $key->key_prefix,
                    'abilities' => $key->abilities,
                    'created_at' => $key->created_at,
                ],
                'plaintext' => $plaintext,
            ],
            'message' => 'Vault key created — store the plaintext now, it will not be shown again',
        ], 201);
    }

    /**
     * Mint a signed URL (Epic 5.4): a time-limited grant that opens this
     * vault (or exactly one of its links) without a vault key. Keyed by the
     * vault salt — rotating the salt revokes every outstanding grant.
     */
    public function signedUrl(Request $request, string $id, VaultSignatureService $signatures): JsonResponse
    {
        $vault = $this->vaultService->getVaultById($id);
        if (! $vault) {
            return response()->json(['success' => false, 'message' => 'Vault not found'], 404);
        }

        $data = $request->validate([
            'expires_in_hours' => ['required', 'integer', 'min:1', 'max:8760'],
            'link_hash' => ['sometimes', 'nullable', 'string'],
        ]);

        $linkHash = $data['link_hash'] ?? null;

        if ($linkHash !== null && ! VaultLink::where('vault_id', $vault->id)->where('hash', $linkHash)->exists()) {
            return response()->json(['success' => false, 'message' => 'Vault link not found'], 404);
        }

        $expiresAt = now()->addHours($data['expires_in_hours']);
        $grant = $signatures->sign($vault, $linkHash, $expiresAt);

        $root = rtrim($vault->base_url ?: config('app.url'), '/');
        $path = '/h/'.$vault->hash.($linkHash !== null ? '/'.$linkHash : '');

        return response()->json([
            'success' => true,
            'data' => [
                'url' => "{$root}{$path}?sig={$grant['sig']}&exp={$grant['exp']}",
                'sig' => $grant['sig'],
                'exp' => $grant['exp'],
                'expires_at' => $expiresAt->toIso8601String(),
                'scope' => $linkHash !== null ? 'link' : 'vault',
            ],
            'message' => 'Signed URL minted — it stops working at expiry, on a grant revocation, or on salt rotation',
        ], 201);
    }

    /**
     * Revoke every outstanding signed grant for this vault by bumping its
     * grant_epoch. Unlike a salt rotation, this touches no links — every
     * bookmarked /v, /h, and delivery URL keeps working; only the time-limited
     * grants die. Use salt rotation for the full address-domain reset.
     */
    public function revokeGrants(string $id): JsonResponse
    {
        $vault = $this->vaultService->getVaultById($id);
        if (! $vault) {
            return response()->json(['success' => false, 'message' => 'Vault not found'], 404);
        }

        // update() (not increment()) so the model `saved` hook fires and busts
        // the cached hash/slug resolutions — otherwise the verifier keeps
        // reading a stale grant_epoch and the revoke silently no-ops.
        $vault->update(['grant_epoch' => (int) $vault->grant_epoch + 1]);

        return response()->json([
            'success' => true,
            'data' => ['grant_epoch' => $vault->grant_epoch],
            'message' => 'All outstanding signed grants revoked — links are unaffected',
        ]);
    }

    /**
     * Rotate the vault salt — the full address-domain reset. Generates a fresh
     * secret (never human-picked) and purges every link, so all previously
     * shared /v, /h and delivery URLs AND every signed grant stop working. The
     * nuclear "un-share everything" option; for grants alone use revokeGrants.
     */
    public function rotateSalt(string $id): JsonResponse
    {
        $vault = $this->vaultService->getVaultById($id);
        if (! $vault) {
            return response()->json(['success' => false, 'message' => 'Vault not found'], 404);
        }

        $vault->update(['salt' => Str::random(Vault::SALT_LENGTH)]);
        $purged = $this->vaultLinkService->purgeLinksForVault($vault->id);

        return response()->json([
            'success' => true,
            'data' => ['links_purged' => $purged],
            'message' => 'Salt rotated — every link and signed grant for this vault was invalidated',
        ]);
    }

    /**
     * Revoke a key (kept for audit; verification rejects it immediately).
     */
    public function revokeKey(string $id, string $keyId): JsonResponse
    {
        $key = VaultKey::where('vault_id', $id)->whereKey($keyId)->first();

        if (! $key) {
            return response()->json(['success' => false, 'message' => 'Vault key not found'], 404);
        }

        $key->update(['revoked_at' => now()]);

        return response()->json(['success' => true, 'message' => 'Vault key revoked']);
    }

    // -------------------------------------------------------------------------
    // Schema overlays — per-vault semantic mapping (Epic 3.1)
    // -------------------------------------------------------------------------

    /**
     * Overlay rows plus the fully resolved presentation (presets ← scheme
     * vault_roles ← overlays) — what a consumer client will actually see.
     */
    public function listOverlays(string $id, VaultSchemaResolver $resolver): JsonResponse
    {
        $vault = $this->vaultService->getVaultById($id);
        if (! $vault) {
            return response()->json(['success' => false, 'message' => 'Vault not found'], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'overlays' => VaultSchemaOverlay::where('vault_id', $vault->id)->get(),
                'presentation' => $resolver->presentationFor($vault),
            ],
            'message' => 'Vault overlays retrieved successfully',
        ]);
    }

    /**
     * Upsert the overlay for one scheme. field_roles must reference fields
     * the scheme currently defines and slots from the role vocabulary.
     * An empty field_roles removes the overlay (back to scheme defaults).
     */
    public function putOverlay(Request $request, string $id, string $schemeId): JsonResponse
    {
        $vault = $this->vaultService->getVaultById($id);
        if (! $vault) {
            return response()->json(['success' => false, 'message' => 'Vault not found'], 404);
        }

        $scheme = CollectionScheme::find($schemeId);
        if (! $scheme) {
            return response()->json(['success' => false, 'message' => 'Scheme not found'], 404);
        }

        $request->validate(['field_roles' => 'present|array']);
        $fieldRoles = $request->input('field_roles', []);

        $knownFields = collect($scheme->fields ?? [])->pluck('name')->filter()->all();
        $errors = [];

        foreach ($fieldRoles as $fieldName => $purposeMap) {
            if (! in_array($fieldName, $knownFields, true)) {
                $errors[] = "Field \"{$fieldName}\" does not exist in scheme \"{$scheme->name}\"";

                continue;
            }
            if (! is_array($purposeMap)) {
                $errors[] = "Field \"{$fieldName}\": expected an object of purpose → slot";

                continue;
            }
            foreach ($purposeMap as $purpose => $slot) {
                if (! in_array($purpose, VaultSchemaResolver::mappablePurposes(), true)) {
                    $errors[] = "Field \"{$fieldName}\": unknown purpose \"{$purpose}\"";
                } elseif (! in_array($slot, VaultSchemaResolver::slotsFor($purpose), true)) {
                    $errors[] = "Field \"{$fieldName}\": unknown slot \"{$slot}\" for purpose \"{$purpose}\"";
                }
            }
        }

        if ($errors !== []) {
            return response()->json(['success' => false, 'message' => 'Invalid overlay', 'errors' => $errors], 422);
        }

        if ($fieldRoles === []) {
            VaultSchemaOverlay::where('vault_id', $vault->id)->where('scheme_id', $scheme->id)->delete();
        } else {
            VaultSchemaOverlay::updateOrCreate(
                ['vault_id' => $vault->id, 'scheme_id' => $scheme->id],
                ['field_roles' => $fieldRoles]
            );
        }

        // Recomposition (Epic 3.2): the semantic mapping changed — the
        // projected index is stale until the rebuild lands.
        $vault->forceFill(['indexed_at' => null])->saveQuietly();
        RebuildVaultIndex::dispatch($vault->id);

        return response()->json([
            'success' => true,
            'data' => ['presentation' => app(VaultSchemaResolver::class)->presentationFor($vault)],
            'message' => 'Vault overlay saved',
        ]);
    }

    /**
     * POST /vaults/{id}/ask — Epic 4.4: the vault ask head, authenticated
     * form. Retrieval walks the same tier-gated operation grammar as any
     * client.
     */
    public function ask(AskVaultRequest $request, string $id, VaultAskService $ask): JsonResponse
    {
        $vault = $this->vaultService->getVaultById($id);

        if (! $vault || ! $vault->isReachable()) {
            return response()->json(['success' => false, 'message' => 'Vault not found'], 404);
        }

        if (! $request->user()->canAccessOrganization($vault->organization_id)) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        try {
            $result = $ask->ask(
                $vault,
                $request->input('question'),
                $request->integer('k', 5),
            );

            return response()->json(['success' => true, 'data' => $result]);
        } catch (\Throwable $e) {
            Log::error("Vault ask failed for {$id}: ".$e->getMessage());

            return response()->json(['success' => false, 'message' => 'Ask service unavailable.'], 503);
        }
    }

    /**
     * POST /platform/vaults/clusters/rebuild — Epic 4.5: run the graph
     * clustering for one organization and materialize the clusters as
     * auto-named vaults (create / update by member overlap / retire stale).
     */
    public function rebuildClusters(Request $request, ClusterVaultService $clusterVaults): JsonResponse
    {
        $data = $request->validate([
            'organization_id' => 'required|exists:organizations,id',
            'min_size' => 'sometimes|integer|min:2',
        ]);

        $summary = $clusterVaults->rebuild(
            Organization::findOrFail($data['organization_id']),
            ownerId: $request->user()?->id,
            minSize: (int) ($data['min_size'] ?? 2),
        );

        return response()->json([
            'success' => true,
            'data' => $summary,
            'message' => sprintf(
                '%d cluster(s): %d created, %d updated, %d retired',
                $summary['clusters'],
                $summary['created'],
                $summary['updated'],
                $summary['deleted'],
            ),
        ]);
    }
}
