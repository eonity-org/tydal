<?php

namespace Tests\Feature;

use App\Enums\VaultPurpose;
use App\Enums\VaultState;
use App\Jobs\RebuildVaultIndex;
use App\Models\Collection;
use App\Models\Organization;
use App\Models\SearchIndex;
use App\Models\User;
use App\Models\Vault;
use App\Models\VaultKey;
use App\Services\ElasticsearchService;
use App\Services\ExhibitionProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

/**
 * `exhibitions:setup` / `exhibitions:create` — preparing TYDAL to serve photo
 * exhibitions to a gallery client (Full Frame) without configuring anything
 * by hand. Elasticsearch is mocked: these tests must never touch a real index.
 */
class ExhibitionsCommandsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake([RebuildVaultIndex::class]);
        Storage::fake('public');

        $es = Mockery::mock(ElasticsearchService::class);
        $es->shouldReceive('provisionIndex')->zeroOrMoreTimes();
        $es->shouldReceive('indexResource')->zeroOrMoreTimes();
        $es->shouldReceive('indexResourceIntoVaults')->zeroOrMoreTimes();
        $this->instance(ElasticsearchService::class, $es);

        $this->owner = User::factory()->create();
        $this->org = Organization::factory()->create(['slug' => 'lucila', 'name' => 'Lucila']);
        $this->org->users()->attach($this->owner->id, ['role' => 'owner']);
    }

    // =========================================================================
    // exhibitions:setup
    // =========================================================================

    public function test_setup_creates_the_photo_collection_on_its_own_index(): void
    {
        $this->artisan('exhibitions:setup', ['--org' => 'lucila'])
            ->expectsOutputToContain('Collection created: Photos')
            ->assertSuccessful();

        $collection = Collection::where('organization_id', $this->org->id)->firstOrFail();
        $this->assertSame('photo_exhibition', $collection->scheme->name);
        $this->assertSame(ExhibitionProvisioner::DEFAULT_INDEX, $collection->searchIndex->index_name);
        $this->assertSame($this->owner->id, $collection->user_owner_id);
    }

    public function test_setup_is_safe_to_run_again(): void
    {
        $this->artisan('exhibitions:setup', ['--org' => 'lucila'])->assertSuccessful();
        $this->artisan('exhibitions:setup', ['--org' => 'lucila'])
            ->expectsOutputToContain('already set up')
            ->assertSuccessful();

        $this->assertSame(1, Collection::where('organization_id', $this->org->id)->count());
    }

    public function test_setup_can_share_an_existing_index(): void
    {
        SearchIndex::create(['index_name' => 'tydal_multimedia', 'display_name' => 'Multimedia', 'is_active' => true]);

        $this->artisan('exhibitions:setup', ['--org' => 'lucila', '--index' => 'tydal_multimedia'])->assertSuccessful();

        $this->assertSame('tydal_multimedia', Collection::where('organization_id', $this->org->id)->firstOrFail()->searchIndex->index_name);
    }

    public function test_setup_refuses_an_unknown_index_or_organization(): void
    {
        $this->artisan('exhibitions:setup', ['--org' => 'lucila', '--index' => 'nope'])->assertFailed();
        $this->artisan('exhibitions:setup', ['--org' => 'nobody'])->assertFailed();
    }

    // =========================================================================
    // exhibitions:create
    // =========================================================================

    public function test_create_needs_setup_first(): void
    {
        $this->artisan('exhibitions:create', ['--org' => 'lucila', '--name' => 'Semana 42'])
            ->expectsOutputToContain('run exhibitions:setup first')
            ->assertFailed();
    }

    public function test_create_builds_a_ready_to_connect_exhibition(): void
    {
        $this->artisan('exhibitions:setup', ['--org' => 'lucila'])->assertSuccessful();

        $this->artisan('exhibitions:create', ['--org' => 'lucila', '--name' => 'Semana 42'])
            ->expectsOutputToContain('/v/lucila/semana-42')
            ->expectsOutputToContain('Write key')
            ->assertSuccessful();

        $vault = Vault::where('slug', 'semana-42')->firstOrFail();
        $this->assertSame(VaultPurpose::GALLERY, $vault->purpose);
        $this->assertSame(VaultState::PRIVATE, $vault->state);

        // It shows its workspace, and uploads land there, in the Photos collection.
        $workspace = $vault->workspaces()->firstOrFail();
        $this->assertSame('Semana 42', $workspace->name);
        $this->assertSame([
            'workspace_id' => $workspace->id,
            'collection_id' => Collection::where('organization_id', $this->org->id)->value('id'),
        ], $vault->exposure_policy['ingest']);

        $abilities = VaultKey::where('vault_id', $vault->id)->pluck('abilities')->all();
        $this->assertEqualsCanonicalizing([['read'], ExhibitionProvisioner::WRITE_ABILITIES], $abilities);
    }

    public function test_create_refuses_a_slug_already_in_use(): void
    {
        $this->artisan('exhibitions:setup', ['--org' => 'lucila'])->assertSuccessful();
        $this->artisan('exhibitions:create', ['--org' => 'lucila', '--name' => 'Semana 42'])->assertSuccessful();

        $this->artisan('exhibitions:create', ['--org' => 'lucila', '--name' => 'Semana 42'])
            ->expectsOutputToContain('already exists')
            ->assertFailed();
        $this->assertSame(1, Vault::where('slug', 'semana-42')->count());
    }

    public function test_the_minted_write_key_can_ingest_straight_away(): void
    {
        $provisioner = app(ExhibitionProvisioner::class);
        $provisioner->setup($this->org, null, 'Photos');
        $exhibition = $provisioner->create($this->org, 'Semana 42');

        $this->post("/h/{$exhibition['vault']->hash}/w/ingest", [
            'image' => UploadedFile::fake()->image('photo.jpg', 60, 40),
            'metadata' => json_encode(['name' => 'Morning at the Pier', 'author' => 'Ana Ruiz']),
        ], ['X-Vault-Key' => $exhibition['write_key']])
            ->assertStatus(200)
            ->assertJsonPath('ok', true);
    }
}
