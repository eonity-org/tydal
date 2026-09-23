<?php

namespace Tests\Feature;

use App\Enums\ResourceRelationOrigin;
use App\Enums\ResourceRelationType;
use App\Enums\ResourceState;
use App\Enums\VaultPurpose;
use App\Models\Collection;
use App\Models\Organization;
use App\Models\Resource;
use App\Models\ResourceRelation;
use App\Models\SearchIndex;
use App\Models\SemanticTag;
use App\Models\User;
use App\Models\Vault;
use App\Models\Workspace;
use App\Services\ElasticsearchService;
use App\Services\ResourceGraphService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

/**
 * Epic 4.3 — the resource graph: manual edge curation (API), traversal,
 * auto-relation rebuilds (tags / semantic), clustering, and the graph-backed
 * vault /related.
 */
class ResourceGraphTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Organization $org;

    private string $token;

    private ResourceGraphService $graph;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->org = Organization::factory()->create(['slug' => 'acme']);
        $this->org->users()->attach($this->user->id, ['role' => 'admin']);
        $this->user->update(['last_organization_id' => $this->org->id]);
        $this->token = $this->user->createToken('t')->plainTextToken;

        $this->graph = app(ResourceGraphService::class);
    }

    private function makeResource(string $name, ?string $orgId = null): Resource
    {
        return Resource::factory()->create([
            'organization_id' => $orgId ?? $this->org->id,
            'user_owner_id' => $this->user->id,
            'name' => $name,
            'state' => ResourceState::LIVE->value,
        ]);
    }

    private function tag(Resource $resource, string ...$labels): void
    {
        foreach ($labels as $label) {
            $tag = SemanticTag::where('organization_id', $this->org->id)->where('label', $label)->first()
                ?? SemanticTag::factory()->create([
                    'organization_id' => $this->org->id,
                    'label' => $label,
                    'is_active' => true,
                ]);
            $resource->semanticTags()->syncWithoutDetaching([$tag->id]);
        }
    }

    // =========================================================================
    // Edge semantics
    // =========================================================================

    public function test_related_edges_are_symmetric_and_stored_once(): void
    {
        $a = $this->makeResource('A');
        $b = $this->makeResource('B');

        $this->graph->relate($a, $b, ResourceRelationType::RELATED, weight: 0.5);
        $this->graph->relate($b, $a, ResourceRelationType::RELATED, weight: 0.9);

        $this->assertSame(1, ResourceRelation::count(), 'A↔B and B↔A are the same edge');
        $this->assertSame(0.9, ResourceRelation::sole()->weight, 'second assertion updated the edge');
    }

    public function test_derived_from_keeps_direction(): void
    {
        $thumb = $this->makeResource('Thumbnail');
        $master = $this->makeResource('Master');

        $edge = $this->graph->relate($thumb, $master, ResourceRelationType::DERIVED_FROM);

        $this->assertSame($thumb->id, $edge->subject_resource_id);
        $this->assertSame($master->id, $edge->object_resource_id);
    }

    public function test_self_loops_and_cross_org_edges_are_rejected(): void
    {
        $a = $this->makeResource('A');
        $otherOrg = Organization::factory()->create();
        $foreign = Resource::factory()->create([
            'organization_id' => $otherOrg->id,
            'user_owner_id' => $this->user->id,
            'state' => ResourceState::LIVE->value,
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->graph->relate($a, $a, ResourceRelationType::RELATED);

        $this->expectException(\InvalidArgumentException::class);
        $this->graph->relate($a, $foreign, ResourceRelationType::RELATED);
    }

    // =========================================================================
    // API
    // =========================================================================

    public function test_manual_relation_lifecycle_over_the_api(): void
    {
        $a = $this->makeResource('A');
        $b = $this->makeResource('B');

        $created = $this->withToken($this->token)
            ->postJson("/api/v1/resources/{$a->id}/relations", [
                'object_id' => $b->id,
                'type' => 'related',
                'weight' => 0.8,
            ])
            ->assertStatus(201)
            ->assertJsonPath('type', 'related')
            ->assertJsonPath('origin', 'manual')
            ->json();

        // Visible from BOTH ends, direction marked
        $this->withToken($this->token)
            ->getJson("/api/v1/resources/{$b->id}/relations")
            ->assertStatus(200)
            ->assertJsonCount(1, 'relations')
            ->assertJsonPath('relations.0.direction', 'both')
            ->assertJsonPath('relations.0.resource.name', 'A');

        $this->withToken($this->token)
            ->deleteJson("/api/v1/resources/{$a->id}/relations/{$created['id']}")
            ->assertStatus(200);

        $this->assertSame(0, ResourceRelation::count());
    }

    public function test_api_rejects_self_relation(): void
    {
        $a = $this->makeResource('A');

        $this->withToken($this->token)
            ->postJson("/api/v1/resources/{$a->id}/relations", [
                'object_id' => $a->id,
                'type' => 'related',
            ])
            ->assertStatus(422);
    }

    public function test_graph_endpoint_walks_multiple_hops(): void
    {
        $a = $this->makeResource('A');
        $b = $this->makeResource('B');
        $c = $this->makeResource('C');
        $this->graph->relate($a, $b, ResourceRelationType::RELATED);
        $this->graph->relate($b, $c, ResourceRelationType::RELATED);

        $oneHop = $this->withToken($this->token)
            ->getJson("/api/v1/resources/{$a->id}/graph?depth=1")
            ->assertStatus(200)
            ->json();

        $this->assertCount(2, $oneHop['nodes']); // A + B
        $this->assertCount(1, $oneHop['edges']);

        $twoHops = $this->withToken($this->token)
            ->getJson("/api/v1/resources/{$a->id}/graph?depth=2")
            ->assertStatus(200)
            ->json();

        $this->assertCount(3, $twoHops['nodes']); // A + B + C
        $this->assertSame(2, collect($twoHops['nodes'])->firstWhere('name', 'C')['distance']);
    }

    public function test_graph_endpoint_filters_by_type(): void
    {
        $a = $this->makeResource('A');
        $b = $this->makeResource('B');
        $c = $this->makeResource('C');
        $this->graph->relate($a, $b, ResourceRelationType::RELATED);
        $this->graph->relate($a, $c, ResourceRelationType::DERIVED_FROM);

        $related = $this->withToken($this->token)
            ->getJson("/api/v1/resources/{$a->id}/graph?types=derived_from")
            ->assertStatus(200)
            ->json();

        $this->assertCount(2, $related['nodes']); // A + C only
        $this->assertSame('derived_from', $related['edges'][0]['type']);
    }

    // =========================================================================
    // Auto-relation rebuilds
    // =========================================================================

    public function test_tag_rebuild_relates_resources_sharing_enough_tags(): void
    {
        $a = $this->makeResource('A');
        $b = $this->makeResource('B');
        $c = $this->makeResource('C');
        $this->tag($a, 'paris', 'architecture', 'travel');
        $this->tag($b, 'paris', 'architecture');
        $this->tag($c, 'paris'); // only one shared tag — below threshold

        $count = $this->graph->rebuildTagRelations($this->org->id, minShared: 2);

        $this->assertSame(1, $count);
        $edge = ResourceRelation::sole();
        $this->assertSame(ResourceRelationOrigin::TAGS, $edge->origin);
        // Jaccard: 2 shared / 3 union
        $this->assertEqualsWithDelta(0.6667, $edge->weight, 0.001);
    }

    public function test_tag_rebuild_replaces_stale_auto_edges_but_never_manual_ones(): void
    {
        $a = $this->makeResource('A');
        $b = $this->makeResource('B');
        $c = $this->makeResource('C');

        // Stale auto edge (no longer justified by tags) + a manual edge
        $this->graph->relate($a, $c, ResourceRelationType::RELATED, origin: ResourceRelationOrigin::TAGS);
        $this->graph->relate($b, $c, ResourceRelationType::RELATED, origin: ResourceRelationOrigin::MANUAL);

        $this->graph->rebuildTagRelations($this->org->id);

        $this->assertSame(1, ResourceRelation::count());
        $this->assertSame(ResourceRelationOrigin::MANUAL, ResourceRelation::sole()->origin, 'curator edge survived');
    }

    public function test_semantic_rebuild_relates_knn_neighbors_above_threshold(): void
    {
        $searchIndex = SearchIndex::create([
            'index_name' => 'tydal_test',
            'display_name' => 'Test',
            'is_active' => true,
        ]);
        $collection = Collection::factory()->create([
            'organization_id' => $this->org->id,
            'user_owner_id' => $this->user->id,
            'index_id' => $searchIndex->id,
        ]);

        $a = $this->makeResource('A');
        $b = $this->makeResource('B');
        $c = $this->makeResource('C');
        foreach ([$a, $b, $c] as $r) {
            $r->update(['collection_id' => $collection->id, 'embedding' => [0.1, 0.2]]);
        }

        $mock = Mockery::mock(ElasticsearchService::class);
        $mock->shouldReceive('indexResource')->zeroOrMoreTimes();
        $mock->shouldReceive('indexResourceIntoVaults')->zeroOrMoreTimes();
        $mock->shouldReceive('knnSearchResources')->andReturnUsing(function ($indices, $vector, $k, $orgId, $exclude) use ($a, $b, $c) {
            // a↔b are near (0.9); c is far (0.4 — below threshold)
            return match ($exclude[0]) {
                $a->id => [['resource_id' => $b->id, 'score' => 0.9], ['resource_id' => $c->id, 'score' => 0.4]],
                $b->id => [['resource_id' => $a->id, 'score' => 0.9]],
                default => [],
            };
        });
        $this->instance(ElasticsearchService::class, $mock);

        $count = $this->graph->rebuildSemanticRelations($this->org->id, k: 5, minScore: 0.75);

        $this->assertSame(1, $count);
        $edge = ResourceRelation::sole();
        $this->assertSame(ResourceRelationOrigin::SEMANTIC, $edge->origin);
        $this->assertSame(0.9, $edge->weight);
    }

    // =========================================================================
    // Clustering
    // =========================================================================

    public function test_clusters_are_connected_components_with_dominant_tags(): void
    {
        $a = $this->makeResource('A');
        $b = $this->makeResource('B');
        $c = $this->makeResource('C');
        $d = $this->makeResource('D');
        $lone = $this->makeResource('Lone');

        $this->tag($a, 'paris');
        $this->tag($b, 'paris');

        $this->graph->relate($a, $b, ResourceRelationType::RELATED);
        $this->graph->relate($c, $d, ResourceRelationType::RELATED);

        $clusters = $this->graph->clusters($this->org->id);

        $this->assertCount(2, $clusters);
        $this->assertSame(2, $clusters[0]['size']);
        $parisCluster = collect($clusters)->firstWhere('top_tags', ['paris']);
        $this->assertNotNull($parisCluster, 'cluster carries its dominant tags');

        // Bridging edge merges the components
        $this->graph->relate($b, $c, ResourceRelationType::RELATED);
        $merged = $this->graph->clusters($this->org->id);
        $this->assertCount(1, $merged);
        $this->assertSame(4, $merged[0]['size']);
    }

    // =========================================================================
    // Vault /related rides the graph
    // =========================================================================

    public function test_vault_related_uses_graph_edges_and_respects_projection(): void
    {
        $ws = Workspace::factory()->create(['organization_id' => $this->org->id]);
        $vault = Vault::factory()->purpose(VaultPurpose::GALLERY)->published()->create([
            'organization_id' => $this->org->id,
            'slug' => 'expo',
        ]);
        DB::table('workspace_vault')->insert(['workspace_id' => $ws->id, 'vault_id' => $vault->id]);

        $a = $this->makeResource('Alpha');
        $inVault = $this->makeResource('Beta');
        $outOfVault = $this->makeResource('Gamma');
        DB::table('dam_resource_workspace')->insert([
            ['resource_id' => $a->id, 'workspace_id' => $ws->id],
            ['resource_id' => $inVault->id, 'workspace_id' => $ws->id],
        ]);

        $this->graph->relate($a, $inVault, ResourceRelationType::RELATED, weight: 0.9, origin: ResourceRelationOrigin::SEMANTIC);
        $this->graph->relate($a, $outOfVault, ResourceRelationType::RELATED, weight: 0.99);

        $this->getJson('/v/acme/expo')->assertStatus(200); // mint slugs

        $payload = $this->getJson('/v/acme/expo/alpha/related')
            ->assertStatus(200)
            ->assertJsonPath('source', 'graph')
            ->json();

        $names = collect($payload['resources'])->pluck('name')->all();
        $this->assertSame(['Beta'], $names, 'out-of-vault neighbor never leaks, even with a heavier edge');
        $this->assertSame('semantic', $payload['resources'][0]['relation']['origin']);
    }

    public function test_vault_related_falls_back_to_shared_tags_without_edges(): void
    {
        $ws = Workspace::factory()->create(['organization_id' => $this->org->id]);
        $vault = Vault::factory()->purpose(VaultPurpose::GALLERY)->published()->create([
            'organization_id' => $this->org->id,
            'slug' => 'expo',
        ]);
        DB::table('workspace_vault')->insert(['workspace_id' => $ws->id, 'vault_id' => $vault->id]);

        $a = $this->makeResource('Alpha');
        $b = $this->makeResource('Beta');
        DB::table('dam_resource_workspace')->insert([
            ['resource_id' => $a->id, 'workspace_id' => $ws->id],
            ['resource_id' => $b->id, 'workspace_id' => $ws->id],
        ]);
        $this->tag($a, 'winter');
        $this->tag($b, 'winter');

        $this->getJson('/v/acme/expo')->assertStatus(200);

        $this->getJson('/v/acme/expo/alpha/related')
            ->assertStatus(200)
            ->assertJsonPath('source', 'tags')
            ->assertJsonPath('resources.0.name', 'Beta');
    }
}
