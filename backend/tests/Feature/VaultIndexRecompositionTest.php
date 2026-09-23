<?php

namespace Tests\Feature;

use App\Enums\ResourceState;
use App\Enums\VaultPurpose;
use App\Jobs\IndexResourceToElasticsearch;
use App\Jobs\RebuildVaultIndex;
use App\Models\Collection;
use App\Models\CollectionScheme;
use App\Models\Organization;
use App\Models\Resource;
use App\Models\User;
use App\Models\Vault;
use App\Models\Workspace;
use App\Services\ElasticsearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

/**
 * Epic 3.2 — recomposition triggers: overlay, purpose, and scheme changes
 * mark the projected index stale and queue a rebuild; ordinary resource
 * edits keep fresh vault indexes live.
 */
class VaultIndexRecompositionTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    private string $token;

    private CollectionScheme $scheme;

    private Vault $vault;

    private Resource $resource;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdmin = User::factory()->superAdmin()->create();
        $this->token = $this->superAdmin->createToken('t')->plainTextToken;

        $org = Organization::factory()->create();
        $ws = Workspace::factory()->create(['organization_id' => $org->id]);

        $this->scheme = CollectionScheme::create([
            'name' => 'recomp-scheme',
            'display_name' => 'Recomp',
            'accepted_mimetypes' => [],
            'is_system' => false,
            'fields' => [
                ['name' => 'technique', 'type' => 'string', 'es_type' => 'keyword',
                    'vault_roles' => ['gallery' => 'badge']],
            ],
        ]);

        $collection = Collection::factory()->create([
            'organization_id' => $org->id,
            'user_owner_id' => $this->superAdmin->id,
            'scheme_id' => $this->scheme->id,
        ]);

        $this->resource = Resource::factory()->create([
            'organization_id' => $org->id,
            'collection_id' => $collection->id,
            'user_owner_id' => $this->superAdmin->id,
            'state' => ResourceState::LIVE->value,
        ]);
        DB::table('dam_resource_workspace')->insert(['resource_id' => $this->resource->id, 'workspace_id' => $ws->id]);

        $this->vault = Vault::factory()->purpose(VaultPurpose::GALLERY)->published()->create([
            'organization_id' => $org->id,
            'indexed_at' => now(),
        ]);
        DB::table('workspace_vault')->insert(['workspace_id' => $ws->id, 'vault_id' => $this->vault->id]);
    }

    public function test_vault_creation_queues_initial_index_build(): void
    {
        Queue::fake();

        $org = Organization::factory()->create();

        $this->withToken($this->token)
            ->postJson('/api/v1/platform/vaults', [
                'organization_id' => $org->id,
                'name' => 'Fresh Gallery',
                'slug' => 'fresh-gallery',
                'purpose' => 'gallery',
                'salt' => 'my-secret-salt',
            ])
            ->assertStatus(201);

        // Without this a brand-new vault serves the DB fallback (keyword-only,
        // no facets, no semantic) until a purpose/overlay change rebuilds it.
        Queue::assertPushed(RebuildVaultIndex::class);
    }

    public function test_overlay_change_stales_and_queues_rebuild(): void
    {
        Queue::fake();

        $this->withToken($this->token)
            ->putJson("/api/v1/platform/vaults/{$this->vault->id}/overlays/{$this->scheme->id}", [
                'field_roles' => ['technique' => ['gallery' => 'hidden']],
            ])
            ->assertStatus(200);

        $this->assertNull($this->vault->fresh()->indexed_at);
        Queue::assertPushed(RebuildVaultIndex::class);
    }

    public function test_purpose_change_stales_and_queues_rebuild(): void
    {
        Queue::fake();

        $this->withToken($this->token)
            ->putJson("/api/v1/platform/vaults/{$this->vault->id}", ['purpose' => 'ai'])
            ->assertStatus(200);

        $this->assertNull($this->vault->fresh()->indexed_at);
        Queue::assertPushed(RebuildVaultIndex::class);
    }

    public function test_scheme_fields_change_stales_every_affected_vault(): void
    {
        Queue::fake();

        $fields = $this->scheme->fields;
        $fields[0]['vault_roles'] = ['gallery' => 'detail'];
        $this->scheme->update(['fields' => $fields]);

        $this->assertNull($this->vault->fresh()->indexed_at);
        Queue::assertPushed(RebuildVaultIndex::class);
    }

    public function test_scheme_change_without_fields_does_not_stale(): void
    {
        Queue::fake();

        $this->scheme->update(['display_name' => 'Renamed']);

        $this->assertNotNull($this->vault->fresh()->indexed_at);
        Queue::assertNotPushed(RebuildVaultIndex::class);
    }

    public function test_resource_indexing_keeps_fresh_vault_indexes_live(): void
    {
        $mock = Mockery::mock(ElasticsearchService::class);
        $mock->shouldReceive('indexResource')->once();
        $mock->shouldReceive('indexResourceIntoVaults')
            ->once()
            ->withArgs(fn (Resource $r) => $r->id === $this->resource->id);
        $this->instance(ElasticsearchService::class, $mock);

        (new IndexResourceToElasticsearch($this->resource->id))->handle($mock);
    }
}
