<?php

namespace App\Services;

use App\Enums\VaultCredential;
use App\Enums\VaultState;
use App\Models\File;
use App\Models\Resource;
use App\Models\Vault;
use App\Models\VaultKey;
use App\Models\VaultLink;
use App\Models\Workspace;
use Hashids\Hashids;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class VaultLinkService
{
    /**
     * Path segments the operation grammar owns (spec §5) — a generated
     * resource/file slug must never collide with them.
     */
    private const RESERVED_SLUGS = [
        'meta', 'tags', 'resources', 'search', 'embed', 'files', 'chunks',
        'links', 'related', 'download', 'renditions', 'info', 'graph', 'preview', 'ask',
    ];

    /**
     * Get or create all Vault links for a resource and its files.
     * Called lazily when the Vault tab is first opened.
     *
     * `delivery` vaults mint one link per (vault, workspace) pair — the
     * classic CDN behavior, untouched. Vault purposes mint ONE stable,
     * workspace-free address per resource/file (spec §4.2), and only for
     * files whose role the vault's exposure policy addresses (spec §6.1).
     *
     * Returns a structured array ready for the API response.
     */
    public function getOrCreateLinksForResource(string $resourceId): array
    {
        $resource = Resource::with(['workspaces', 'files'])->findOrFail($resourceId);

        $deliveryPairs = $this->buildDeliveryPairs($resource);
        $projectionVaults = $this->reachableProjectionVaults($resource);

        // Generate resource-level links
        $resourceLinks = [];
        foreach ($deliveryPairs as $pair) {
            $link = $this->getOrCreateLink($pair['vault'], $pair['workspace'], $resource->id, null);
            $resourceLinks[] = $this->formatLink($link, $pair['vault'], $pair['workspace']);
        }
        foreach ($projectionVaults as $vault) {
            $link = $this->getOrCreateLink($vault, null, $resource->id, null);
            $resourceLinks[] = $this->formatLink($link, $vault, null);
        }

        // Generate file-level links
        $filesData = [];
        foreach ($resource->files as $file) {
            $fileLinks = [];
            foreach ($deliveryPairs as $pair) {
                $link = $this->getOrCreateLink($pair['vault'], $pair['workspace'], $resource->id, $file->id);
                $fileLinks[] = $this->formatLink($link, $pair['vault'], $pair['workspace']);
            }
            foreach ($projectionVaults as $vault) {
                if (! in_array($file->role->value, $vault->addressRoles(), true)) {
                    continue; // role not exposed by this vault — unaddressed
                }
                $link = $this->getOrCreateLink($vault, null, $resource->id, $file->id);
                $fileLinks[] = $this->formatLink($link, $vault, null);
            }
            $filesData[] = [
                'id' => $file->id,
                'filename' => $file->filename,
                'mime_type' => $file->mime_type,
                'size' => $file->size,
                'links' => $fileLinks,
            ];
        }

        return [
            'resource' => [
                'id' => $resource->id,
                'name' => $resource->name,
                'links' => $resourceLinks,
            ],
            'files' => $filesData,
        ];
    }

    /**
     * Get or create a single VaultLink record, generating its hash after insert.
     * A null workspace means a vault-purpose link (workspace-free key).
     */
    public function getOrCreateLink(Vault $vault, ?Workspace $workspace, string $resourceId, ?string $fileId): VaultLink
    {
        $linkKey = VaultLink::computeLinkKey($vault->id, $workspace !== null ? (string) $workspace->id : null, $resourceId, $fileId);

        return DB::transaction(function () use ($vault, $workspace, $resourceId, $fileId, $linkKey) {
            $link = VaultLink::where('link_key', $linkKey)->first();

            if (! $link) {
                $link = VaultLink::create([
                    'vault_id' => $vault->id,
                    'workspace_id' => $workspace?->id,
                    'resource_id' => $resourceId,
                    'file_id' => $fileId,
                    'link_key' => $linkKey,
                    // Vault-purpose links get a human slug — the /v/ address form (§4.3)
                    'slug' => $workspace === null
                        ? $this->generateLinkSlug($vault, $resourceId, $fileId)
                        : null,
                    'expires_at' => $vault->hash_ttl_hours
                        ? now()->addHours($vault->hash_ttl_hours)
                        : null,
                ]);

                // Hash depends on the auto-increment PK — generated after insert
                $hashids = new Hashids($vault->salt, 8);
                $link->update(['hash' => $hashids->encode($link->id)]);
            }

            return $link;
        });
    }

    /**
     * Batch resolver for resource-level vault links — one SELECT for all
     * existing links, minting only the missing few. Returns [resourceId =>
     * VaultLink], each with its vault relation set. Kills the per-card
     * getOrCreateLink N+1 on listings (vaultIndex, vaultGraph): a warm vault
     * (links already minted) resolves a whole page in a single query.
     *
     * @param  iterable<string>  $resourceIds
     * @return array<string, VaultLink>
     */
    public function getOrCreateResourceLinks(Vault $vault, iterable $resourceIds): array
    {
        $ids = array_values(array_unique(array_filter(is_array($resourceIds) ? $resourceIds : iterator_to_array($resourceIds))));

        if ($ids === []) {
            return [];
        }

        $existing = VaultLink::where('vault_id', $vault->id)
            ->whereIn('resource_id', $ids)
            ->whereNull('file_id')
            ->get()
            ->keyBy('resource_id');

        $map = [];
        foreach ($ids as $resourceId) {
            $link = $existing->get($resourceId) ?? $this->getOrCreateLink($vault, null, $resourceId, null);
            $link->setRelation('vault', $vault);
            $map[$resourceId] = $link;
        }

        return $map;
    }

    /**
     * Slug for a vault-purpose link — never a grammar word. Scoping follows
     * the hierarchical /v/ grammar (spec §4.3): resource slugs are unique per
     * VAULT; file slugs are unique per RESOURCE (siblings only), so
     * album-one/cover and album-two/cover coexist and a file may carry its
     * resource's name (/doc/doc).
     */
    private function generateLinkSlug(Vault $vault, string $resourceId, ?string $fileId): string
    {
        if ($fileId !== null) {
            $filename = File::whereKey($fileId)->value('filename') ?? $fileId;
            $base = Str::slug(pathinfo($filename, PATHINFO_FILENAME));
        } else {
            $name = Resource::whereKey($resourceId)->value('name') ?? $resourceId;
            $base = Str::slug($name);
        }

        if ($base === '') {
            $base = 'item';
        }

        $taken = fn (string $slug) => $fileId !== null
            ? VaultLink::where('vault_id', $vault->id)
                ->where('resource_id', $resourceId)
                ->whereNotNull('file_id')
                ->where('slug', $slug)
                ->exists()
            : VaultLink::where('vault_id', $vault->id)
                ->whereNull('file_id')
                ->where('slug', $slug)
                ->exists();

        $slug = $base;
        $n = 2;
        while (in_array($slug, self::RESERVED_SLUGS, true) || $taken($slug)) {
            $slug = "{$base}-{$n}";
            $n++;
        }

        return $slug;
    }

    /**
     * Resolve a flat legacy hash to its VaultLink (delivery links on
     * /vault/{hash}), validating expiry, Vault status, and IP allowlist.
     */
    public function resolveHash(string $hash, string $clientIp, ?string $vaultKey = null, ?array $grant = null): ?VaultLink
    {
        $link = VaultLink::where('hash', $hash)->with(['vault', 'workspace'])->first();

        if (! $link) {
            return null;
        }

        // Legacy CDN semantics — the hash alone is the whole credential —
        // survive only for `delivery` vaults, which predate the publish gate.
        // Projection purposes answer to the publish gate here exactly as they
        // do on /h/ and /v/ (buildUrl only ever advertises them there); without
        // this, an unpublished gallery's works would be one flat URL away from
        // anyone who saw a link hash, key or no key.
        $requirePublished = ! $link->vault->isDelivery();

        if (! $this->passesVaultPolicy($link->vault, $clientIp, $requirePublished, $vaultKey, $grant, $link)) {
            return null;
        }

        $this->markCredential($link->vault, $vaultKey, $grant, $link);

        return $this->validateLink($link) ? $link : null;
    }

    /**
     * Plain lookup of a link by its vault-scoped hash within a known vault —
     * no policy gate (the vault is already resolved and authorized by the
     * caller). Used server-side to turn a card's vault-scoped id back into its
     * link, e.g. by the ask pipeline expanding the graph.
     */
    public function findLinkInVault(Vault $vault, string $linkHash): ?VaultLink
    {
        $link = VaultLink::where('vault_id', $vault->id)->where('hash', $linkHash)->first();

        if ($link) {
            $link->setRelation('vault', $vault);
        }

        return $link;
    }

    /**
     * Vault-first resolution for /h/{vaultHash}/{linkHash} (spec §4.1):
     * the vault — and therefore its whole policy — is resolved before any
     * link query, and the link lookup is scoped to that vault, so link
     * hashes only need per-vault uniqueness.
     */
    public function resolveVaultLink(string $vaultHash, string $linkHash, string $clientIp, ?string $vaultKey = null, ?array $grant = null): ?VaultLink
    {
        $vault = Vault::findByHashCached($vaultHash);

        if (! $vault) {
            return null;
        }

        // Link-scoped grants need the target link as verification context, so
        // resolve it before the policy gate — same 404 either way.
        $link = VaultLink::where('vault_id', $vault->id)->where('hash', $linkHash)->first();

        if (! $link) {
            return null;
        }

        $link->setRelation('vault', $vault);

        if (! $this->passesVaultPolicy($vault, $clientIp, requirePublished: true, vaultKey: $vaultKey, grant: $grant, link: $link)) {
            return null;
        }

        $this->markCredential($vault, $vaultKey, $grant, $link);

        return $this->validateLink($link) ? $link : null;
    }

    /**
     * Resolve the human namespace root /v/{orgSlug}/{vaultSlug} (spec §4):
     * same policy gate as the hash form — humans and machines see the same
     * boundary, only the address form differs.
     */
    public function resolveVaultBySlugs(string $orgSlug, string $vaultSlug, string $clientIp, ?string $vaultKey = null, ?array $grant = null): ?Vault
    {
        $vault = Vault::findBySlugsCached($orgSlug, $vaultSlug);

        if (! $vault || ! $this->passesVaultPolicy($vault, $clientIp, requirePublished: true, vaultKey: $vaultKey, grant: $grant)) {
            return null;
        }

        return $this->markCredential($vault, $vaultKey, $grant);
    }

    /**
     * Resolve a vault-level address by its opaque hash (the /h/{vaultHash}
     * root — vault index and vault operations, keyless-by-hash for MCP).
     */
    public function resolveVaultByHash(string $vaultHash, string $clientIp, ?string $vaultKey = null, ?array $grant = null): ?Vault
    {
        $vault = Vault::findByHashCached($vaultHash);

        if (! $vault || ! $this->passesVaultPolicy($vault, $clientIp, requirePublished: true, vaultKey: $vaultKey, grant: $grant)) {
            return null;
        }

        return $this->markCredential($vault, $vaultKey, $grant);
    }

    /**
     * Authorize a write at the boundary (VAULT_WRITE_METHODS.md §5). This is a
     * dedicated gate, NOT the read policy: writes must reach a *private* vault
     * (that is the whole point of `open`), so publication is never required —
     * only that the vault is reachable, the method is one the purpose exposes,
     * and the presented key carries the matching `w:{method}` ability.
     *
     * Returns a status-tagged result the controller maps to HTTP: 200 with the
     * vault + authorizing key, 403 when the method/key is refused, or 404 when
     * the vault is absent, disabled, or IP-blocked (indistinguishable, so a
     * probe learns nothing). The vault is resolved by the caller (by hash or by
     * slugs) so both address forms share this one gate.
     *
     * @return array{status: int, vault?: Vault, key?: VaultKey, error?: string}
     */
    public function authorizeWrite(?Vault $vault, string $method, string $clientIp, ?string $vaultKey): array
    {
        if (! $vault || ! $vault->isReachable()) {
            return ['status' => 404];
        }

        if (! $vault->allowsWriteMethod($method)) {
            return ['status' => 403, 'error' => 'This vault does not expose that write method'];
        }

        if ($vaultKey === null) {
            return ['status' => 403, 'error' => 'A write key is required'];
        }

        $key = VaultKey::resolveForWrite($vault, $vaultKey, $method);
        if (! $key) {
            return ['status' => 403, 'error' => 'Key not authorized for this method'];
        }

        // IP allowlist — same silent 404 as the read path, so the allowlist is
        // not itself an oracle.
        $allowedIps = $vault->allowed_ips ?? [];
        if (! empty($allowedIps) && ! in_array($clientIp, $allowedIps, true)) {
            return ['status' => 404];
        }

        return ['status' => 200, 'vault' => $vault, 'key' => $key];
    }

    /**
     * Non-destructive write-auth probe (VAULT_WRITE_METHODS.md §5): report which
     * of the vault's exposed write methods the presented key may perform,
     * without performing any. Same gate and same 404 opacity as
     * {@see authorizeWrite()} (absent/disabled/IP-blocked are indistinguishable),
     * so a config UI can confirm a write key is bound correctly before relying
     * on it — but the probe reveals nothing a real write would not.
     *
     * @return array{status: int, methods?: list<string>, error?: string}
     */
    public function writeCapabilities(?Vault $vault, string $clientIp, ?string $vaultKey): array
    {
        if (! $vault || ! $vault->isReachable()) {
            return ['status' => 404];
        }

        $allowedIps = $vault->allowed_ips ?? [];
        if (! empty($allowedIps) && ! in_array($clientIp, $allowedIps, true)) {
            return ['status' => 404];
        }

        if ($vaultKey === null) {
            return ['status' => 403, 'error' => 'A write key is required'];
        }

        $methods = VaultKey::authorizedWriteMethods($vault, $vaultKey);
        if ($methods === []) {
            return ['status' => 403, 'error' => 'Key not authorized to write on this vault'];
        }

        return ['status' => 200, 'methods' => $methods];
    }

    /**
     * Resolve {resourceSlug} inside an already-resolved vault to its
     * resource-level link (validated — implicit revocation applies).
     */
    public function resolveResourceLinkBySlug(Vault $vault, string $resourceSlug): ?VaultLink
    {
        $link = VaultLink::where('vault_id', $vault->id)
            ->where('slug', $resourceSlug)
            ->whereNull('file_id')
            ->first();

        if (! $link) {
            return null;
        }

        $link->setRelation('vault', $vault);

        return $this->validateLink($link) ? $link : null;
    }

    /**
     * Resolve {resourceSlug}/{fileSlug} to the file-level link.
     */
    public function resolveFileLinkBySlug(Vault $vault, string $resourceSlug, string $fileSlug): ?VaultLink
    {
        $resourceLink = $this->resolveResourceLinkBySlug($vault, $resourceSlug);

        if (! $resourceLink) {
            return null;
        }

        $link = VaultLink::where('vault_id', $vault->id)
            ->where('resource_id', $resourceLink->resource_id)
            ->where('slug', $fileSlug)
            ->whereNotNull('file_id')
            ->first();

        if (! $link) {
            return null;
        }

        $link->setRelation('vault', $vault);

        return $this->validateLink($link) ? $link : null;
    }

    /**
     * Build the full public URL for a link. Delivery links keep the flat
     * /vault/{hash} form; vault purposes live on /h/{vaultHash}/{linkHash}.
     */
    public function buildUrl(VaultLink $link): string
    {
        $vault = $link->vault;

        $path = $vault->isDelivery()
            ? '/vault/'.$link->hash
            : '/h/'.$vault->hash.'/'.$link->hash;

        if ($vault->base_url) {
            return rtrim($vault->base_url, '/').$path;
        }

        return $vault->isDelivery()
            ? route('vault.serve', ['hash' => $link->hash])
            : route('h.serve', ['vaultHash' => $vault->hash, 'linkHash' => $link->hash]);
    }

    /**
     * Delete all vault_links for a given Vault (salt or purpose change).
     */
    public function purgeLinksForVault(string $vaultId): int
    {
        return VaultLink::where('vault_id', $vaultId)->delete();
    }

    // -------------------------------------------------------------------------

    /**
     * Shared vault policy gate: active, IP allowlist, and three-tier auth
     * (spec §7 + Epic 5.4): published vaults are open; private vaults require
     * a valid VaultKey — or a signed grant (`?sig=&exp=`), the time-limited
     * publish form. Active + IP checks are never bypassed by any credential.
     */
    /**
     * How this request authenticated, recorded on the resolved instance so the
     * capability levels can gate on it (VaultAccessLevel).
     *
     * Set unconditionally on every resolution — never conditionally — because
     * `findBy*Cached` may hand back a shared instance, and a stale KEY left on
     * it would grant a later keyless request more than it is owed.
     */
    private function markCredential(Vault $vault, ?string $vaultKey, ?array $grant, ?VaultLink $link = null): Vault
    {
        $credential = match (true) {
            $vaultKey !== null && VaultKey::verify($vault, $vaultKey) => VaultCredential::KEY,
            $this->grantAuthorizes($vault, $grant, $link) => VaultCredential::GRANT,
            default => VaultCredential::OPEN,
        };

        return $vault->withCredential($credential);
    }

    private function passesVaultPolicy(Vault $vault, string $clientIp, bool $requirePublished, ?string $vaultKey = null, ?array $grant = null, ?VaultLink $link = null): bool
    {
        if (! $vault->isReachable()) {
            return false;
        }

        if ($requirePublished && ! $vault->isOpen()) {
            $keyOk = $vaultKey !== null && VaultKey::verify($vault, $vaultKey);

            if (! $keyOk && ! $this->grantAuthorizes($vault, $grant, $link)) {
                return false;
            }
        }

        $allowedIps = $vault->allowed_ips ?? [];
        if (! empty($allowedIps) && ! in_array($clientIp, $allowedIps)) {
            return false;
        }

        return true;
    }

    /**
     * Signed-grant acceptance, widest scope first: a vault-wide grant opens
     * every address; a link-scoped grant opens exactly that address — and a
     * RESOURCE share also covers the resource's own file addresses, so the
     * shared thing renders whole (its images/binaries resolve too).
     */
    private function grantAuthorizes(Vault $vault, ?array $grant, ?VaultLink $link): bool
    {
        if ($grant === null || ! isset($grant['sig'], $grant['exp'])) {
            return false;
        }

        $signatures = app(VaultSignatureService::class);

        if ($signatures->verify($vault, null, $grant['sig'], (int) $grant['exp'])) {
            return true;
        }

        if ($link === null) {
            return false;
        }

        if ($signatures->verify($vault, $link->hash, $grant['sig'], (int) $grant['exp'])) {
            return true;
        }

        if ($link->file_id !== null) {
            $resourceLinkHash = VaultLink::where('vault_id', $vault->id)
                ->where('resource_id', $link->resource_id)
                ->whereNull('file_id')
                ->value('hash');

            return $resourceLinkHash !== null
                && $signatures->verify($vault, $resourceLinkHash, $grant['sig'], (int) $grant['exp']);
        }

        return false;
    }

    /**
     * Membership validity — revocation stays implicit (spec §4.2): a link
     * dies silently when its resource leaves the projection.
     */
    private function validateLink(VaultLink $link): bool
    {
        if ($link->isExpired()) {
            return false;
        }

        // Vault-purpose link (no workspace in the key): the resource must be
        // in ANY workspace linked to the vault, or reachable through the
        // org's default workspace for has_public_workspace vaults.
        if ($link->workspace_id === null) {
            // Org pinning (spec §2) gates both routes in: whatever the pivot
            // tables say, this boundary only ever serves its own org. Mirrors
            // VaultOperationService::vaultResourceQuery().
            $sameOrg = DB::table('resources')
                ->where('id', $link->resource_id)
                ->where('organization_id', $link->vault->organization_id)
                ->exists();

            if (! $sameOrg) {
                return false;
            }

            if ($link->vault->has_public_workspace) {
                return true;
            }

            return DB::table('dam_resource_workspace')
                ->join('workspace_vault', 'workspace_vault.workspace_id', '=', 'dam_resource_workspace.workspace_id')
                ->where('workspace_vault.vault_id', $link->vault_id)
                ->where('dam_resource_workspace.resource_id', $link->resource_id)
                ->exists();
        }

        // Delivery link — classic per-workspace checks. Org pinning first: the
        // public-workspace shortcut below skips the pivot entirely, so without
        // it a resource could be served through another org's public delivery
        // vault on the strength of its own default workspace being "default".
        if (! DB::table('resources')
            ->where('id', $link->resource_id)
            ->where('organization_id', $link->vault->organization_id)
            ->exists()) {
            return false;
        }

        $isPublicWorkspaceLink = $link->vault->has_public_workspace && $link->workspace->is_default;

        if (! $isPublicWorkspaceLink) {
            // Workspace must still be associated with this Vault
            $wsLinkedToVault = DB::table('workspace_vault')
                ->where('workspace_id', $link->workspace_id)
                ->where('vault_id', $link->vault_id)
                ->exists();

            if (! $wsLinkedToVault) {
                return false;
            }

            // Resource must still belong to this workspace
            $resourceInWorkspace = DB::table('dam_resource_workspace')
                ->where('resource_id', $link->resource_id)
                ->where('workspace_id', $link->workspace_id)
                ->exists();

            if (! $resourceInWorkspace) {
                return false;
            }
        }

        return true;
    }

    /**
     * Deduplicated (vault, workspace) pairs for the resource's DELIVERY
     * vaults — the classic CDN minting surface, untouched.
     */
    private function buildDeliveryPairs(Resource $resource): array
    {
        $pairs = [];
        $seen = [];

        // Pairs from the resource's associated non-default workspaces
        foreach ($resource->workspaces as $workspace) {
            $vaults = $workspace->vaults()->whereIn('state', [VaultState::PRIVATE->value, VaultState::PUBLIC->value])->where('purpose', 'delivery')->get();
            foreach ($vaults as $vault) {
                $key = $vault->id.':'.$workspace->id;
                if (! isset($seen[$key])) {
                    $pairs[] = ['vault' => $vault, 'workspace' => $workspace];
                    $seen[$key] = true;
                }
            }
        }

        // Pairs from delivery vaults with has_public_workspace = true → always
        // inject default workspace. Org-scoped: "the whole default workspace"
        // means this resource's OWN org, never every org's public vault.
        $publicVaults = Vault::where('has_public_workspace', true)
            ->whereIn('state', [VaultState::PRIVATE->value, VaultState::PUBLIC->value])
            ->where('purpose', 'delivery')
            ->where('organization_id', $resource->organization_id)
            ->get();

        if ($publicVaults->isNotEmpty()) {
            $defaultWorkspace = Workspace::where('organization_id', $resource->organization_id)
                ->where('is_default', true)
                ->first();

            if ($defaultWorkspace) {
                foreach ($publicVaults as $vault) {
                    $key = $vault->id.':'.$defaultWorkspace->id;
                    if (! isset($seen[$key])) {
                        $pairs[] = ['vault' => $vault, 'workspace' => $defaultWorkspace];
                        $seen[$key] = true;
                    }
                }
            }
        }

        return $pairs;
    }

    /**
     * Deduplicated vault-purpose vaults that project this resource — via its
     * workspaces, or via has_public_workspace within the same org. The
     * workspace deliberately drops out: one vault, one address (spec §4.2).
     * Public: the per-vault index upkeep (Epic 3.2) reuses it.
     *
     * @return list<Vault>
     */
    public function reachableProjectionVaults(Resource $resource): array
    {
        $vaults = [];
        $seen = [];

        foreach ($resource->workspaces as $workspace) {
            $wsVaults = $workspace->vaults()->whereIn('state', [VaultState::PRIVATE->value, VaultState::PUBLIC->value])->where('purpose', '!=', 'delivery')->get();
            foreach ($wsVaults as $vault) {
                if (! isset($seen[$vault->id])) {
                    $vaults[] = $vault;
                    $seen[$vault->id] = true;
                }
            }
        }

        $publicVaults = Vault::where('has_public_workspace', true)
            ->whereIn('state', [VaultState::PRIVATE->value, VaultState::PUBLIC->value])
            ->where('purpose', '!=', 'delivery')
            ->where('organization_id', $resource->organization_id)
            ->get();

        foreach ($publicVaults as $vault) {
            if (! isset($seen[$vault->id])) {
                $vaults[] = $vault;
                $seen[$vault->id] = true;
            }
        }

        return $vaults;
    }

    private function formatLink(VaultLink $link, Vault $vault, ?Workspace $workspace): array
    {
        $link->setRelation('vault', $vault);
        $url = $this->buildUrl($link);

        return [
            'id' => $link->id,
            'vault_id' => $vault->id,
            'vault_name' => $vault->name,
            'vault_purpose' => $vault->purpose->value,
            'workspace_id' => $workspace?->id,
            'workspace_name' => $workspace?->name,
            'hash' => $link->hash,
            'url' => $url,
            'info_url' => $url.'/info',
            'download_url' => $vault->is_downloadable ? $url.'/download' : null,
            'expires_at' => $link->expires_at?->toIso8601String(),
            'is_expired' => $link->isExpired(),
        ];
    }
}
