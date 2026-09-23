<?php

namespace App\Services;

use App\Enums\VaultPurpose;
use App\Enums\VaultState;
use App\Jobs\RebuildVaultIndex;
use App\Models\Organization;
use App\Models\User;
use App\Models\Vault;
use App\Models\Workspace;
use App\Services\LLM\Contracts\LlmServiceInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Epic 4.5 — materialize discovered structure into Vaults: one vault per
 * graph cluster (ResourceGraphService::clusters), projected through a
 * dedicated SYSTEM workspace holding the members.
 *
 * Identity across rebuilds: clusters have no inherent id, so new clusters
 * are matched to existing cluster vaults by member-set overlap (Jaccard ≥
 * threshold, greedy best-first). A matched vault keeps its slug and hash —
 * the addresses stay immutable (spec §4.3) — while membership and the
 * LLM-refreshed human name update. Unmatched old vaults (and their system
 * workspaces) are retired; curator vaults are untouched by construction
 * (the rebuild only ever sees `generated_from = 'clusters'`).
 *
 * Naming by consumer (arch §3.3): machine slug is computed; the human name
 * is an LLM call over the cluster's dominant tags + sample resource names,
 * degrading to a tag-derived title when the LLM is unavailable.
 */
class ClusterVaultService
{
    public const GENERATOR = 'clusters';

    public function __construct(private readonly ResourceGraphService $graph) {}

    /**
     * Run the clustering and upsert one vault per cluster.
     *
     * @return array{clusters: int, created: int, updated: int, deleted: int, vaults: list<array{id: string, name: string, slug: string, size: int}>}
     */
    public function rebuild(Organization $org, ?string $ownerId = null, int $minSize = 2, float $matchThreshold = 0.5): array
    {
        $ownerId ??= $this->resolveOwnerId($org);

        $clusters = array_values(array_filter(
            $this->graph->clusters($org->id),
            fn (array $c) => $c['size'] >= $minSize,
        ));

        $existing = Vault::query()
            ->where('organization_id', $org->id)
            ->where('generated_from', self::GENERATOR)
            ->get();

        $existingMembers = $existing->mapWithKeys(
            fn (Vault $v) => [$v->id => $this->memberIds($v)],
        );

        $assignment = $this->matchClusters($clusters, $existingMembers->all(), $matchThreshold);

        $summary = ['clusters' => count($clusters), 'created' => 0, 'updated' => 0, 'deleted' => 0, 'vaults' => []];
        $keptVaultIds = [];

        foreach ($clusters as $i => $cluster) {
            $name = $this->nameCluster($cluster, $i + 1);
            $vaultId = $assignment[$i] ?? null;

            if ($vaultId !== null) {
                $vault = $existing->firstWhere('id', $vaultId);
                $vault->update(['name' => $name]); // slug + hash stay — addresses are immutable
                $summary['updated']++;
            } else {
                $vault = Vault::create([
                    'organization_id' => $org->id,
                    'name' => $name,
                    'slug' => $this->uniqueSlug($org->id, $name),
                    'description' => 'Auto-created from graph cluster · tags: '.implode(', ', $cluster['top_tags']),
                    'purpose' => VaultPurpose::MIXED,
                    'generated_from' => self::GENERATOR,
                    'state' => VaultState::PRIVATE->value,
                    'salt' => Str::random(24),
                ]);
                $summary['created']++;
            }

            $this->syncMembers($vault, $cluster['resource_ids'], $ownerId);

            $vault->forceFill(['indexed_at' => null])->save();
            RebuildVaultIndex::dispatch($vault->id);

            $keptVaultIds[] = $vault->id;
            $summary['vaults'][] = ['id' => $vault->id, 'name' => $vault->name, 'slug' => $vault->slug, 'size' => $cluster['size']];
        }

        foreach ($existing as $vault) {
            if (! in_array($vault->id, $keptVaultIds, true)) {
                $this->retire($vault);
                $summary['deleted']++;
            }
        }

        return $summary;
    }

    /**
     * Greedy best-first assignment: every (cluster, vault) pair scored by
     * Jaccard overlap of member sets, matched in descending order, one use
     * each, floor at $threshold.
     *
     * @param  list<array{resource_ids: list<string>}>  $clusters
     * @param  array<string, list<string>>  $existingMembers  vault id → member ids
     * @return array<int, string> cluster index → vault id
     */
    private function matchClusters(array $clusters, array $existingMembers, float $threshold): array
    {
        $pairs = [];
        foreach ($clusters as $i => $cluster) {
            $new = $cluster['resource_ids'];
            foreach ($existingMembers as $vaultId => $old) {
                $shared = count(array_intersect($new, $old));
                if ($shared === 0) {
                    continue;
                }

                $jaccard = $shared / count(array_unique(array_merge($new, $old)));
                if ($jaccard >= $threshold) {
                    $pairs[] = ['cluster' => $i, 'vault' => $vaultId, 'score' => $jaccard];
                }
            }
        }

        usort($pairs, fn ($a, $b) => $b['score'] <=> $a['score']);

        $assignment = [];
        $usedVaults = [];
        foreach ($pairs as $pair) {
            if (isset($assignment[$pair['cluster']]) || isset($usedVaults[$pair['vault']])) {
                continue;
            }
            $assignment[$pair['cluster']] = $pair['vault'];
            $usedVaults[$pair['vault']] = true;
        }

        return $assignment;
    }

