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
use Illuminate\Support\Facades\Hash;
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
        $this->artisan('exhibitions:setup', ['--org' => 'lucila', '--no-interaction' => true])
            ->expectsOutputToContain('Collection created: Photos')
            ->assertSuccessful();

        $collection = Collection::where('organization_id', $this->org->id)->firstOrFail();
        $this->assertSame('photo_exhibition', $collection->scheme->name);
        $this->assertSame(ExhibitionProvisioner::DEFAULT_INDEX, $collection->searchIndex->index_name);
        $this->assertSame($this->owner->id, $collection->user_owner_id);
    }

    public function test_setup_is_safe_to_run_again(): void
    {
        $this->artisan('exhibitions:setup', ['--org' => 'lucila', '--no-interaction' => true])->assertSuccessful();
        $this->artisan('exhibitions:setup', ['--org' => 'lucila', '--no-interaction' => true])
            ->expectsOutputToContain('already set up')
            ->assertSuccessful();

        $this->assertSame(1, Collection::where('organization_id', $this->org->id)->count());
    }

    public function test_setup_can_share_an_existing_index(): void
    {
        SearchIndex::create(['index_name' => 'tydal_multimedia', 'display_name' => 'Multimedia', 'is_active' => true]);

        $this->artisan('exhibitions:setup', ['--org' => 'lucila', '--index' => 'tydal_multimedia', '--no-interaction' => true])->assertSuccessful();

        $this->assertSame('tydal_multimedia', Collection::where('organization_id', $this->org->id)->firstOrFail()->searchIndex->index_name);
    }

    public function test_setup_refuses_an_unknown_index_or_organization(): void
    {
        $this->artisan('exhibitions:setup', ['--org' => 'lucila', '--index' => 'nope', '--no-interaction' => true])->assertFailed();
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
        $this->artisan('exhibitions:setup', ['--org' => 'lucila', '--no-interaction' => true])->assertSuccessful();

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
        $this->artisan('exhibitions:setup', ['--org' => 'lucila', '--no-interaction' => true])->assertSuccessful();
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

    // =========================================================================
    // --curator
    // =========================================================================

    public function test_create_with_a_new_curator_makes_their_account(): void
    {
        $this->artisan('exhibitions:setup', ['--org' => 'lucila', '--no-interaction' => true])->assertSuccessful();

        $this->artisan('exhibitions:create', ['--org' => 'lucila', '--name' => 'Semana 42', '--curator' => 'curator@example.org', '--no-interaction' => true])
            ->expectsOutputToContain('curator@example.org')
            ->expectsOutputToContain('new account')
            ->assertSuccessful();

        $curator = User::where('email', 'curator@example.org')->firstOrFail();
        $this->assertSame('editor', $this->org->users()->where('users.id', $curator->id)->first()->pivot->role);
    }

    public function test_an_existing_member_is_never_downgraded(): void
    {
        $this->artisan('exhibitions:setup', ['--org' => 'lucila', '--no-interaction' => true])->assertSuccessful();
        $admin = User::factory()->create(['email' => 'boss@example.org']);
        $this->org->users()->attach($admin->id, ['role' => 'admin']);

        $this->artisan('exhibitions:create', ['--org' => 'lucila', '--name' => 'Semana 42', '--curator' => 'boss@example.org', '--role' => 'viewer'])
            ->expectsOutputToContain('their existing TYDAL password')
            ->assertSuccessful();

        $this->assertSame('admin', $this->org->users()->where('users.id', $admin->id)->first()->pivot->role);
    }

    public function test_a_bad_curator_option_stops_before_anything_is_created(): void
    {
        $this->artisan('exhibitions:setup', ['--org' => 'lucila', '--no-interaction' => true])->assertSuccessful();

        $this->artisan('exhibitions:create', ['--org' => 'lucila', '--name' => 'Semana 42', '--curator' => 'x@example.org', '--role' => 'owner'])
            ->assertFailed();
        $this->artisan('exhibitions:create', ['--org' => 'lucila', '--name' => 'Semana 42', '--curator' => 'not-an-email'])
            ->assertFailed();

        $this->assertSame(0, Vault::count());
    }

    public function test_write_key_check_names_the_vaults_organization(): void
    {
        $provisioner = app(ExhibitionProvisioner::class);
        $provisioner->setup($this->org, null, 'Photos');
        $exhibition = $provisioner->create($this->org, 'Semana 42');

        $this->getJson("/h/{$exhibition['vault']->hash}/w", ['X-Vault-Key' => $exhibition['write_key']])
            ->assertJsonPath('organization.slug', 'lucila')
            ->assertJsonPath('organization.id', $this->org->id);
        // Readers still learn nothing about who owns it.
        $this->getJson("/h/{$exhibition['vault']->hash}/w", ['X-Vault-Key' => $exhibition['read_key']])
            ->assertStatus(403);
    }

    // =========================================================================
    // setup: the collection's language
    // =========================================================================

    public function test_setup_asks_for_the_language_when_creating_the_collection(): void
    {
        $this->artisan('exhibitions:setup', ['--org' => 'lucila'])
            ->expectsQuestion('Language of the photographs\' texts — titles, descriptions (ISO code: en, es, fr, ca, pt-BR…)', 'es')
            ->expectsOutputToContain('language es')
            ->assertSuccessful();

        $this->assertSame('es', Collection::where('organization_id', $this->org->id)->value('language'));
    }

    public function test_setup_takes_the_language_as_an_option_and_defaults_to_english(): void
    {
        $this->artisan('exhibitions:setup', ['--org' => 'lucila', '--language' => 'pt-BR'])->assertSuccessful();
        $this->assertSame('pt-BR', Collection::where('organization_id', $this->org->id)->value('language'));

        Collection::query()->delete();
        $this->artisan('exhibitions:setup', ['--org' => 'lucila', '--no-interaction' => true])->assertSuccessful();
        $this->assertSame(ExhibitionProvisioner::DEFAULT_LANGUAGE, Collection::where('organization_id', $this->org->id)->value('language'));
    }

    public function test_a_rerun_with_a_language_corrects_it_and_without_one_never_asks(): void
    {
        $this->artisan('exhibitions:setup', ['--org' => 'lucila', '--language' => 'en'])->assertSuccessful();

        // Interactive, but the collection exists: no question is expected.
        $this->artisan('exhibitions:setup', ['--org' => 'lucila'])->assertSuccessful();
        $this->artisan('exhibitions:setup', ['--org' => 'lucila', '--language' => 'ca'])
            ->expectsOutputToContain('language ca')
            ->assertSuccessful();

        $this->assertSame('ca', Collection::where('organization_id', $this->org->id)->value('language'));
    }

    public function test_setup_refuses_a_malformed_language_before_creating_anything(): void
    {
        $this->artisan('exhibitions:setup', ['--org' => 'lucila', '--language' => 'Spanish!'])
            ->expectsOutputToContain('is not a language code')
            ->assertFailed();

        $this->assertSame(0, Collection::where('organization_id', $this->org->id)->count());
    }

    public function test_setup_suggests_the_wrapper_when_run_through_it(): void
    {
        $this->artisan('exhibitions:setup', ['--org' => 'lucila', '--no-interaction' => true])
            ->expectsOutputToContain('php artisan exhibitions:create --org=lucila')
            ->assertSuccessful();

        putenv('TYDAL_VIA_FULLFRAME_SH=1');
        try {
            $this->artisan('exhibitions:setup', ['--org' => 'lucila', '--no-interaction' => true])
                ->expectsOutputToContain('tools/clients/fullframe.sh create --org=lucila')
                ->doesntExpectOutputToContain('php artisan exhibitions:create')
                ->assertSuccessful();
        } finally {
            putenv('TYDAL_VIA_FULLFRAME_SH');
        }
    }

    // =========================================================================
    // create: the new curator's password
    // =========================================================================

    public function test_the_curator_password_can_be_chosen_with_an_option(): void
    {
        $this->artisan('exhibitions:setup', ['--org' => 'lucila', '--no-interaction' => true])->assertSuccessful();

        $this->artisan('exhibitions:create', [
            '--org' => 'lucila', '--name' => 'Semana 42',
            '--curator' => 'curator@example.org', '--curator-password' => 'chosen-secret-1',
        ])
            ->expectsOutputToContain('the one you chose')
            ->doesntExpectOutputToContain('chosen-secret-1')
            ->assertSuccessful();

        $this->assertTrue(Hash::check('chosen-secret-1', User::where('email', 'curator@example.org')->value('password')));
    }

    public function test_the_curator_password_is_asked_hidden_and_confirmed(): void
    {
        $this->artisan('exhibitions:setup', ['--org' => 'lucila', '--no-interaction' => true])->assertSuccessful();

        $this->artisan('exhibitions:create', ['--org' => 'lucila', '--name' => 'Semana 42', '--curator' => 'curator@example.org'])
            ->expectsQuestion('Password for the new curator curator@example.org (leave empty to generate one)', 'typed-secret-9')
            ->expectsQuestion('Repeat the password', 'typed-secret-9')
            ->expectsOutputToContain('the one you chose')
            ->assertSuccessful();

        $this->assertTrue(Hash::check('typed-secret-9', User::where('email', 'curator@example.org')->value('password')));
    }

    public function test_an_empty_answer_generates_the_password(): void
    {
        $this->artisan('exhibitions:setup', ['--org' => 'lucila', '--no-interaction' => true])->assertSuccessful();

        $this->artisan('exhibitions:create', ['--org' => 'lucila', '--name' => 'Semana 42', '--curator' => 'curator@example.org'])
            ->expectsQuestion('Password for the new curator curator@example.org (leave empty to generate one)', '')
            ->expectsOutputToContain('generated')
            ->assertSuccessful();
    }

    public function test_a_mismatched_or_short_password_stops_before_anything_is_created(): void
    {
        $this->artisan('exhibitions:setup', ['--org' => 'lucila', '--no-interaction' => true])->assertSuccessful();

        $this->artisan('exhibitions:create', ['--org' => 'lucila', '--name' => 'Semana 42', '--curator' => 'curator@example.org'])
            ->expectsQuestion('Password for the new curator curator@example.org (leave empty to generate one)', 'typed-secret-9')
            ->expectsQuestion('Repeat the password', 'typo-secret-9')
            ->expectsOutputToContain('do not match')
            ->assertFailed();
        $this->artisan('exhibitions:create', [
            '--org' => 'lucila', '--name' => 'Semana 42',
            '--curator' => 'curator@example.org', '--curator-password' => 'short',
        ])
            ->expectsOutputToContain('at least 8 characters')
            ->assertFailed();

        $this->assertSame(0, Vault::count());
        $this->assertFalse(User::where('email', 'curator@example.org')->exists());
    }

    public function test_an_existing_account_keeps_its_password(): void
    {
        $this->artisan('exhibitions:setup', ['--org' => 'lucila', '--no-interaction' => true])->assertSuccessful();
        $existing = User::factory()->create(['email' => 'known@example.org', 'password' => Hash::make('their-own-pass')]);

        $this->artisan('exhibitions:create', [
            '--org' => 'lucila', '--name' => 'Semana 42',
            '--curator' => 'known@example.org', '--curator-password' => 'attempted-overwrite',
        ])
            ->expectsOutputToContain('password is left unchanged')
            ->expectsOutputToContain('their existing TYDAL password')
            ->assertSuccessful();

        $this->assertTrue(Hash::check('their-own-pass', $existing->fresh()->password));
    }
}
