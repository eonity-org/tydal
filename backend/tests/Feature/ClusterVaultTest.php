<?php

namespace Tests\Feature;

use App\Enums\ResourceRelationType;
use App\Enums\ResourceState;
use App\Enums\VaultPurpose;
use App\Enums\VaultState;
use App\Jobs\RebuildVaultIndex;
use App\Models\Organization;
use App\Models\Resource;
use App\Models\ResourceRelation;
use App\Models\User;
use App\Models\Vault;
use App\Models\Workspace;
use App\Services\ClusterVaultService;
use App\Services\ElasticsearchService;
use App\Services\LLM\Contracts\LlmServiceInterface;
use App\Services\ResourceGraphService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

/**
 * Epic 4.5 — clusters materialize as vaults: one purpose=mixed,
 * generated_from='clusters' vault per graph cluster, projected through a
 * dedicated system workspace; identity (slug/hash) stable across rebuilds
 * via member-overlap matching; stale cluster vaults retired; curator vaults
 * never touched.
 */
class ClusterVaultTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Organization $org;

    private ResourceGraphService $graph;

    private ClusterVaultService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake([RebuildVaultIndex::class]);

        $mock = Mockery::mock(ElasticsearchService::class);
        $mock->shouldReceive('indexResource')->zeroOrMoreTimes();
        $mock->shouldReceive('indexResourceIntoVaults')->zeroOrMoreTimes();
        $mock->shouldReceive('deleteResource')->zeroOrMoreTimes();
        $mock->shouldReceive('removeResourceFromVaultIndexes')->zeroOrMoreTimes();
        $mock->shouldReceive('deleteIndex')->zeroOrMoreTimes();
        $this->instance(ElasticsearchService::class, $mock);

        $this->user = User::factory()->create();
        $this->org = Organization::factory()->create(['slug' => 'acme']);
        $this->org->users()->attach($this->user->id, ['role' => 'admin']);

        $this->graph = app(ResourceGraphService::class);
        $this->service = app(ClusterVaultService::class);
    }

    private function mockLlm(?string $name): void
    {
        $llm = Mockery::mock(LlmServiceInterface::class);
        $llm->shouldReceive('getModel')->zeroOrMoreTimes()->andReturn('test-model');

        if ($name === null) {
            $llm->shouldReceive('chat')->andThrow(new \RuntimeException('LLM down'));
        } else {
            $llm->shouldReceive('chat')->andReturn($name);
        }

        $this->app->instance(LlmServiceInterface::class, $llm);
    }

    private function makeResource(string $name): Resource
    {
        return Resource::factory()->create([
            'organization_id' => $this->org->id,
            'user_owner_id' => $this->user->id,
            'name' => $name,
            'state' => ResourceState::LIVE->value,
        ]);
    }

    /** @return list<resource> a related cluster of $n resources */
    private function makeCluster(int $n, string $prefix): array
    {
        $resources = [];
        for ($i = 0; $i < $n; $i++) {
            $resources[] = $this->makeResource("{$prefix} {$i}");
        }
        for ($i = 1; $i < $n; $i++) {
            $this->graph->relate($resources[0], $resources[$i], ResourceRelationType::RELATED);
        }

        return $resources;
    }

    // =========================================================================
    // Materialization
    // =========================================================================

    public function test_each_cluster_becomes_a_mixed_generated_vault_with_system_workspace(): void
    {
        $this->mockLlm('Paris Tourism & Architecture');
        $paris = $this->makeCluster(3, 'Paris');
        $this->makeCluster(2, 'Alps');

        $summary = $this->service->rebuild($this->org);

        $this->assertSame(2, $summary['clusters']);
        $this->assertSame(2, $summary['created']);

        $vault = Vault::where('generated_from', 'clusters')
            ->where('name', 'Paris Tourism & Architecture')
            ->first();

        $this->assertNotNull($vault);
        $this->assertSame(VaultPurpose::MIXED, $vault->purpose);
        $this->assertSame(VaultState::PRIVATE, $vault->state, 'auto vaults are born private');
        $this->assertSame('paris-tourism-architecture', $vault->slug);

        $workspace = $vault->workspaces()->first();
        $this->assertTrue($workspace->is_system);
        $this->assertSame('cluster', $workspace->purpose);
        $this->assertEqualsCanonicalizing(
            collect($paris)->pluck('id')->all(),
            $workspace->resources()->pluck('resources.id')->all(),
        );

        Queue::assertPushed(RebuildVaultIndex::class, 2);
    }

    public function test_rebuild_matches_by_member_overlap_and_keeps_slug_and_hash(): void
    {
        $this->mockLlm('First Name');
        $cluster = $this->makeCluster(4, 'Doc');
        $this->service->rebuild($this->org);

        $vault = Vault::where('generated_from', 'clusters')->sole();
        [$slug, $hash] = [$vault->slug, $vault->hash];

        // Membership drifts (one more member), the LLM now says something else
        $extra = $this->makeResource('Doc extra');
        $this->graph->relate($cluster[0], $extra, ResourceRelationType::RELATED);
        $this->mockLlm('Renamed Collection');

        $summary = $this->service->rebuild($this->org);

        $this->assertSame(1, $summary['updated']);
        $this->assertSame(0, $summary['created']);

        $vault->refresh();
        $this->assertSame('Renamed Collection', $vault->name, 'human name refreshes');
        $this->assertSame($slug, $vault->slug, 'machine slug is immutable');
        $this->assertSame($hash, $vault->hash, 'hash survives the rebuild');
        $this->assertSame(5, $vault->workspaces()->first()->resources()->count());
    }

    public function test_stale_cluster_vaults_are_retired_but_curator_vaults_survive(): void
    {
        $this->mockLlm('Something');
        $cluster = $this->makeCluster(2, 'Doc');

        $curatorVault = Vault::factory()->create(['organization_id' => $this->org->id, 'slug' => 'hand-made']);

        $this->service->rebuild($this->org);
        $this->assertSame(1, Vault::where('generated_from', 'clusters')->count());

        // The cluster dissolves
        ResourceRelation::query()->delete();

        $summary = $this->service->rebuild($this->org);

        $this->assertSame(1, $summary['deleted']);
        $this->assertSame(0, Vault::where('generated_from', 'clusters')->count());
        $this->assertSame(0, Workspace::where('purpose', 'cluster')->count(), 'system workspace retired with its vault');
        $this->assertNotNull(Vault::find($curatorVault->id), 'curator vault untouched');
    }

    public function test_min_size_filters_small_clusters(): void
    {
        $this->mockLlm('Big Cluster');
        $this->makeCluster(2, 'Small');
        $this->makeCluster(4, 'Big');

        $summary = $this->service->rebuild($this->org, minSize: 3);

        $this->assertSame(1, $summary['clusters']);
        $this->assertSame(1, Vault::where('generated_from', 'clusters')->count());
    }

    // =========================================================================
    // Naming
    // =========================================================================

    public function test_llm_failure_degrades_to_tag_derived_name(): void
    {
        $this->mockLlm(null); // throws

        $name = $this->service->nameCluster(
            ['top_tags' => ['paris', 'tourism', 'architecture'], 'names' => ['A'], 'size' => 3],
            1,
        );

        $this->assertSame('Paris & Tourism', $name);
    }

    public function test_tagless_cluster_falls_back_to_ordinal(): void
    {
        $this->mockLlm(null);

        $this->assertSame('Cluster 7', $this->service->nameCluster(
            ['top_tags' => [], 'names' => [], 'size' => 2],
            7,
        ));
    }

    // =========================================================================
    // Platform endpoint
    // =========================================================================

    public function test_platform_endpoint_rebuilds_for_one_org(): void
    {
        $this->mockLlm('Endpoint Cluster');
        $this->makeCluster(2, 'Doc');

        $superAdmin = User::factory()->superAdmin()->create();
        $token = $superAdmin->createToken('t')->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/v1/platform/vaults/clusters/rebuild', ['organization_id' => $this->org->id])
            ->assertStatus(200)
            ->assertJsonPath('data.created', 1)
            ->assertJsonPath('data.vaults.0.name', 'Endpoint Cluster');
    }

    public function test_platform_endpoint_is_superadmin_only(): void
    {
        $token = $this->user->createToken('t')->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/v1/platform/vaults/clusters/rebuild', ['organization_id' => $this->org->id])
            ->assertStatus(403);
    }
}
