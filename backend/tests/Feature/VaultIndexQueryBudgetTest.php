<?php

namespace Tests\Feature;

use App\Enums\ResourceRelationOrigin;
use App\Enums\ResourceRelationType;
use App\Enums\ResourceState;
use App\Enums\VaultPurpose;
use App\Models\Organization;
use App\Models\Resource;
use App\Models\Vault;
use App\Models\Workspace;
use App\Services\ResourceGraphService;
use App\Services\VaultLinkService;
use App\Services\VaultOperationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Every vault listing endpoint must issue a bounded number of queries
 * regardless of how many resources it returns — card building batches link
 * resolution and eager-loads the snapshot relations, so the query count is
 * O(1) in the result size, not O(3N). Pins the N+1 fixes (index, DB-fallback
 * search, related, graph) against regression.
 */
class VaultIndexQueryBudgetTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Workspace $ws;

    private VaultOperationService $ops;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organization::factory()->create(['slug' => 'acme']);
        $this->ws = Workspace::factory()->create(['organization_id' => $this->org->id]);
        $this->ops = app(VaultOperationService::class);
    }

    private function makeVault(array $overrides = []): Vault
    {
        $vault = Vault::factory()->purpose(VaultPurpose::GALLERY)->published()->create(
            array_merge(['organization_id' => $this->org->id, 'slug' => 'my-vault'], $overrides)
        );
        DB::table('workspace_vault')->insert(['workspace_id' => $this->ws->id, 'vault_id' => $vault->id]);

        return $vault;
    }

    /** @return list<resource> */
    private function seedResources(int $n): array
    {
        $made = [];
        for ($i = 0; $i < $n; $i++) {
            $r = Resource::factory()->create([
                'organization_id' => $this->org->id,
                'name' => 'Resource '.str_pad((string) $i, 3, '0', STR_PAD_LEFT),
                'state' => ResourceState::LIVE->value,
            ]);
            DB::table('dam_resource_workspace')->insert(['resource_id' => $r->id, 'workspace_id' => $this->ws->id]);
            $made[] = $r;
        }

        return $made;
    }

    private function countQueries(callable $fn): int
    {
        $fn(); // warm lazy link minting first — measure steady-state reads
        DB::flushQueryLog();
        DB::enableQueryLog();
        $fn();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    public function test_vault_index_is_o1_in_resource_count(): void
    {
        $vault = $this->makeVault();

        $this->seedResources(3);
        $few = $this->countQueries(fn () => $this->ops->vaultIndex($vault, 1, 50));

        $this->seedResources(17); // 20 total
        $many = $this->countQueries(fn () => $this->ops->vaultIndex($vault, 1, 50));

        $this->assertSame($few, $many, "vaultIndex scaled with resources ({$few} → {$many}) — N+1 regression.");
        $this->assertLessThanOrEqual(10, $many, "vaultIndex exceeded its query budget ({$many}).");
    }

    public function test_db_fallback_search_is_o1_in_result_count(): void
    {
        // indexed_at NULL → the DB keyword path (resourceCard per hit)
        $vault = $this->makeVault(['indexed_at' => null]);

        $this->seedResources(3);
        $few = $this->countQueries(fn () => $this->ops->vaultSearch($vault, 'Resource', 1, 50, 'keyword'));

        $this->seedResources(17);
        $many = $this->countQueries(fn () => $this->ops->vaultSearch($vault, 'Resource', 1, 50, 'keyword'));

        $this->assertSame($few, $many, "DB-fallback search scaled with results ({$few} → {$many}) — N+1 regression.");
        $this->assertLessThanOrEqual(10, $many, "DB search exceeded its query budget ({$many}).");
    }

    public function test_graph_is_o1_in_node_count(): void
    {
        $vault = $this->makeVault();

        $this->seedResources(3);
        $few = $this->countQueries(fn () => $this->ops->vaultGraph($vault, 300));

        $this->seedResources(17);
        $many = $this->countQueries(fn () => $this->ops->vaultGraph($vault, 300));

        $this->assertSame($few, $many, "vaultGraph scaled with nodes ({$few} → {$many}) — N+1 regression.");
        $this->assertLessThanOrEqual(10, $many, "vaultGraph exceeded its query budget ({$many}).");
    }

    public function test_related_is_o1_in_neighbor_count(): void
    {
        $vault = $this->makeVault();
        $links = app(VaultLinkService::class);
        $graph = app(ResourceGraphService::class);

        [$hub] = $this->seedResources(1);

        // 2 neighbors
        foreach ($this->seedResources(2) as $n) {
            $graph->relate($hub, $n, ResourceRelationType::RELATED, weight: 0.5, origin: ResourceRelationOrigin::SEMANTIC);
        }
        $hubLink = $links->getOrCreateLink($vault, null, $hub->id, null);
        $few = $this->countQueries(fn () => $this->ops->resourceRelated($vault, $hubLink, 50));

        // grow to 12 neighbors
        foreach ($this->seedResources(10) as $n) {
            $graph->relate($hub, $n, ResourceRelationType::RELATED, weight: 0.5, origin: ResourceRelationOrigin::SEMANTIC);
        }
        $many = $this->countQueries(fn () => $this->ops->resourceRelated($vault, $hubLink, 50));

        $this->assertSame($few, $many, "resourceRelated scaled with neighbors ({$few} → {$many}) — N+1 regression.");
        $this->assertLessThanOrEqual(12, $many, "resourceRelated exceeded its query budget ({$many}).");
    }
}