    /**
     * The cluster vault's projection vehicle: its dedicated system workspace
     * (is_system, purpose 'cluster'), created and attached on first use,
     * members synced to exactly the cluster.
     *
     * @param  list<string>  $memberIds
     */
    private function syncMembers(Vault $vault, array $memberIds, string $ownerId): void
    {
        $workspace = $this->systemWorkspacesOf($vault)->first();

        if ($workspace === null) {
            $workspace = Workspace::create([
                'organization_id' => $vault->organization_id,
                'user_owner_id' => $ownerId,
                'name' => 'Cluster · '.$vault->slug,
                'slug' => 'cluster-'.$vault->slug,
                'description' => 'System workspace projecting an auto-created cluster vault.',
                'state' => VaultState::PRIVATE->value,
                'is_system' => true,
                'purpose' => 'cluster',
            ]);
            $vault->workspaces()->attach($workspace->id);
        }

        $workspace->resources()->sync($memberIds);
    }

    /**
     * Retire a stale cluster vault: the vault (its ES index and links drop
     * with it) plus its system workspace — and ONLY a system 'cluster'
     * workspace; anything a human attached survives.
     */
    private function retire(Vault $vault): void
    {
        $workspaces = $this->systemWorkspacesOf($vault);

        $vault->delete();

        foreach ($workspaces as $workspace) {
            $workspace->resources()->detach();
            $workspace->delete();
        }
    }

    /**
     * The vault's system 'cluster' workspaces — typed query so callers get
     * real Workspace models.
     *
     * @return Collection<int, Workspace>
     */
    private function systemWorkspacesOf(Vault $vault): Collection
    {
        return Workspace::query()
            ->whereIn('id', DB::table('workspace_vault')->where('vault_id', $vault->id)->pluck('workspace_id'))
            ->where('is_system', true)
            ->where('purpose', 'cluster')
            ->get();
    }

    /**
     * Human name for a cluster — LLM over dominant tags + sample names,
     * tag-derived title as the degradation path.
     *
     * @param  array{top_tags: list<string>, names: list<string>, size: int}  $cluster
     */
    public function nameCluster(array $cluster, int $ordinal): string
    {
        $fallback = $cluster['top_tags'] === []
            ? "Cluster {$ordinal}"
            : Str::title(implode(' & ', array_slice($cluster['top_tags'], 0, 2)));

        try {
            $name = app(LlmServiceInterface::class)->chat([
                ['role' => 'system', 'content' => 'You name collections of digital resources. Reply with the name only — 2 to 5 words, no quotes, no punctuation at the end, in the dominant language of the tags.'],
                ['role' => 'user', 'content' => sprintf(
                    "Name this collection.\nDominant tags: %s\nSample items: %s",
                    implode(', ', $cluster['top_tags']),
                    implode(' · ', array_slice($cluster['names'], 0, 5)),
                )],
            ]);

            $name = trim(Str::of($name)->replace(["\n", "\r", '"', '“', '”'], ' ')->squish());

            return $name === '' ? $fallback : Str::limit($name, 60, '');
        } catch (\Throwable $e) {
            Log::warning('Cluster naming LLM call failed: '.$e->getMessage());

            return $fallback;
        }
    }

    /** @return list<string> */
    private function memberIds(Vault $vault): array
    {
        $wsIds = DB::table('workspace_vault')->where('vault_id', $vault->id)->pluck('workspace_id');

        return DB::table('dam_resource_workspace')
            ->whereIn('workspace_id', $wsIds)
            ->distinct()
            ->pluck('resource_id')
            ->all();
    }

    private function uniqueSlug(string $orgId, string $name): string
    {
        $base = Str::slug($name) ?: 'cluster';
        $slug = $base;
        $n = 2;

        while (Vault::where('organization_id', $orgId)->where('slug', $slug)->exists()) {
            $slug = "{$base}-{$n}";
            $n++;
        }

        return $slug;
    }

    /**
     * Owner for the system workspaces when the caller has no user context
     * (artisan): any org member, falling back to any user at all.
     */
    private function resolveOwnerId(Organization $org): string
    {
        $id = $org->users()->value('users.id') ?? User::query()->value('id');

        return $id !== null
            ? (string) $id
            : throw new \RuntimeException('No user available to own cluster workspaces.');
    }
}
