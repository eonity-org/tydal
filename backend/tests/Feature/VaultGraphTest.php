<?php

namespace Tests\Feature;

use App\Enums\ResourceRelationOrigin;
use App\Enums\ResourceRelationType;
use App\Enums\ResourceState;
use App\Enums\VaultPurpose;
use App\Enums\VaultState;
use App\Models\Organization;
use App\Models\Resource;
use App\Models\ResourceRelation;
use App\Models\Vault;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Vault-level `graph` op (Epic 5.2) — the projected graph: node cards for
 * every projected resource, edges only where both endpoints are projected.
 */
class VaultGraphTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Workspace $ws;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organization::factory()->create(['slug' => 'acme']);
        $this->ws = Workspace::factory()->create(['organization_id' => $this->org->id]);
    }

    private function makeVault(array $overrides = []): Vault
    {
        $vault = Vault::factory()->purpose(VaultPurpose::OBSIDIAN)->published()->create(
            array_merge(['organization_id' => $this->org->id, 'slug' => 'notes'], $overrides)
        );

        DB::table('workspace_vault')->insert(['workspace_id' => $this->ws->id, 'vault_id' => $vault->id]);

        return $vault;
    }

    private function addResource(string $name, ?Workspace $ws = null): Resource
    {
        $resource = Resource::factory()->create([
            'organization_id' => $this->org->id,
            'name' => $name,
            'state' => ResourceState::LIVE->value,
        ]);

        DB::table('dam_resource_workspace')->insert([
            'resource_id' => $resource->id,
            'workspace_id' => ($ws ?? $this->ws)->id,
        ]);

        return $resource;
    }

    private function relate(Resource $a, Resource $b, float $weight = 1.0): void
    {
        ResourceRelation::create([
            'organization_id' => $this->org->id,
            'subject_resource_id' => $a->id,
            'object_resource_id' => $b->id,
            'type' => ResourceRelationType::RELATED,
            'origin' => ResourceRelationOrigin::MANUAL,
            'weight' => $weight,
        ]);
    }

    public function test_graph_returns_projected_nodes_and_edges(): void
    {
        $this->makeVault();
        $a = $this->addResource('Alpha Note');
        $b = $this->addResource('Beta Note');
        $c = $this->addResource('Gamma Note');
        $this->relate($a, $b, 0.9);
        $this->relate($b, $c, 0.5);

        $payload = $this->getJson('/v/acme/notes/graph')->assertStatus(200)->json();

        $this->assertSame('vault-graph', $payload['type']);
        $this->assertCount(3, $payload['nodes']);
        $this->assertCount(2, $payload['edges']);
        $this->assertFalse($payload['truncated']);

        // Edges reference nodes by the vault-scoped id (link hash) the cards
        // carry — never the internal resource UUID, which must not cross the
        // boundary. Both endpoints resolve to real nodes in the projection.
        $nodeIds = collect($payload['nodes'])->pluck('id');
        $edge = collect($payload['edges'])->firstWhere('weight', 0.9);
        $this->assertNotContains($a->id, $nodeIds, 'internal resource UUID leaked into a node id');
        $this->assertTrue($nodeIds->contains($edge['source']));
        $this->assertTrue($nodeIds->contains($edge['target']));

        // The 0.9 edge connects Alpha → Beta: its endpoints are their cards' ids.
        $idByName = collect($payload['nodes'])->pluck('id', 'name');
        $this->assertSame($idByName['Alpha Note'], $edge['source']);
        $this->assertSame($idByName['Beta Note'], $edge['target']);
        $this->assertSame('related', $edge['type']);
        $this->assertSame('manual', $edge['origin']);

        // Node cards carry the same identity fields listings do.
        $this->assertArrayHasKey('slug', $payload['nodes'][0]);
        $this->assertArrayHasKey('url', $payload['nodes'][0]);
    }

    public function test_edges_to_unprojected_resources_never_leak(): void
    {
        $this->makeVault();
        $inside = $this->addResource('Inside Note');

        // Same org, but a workspace NOT attached to the vault.
        $otherWs = Workspace::factory()->create(['organization_id' => $this->org->id]);
        $outside = $this->addResource('Outside Note', $otherWs);
        $this->relate($inside, $outside);

        $payload = $this->getJson('/v/acme/notes/graph')->assertStatus(200)->json();

        $this->assertCount(1, $payload['nodes']);
        $this->assertCount(0, $payload['edges']);
    }

    public function test_graph_serves_on_the_hash_form_and_respects_node_cap(): void
    {
        $vault = $this->makeVault();
        $a = $this->addResource('Alpha Note');
        $b = $this->addResource('Beta Note');
        $this->relate($a, $b);

        $payload = $this->getJson('/h/'.$vault->hash.'/graph?nodes=1')->assertStatus(200)->json();

        $this->assertCount(1, $payload['nodes']);
        $this->assertTrue($payload['truncated']);
        // The second endpoint fell outside the cap — its edge must go too.
        $this->assertCount(0, $payload['edges']);
    }

    public function test_unpublished_vault_graph_is_hidden_without_key(): void
    {
        $this->makeVault(['state' => VaultState::PRIVATE->value]);
        $this->addResource('Alpha Note');

        $this->getJson('/v/acme/notes/graph')->assertStatus(404);
    }

    public function test_meta_advertises_the_graph_operation(): void
    {
        $this->makeVault();

        $ops = $this->getJson('/v/acme/notes/meta')->assertStatus(200)->json('operations.vault');

        $this->assertContains('graph', $ops);
    }
}
