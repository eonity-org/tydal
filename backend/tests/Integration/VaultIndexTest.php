<?php

namespace Tests\Integration;

use App\Enums\ResourceState;
use App\Enums\VaultPurpose;
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
use Tests\TestCase;

/**
 * Epic 3.2 — per-vault projected index against a live Elasticsearch:
 * Resource → Schema → Vault Overlay → Index. Slot-mapped fields match,
 * facet slots aggregate, and search switches to the ES path once
 * indexed_at is stamped.
 */
class VaultIndexTest extends TestCase
{
    use RefreshDatabase;

    private Vault $vault;

    private ElasticsearchService $es;

    protected function setUp(): void
    {
        parent::setUp();

        $this->es = app(ElasticsearchService::class);

        $user = User::factory()->create();
        $org = Organization::factory()->create(['slug' => 'acme']);
        $ws = Workspace::factory()->create(['organization_id' => $org->id]);

        $scheme = CollectionScheme::create([
            'name' => 'artworks',
            'display_name' => 'Artworks',
            'accepted_mimetypes' => [],
            'is_system' => false,
            'fields' => [
                ['name' => 'technique', 'type' => 'select', 'es_type' => 'keyword', 'storage' => 'metadata',
                    'validators' => ['in' => ['oil', 'acrylic']],
                    'vault_roles' => ['gallery' => 'badge']],
                ['name' => 'notes', 'type' => 'text', 'es_type' => 'text', 'storage' => 'metadata',
                    'vault_roles' => ['gallery' => 'detail']],
            ],
        ]);

        $collection = Collection::factory()->create([
            'organization_id' => $org->id,
            'user_owner_id' => $user->id,
            'scheme_id' => $scheme->id,
        ]);

        foreach ([
            ['Sunset Field', ['technique' => 'oil', 'notes' => 'painted at dusk near the river']],
            ['Harbor Morning', ['technique' => 'acrylic', 'notes' => 'quick harbor study']],
        ] as [$name, $metadata]) {
            $resource = Resource::factory()->create([
                'organization_id' => $org->id,
                'collection_id' => $collection->id,
                'user_owner_id' => $user->id,
                'state' => ResourceState::LIVE->value,
                'name' => $name,
                'metadata' => $metadata,
            ]);
            DB::table('dam_resource_workspace')->insert(['resource_id' => $resource->id, 'workspace_id' => $ws->id]);
        }

        $this->vault = Vault::factory()->purpose(VaultPurpose::GALLERY)->published()->create([
            'organization_id' => $org->id,
            'slug' => 'expo',
        ]);
        DB::table('workspace_vault')->insert(['workspace_id' => $ws->id, 'vault_id' => $this->vault->id]);
    }

    protected function tearDown(): void
    {
        try {
            $this->es->deleteIndex($this->es->buildVaultIndexName($this->vault));
        } catch (\Throwable) {
            // best-effort cleanup
        }

        parent::tearDown();
    }

    public function test_full_projection_loop_reindex_search_facets(): void
    {
        $indexed = $this->es->reindexVault($this->vault);
        $this->assertSame(2, $indexed);

        $this->vault->forceFill(['indexed_at' => now()])->saveQuietly();
        $this->es->refreshIndex($this->es->buildVaultIndexName($this->vault));

        // Slot-mapped metadata matches full-text ("detail" slot → text)
        $result = $this->es->searchVaultIndex($this->vault, 'harbor study', 1, 10, ['technique' => 'metadata.technique']);
        $this->assertSame(1, $result['total']);
        $this->assertSame('Harbor Morning', $result['hits'][0]['name']);
        $this->assertArrayNotHasKey('embedding', $result['hits'][0]);

        // Facet slot aggregates ("badge" → keyword terms)
        $all = $this->es->searchVaultIndex($this->vault, '', 1, 10, ['technique' => 'metadata.technique']);
        $this->assertSame(2, $all['total']);
        $this->assertSame(['acrylic' => 1, 'oil' => 1], collect($all['facets']['technique'])->sortKeys()->all());

        // The /v/ search endpoint now rides the ES path
        $response = $this->getJson('/v/acme/expo/search?q=dusk');
        $response->assertStatus(200)
            ->assertJsonPath('pagination.total', 1)
            ->assertJsonPath('results.0.name', 'Sunset Field');
        $this->assertArrayHasKey('facets', $response->json());
    }

    public function test_command_rebuilds_by_slug(): void
    {
        $this->artisan('search:reindex --vault=expo')
            ->expectsOutputToContain('expo: 2 document(s)')
            ->assertExitCode(0);

        $this->assertNotNull($this->vault->fresh()->indexed_at);
    }
}
