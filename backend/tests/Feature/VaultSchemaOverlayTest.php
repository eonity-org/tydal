<?php

namespace Tests\Feature;

use App\Enums\ResourceState;
use App\Enums\VaultPurpose;
use App\Models\Collection;
use App\Models\CollectionScheme;
use App\Models\Organization;
use App\Models\Resource;
use App\Models\User;
use App\Models\Vault;
use App\Models\VaultSchemaOverlay;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Epic 3.1 — VaultSchemaOverlay engine: layered semantic resolution
 * (structural preset ← scheme vault_roles ← per-vault overlay), the overlay
 * CRUD, vocabulary enforcement, drift tolerance, and the presentation block
 * in the vault self-description.
 */
class VaultSchemaOverlayTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    private string $token;

    private Organization $org;

    private Workspace $ws;

    private CollectionScheme $scheme;

    private Vault $vault;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdmin = User::factory()->superAdmin()->create();
        $this->token = $this->superAdmin->createToken('t')->plainTextToken;

        $this->org = Organization::factory()->create(['slug' => 'acme']);
        $this->ws = Workspace::factory()->create(['organization_id' => $this->org->id]);

        $this->scheme = CollectionScheme::create([
            'name' => 'artworks',
            'display_name' => 'Artworks',
            'accepted_mimetypes' => [],
            'is_system' => false,
            'fields' => [
                // author's intent: badge in galleries
                ['name' => 'technique', 'type' => 'select', 'es_type' => 'keyword', 'is_facet' => true,
                    'validators' => ['in' => ['oil', 'acrylic']],
                    'vault_roles' => ['gallery' => 'badge', 'ai' => 'facet']],
                // no vault_roles → structural preset decides
                ['name' => 'notes', 'type' => 'text', 'es_type' => 'text', 'display_in_form' => true],
                // neither facet nor in form → hidden by preset
                ['name' => 'internal_ref', 'type' => 'string', 'es_type' => 'keyword'],
            ],
        ]);

        $collection = Collection::factory()->create([
            'organization_id' => $this->org->id,
            'user_owner_id' => $this->superAdmin->id,
            'scheme_id' => $this->scheme->id,
        ]);

        $resource = Resource::factory()->create([
            'organization_id' => $this->org->id,
            'collection_id' => $collection->id,
            'user_owner_id' => $this->superAdmin->id,
            'state' => ResourceState::LIVE->value,
        ]);
        DB::table('dam_resource_workspace')->insert(['resource_id' => $resource->id, 'workspace_id' => $this->ws->id]);

        $this->vault = Vault::factory()->purpose(VaultPurpose::GALLERY)->published()->create([
            'organization_id' => $this->org->id,
            'slug' => 'expo',
        ]);
        DB::table('workspace_vault')->insert(['workspace_id' => $this->ws->id, 'vault_id' => $this->vault->id]);
    }

    private function presentationFields(): array
    {
        $meta = $this->getJson('/v/acme/expo/meta')->assertStatus(200)->json();
        $block = collect($meta['presentation'])->firstWhere('scheme', $this->scheme->id);

        return $block['fields'] ?? [];
    }

    // =========================================================================
    // Layered resolution
    // =========================================================================

    public function test_scheme_vault_roles_and_presets_resolve_in_vault_meta(): void
    {
        $fields = $this->presentationFields();

        $this->assertSame('badge', $fields['technique'], 'scheme author intent wins over preset');
        $this->assertSame('detail', $fields['notes'], 'display_in_form preset → detail');
        $this->assertSame('hidden', $fields['internal_ref'], 'unflagged field defaults hidden');
    }

    public function test_core_slots_come_from_builtins(): void
    {
        $meta = $this->getJson('/v/acme/expo/meta')->assertStatus(200)->json();
        $block = collect($meta['presentation'])->firstWhere('scheme', $this->scheme->id);

        $this->assertSame('name', $block['core']['caption']);
        $this->assertSame('snapshot', $block['core']['image']);
    }

    public function test_vault_overlay_overrides_the_scheme_suggestion(): void
    {
        VaultSchemaOverlay::create([
            'vault_id' => $this->vault->id,
            'scheme_id' => $this->scheme->id,
            'field_roles' => ['technique' => ['gallery' => 'hidden']],
        ]);

        $fields = $this->presentationFields();

        $this->assertSame('hidden', $fields['technique'], 'this exhibition hides technique');
        $this->assertSame('detail', $fields['notes'], 'untouched fields keep their resolution');
    }

    public function test_overlay_entries_for_removed_fields_are_ignored(): void
    {
        VaultSchemaOverlay::create([
            'vault_id' => $this->vault->id,
            'scheme_id' => $this->scheme->id,
            'field_roles' => ['field_that_was_renamed' => ['gallery' => 'badge']],
        ]);

        // Must not 500, must not leak the ghost field
        $fields = $this->presentationFields();
        $this->assertArrayNotHasKey('field_that_was_renamed', $fields);
    }

    // =========================================================================
    // Overlay CRUD (platform)
    // =========================================================================

    public function test_put_overlay_validates_fields_and_vocabulary(): void
    {
        // Unknown field
        $this->withToken($this->token)
            ->putJson("/api/v1/platform/vaults/{$this->vault->id}/overlays/{$this->scheme->id}", [
                'field_roles' => ['ghost' => ['gallery' => 'badge']],
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.0', 'Field "ghost" does not exist in scheme "artworks"');

        // Unknown slot
        $this->withToken($this->token)
            ->putJson("/api/v1/platform/vaults/{$this->vault->id}/overlays/{$this->scheme->id}", [
                'field_roles' => ['technique' => ['gallery' => 'marquee']],
            ])
            ->assertStatus(422);

        // Valid upsert
        $this->withToken($this->token)
            ->putJson("/api/v1/platform/vaults/{$this->vault->id}/overlays/{$this->scheme->id}", [
                'field_roles' => ['technique' => ['gallery' => 'credit']],
            ])
            ->assertStatus(200);

        $this->assertSame('credit', $this->presentationFields()['technique']);

        // Empty map removes the overlay → back to scheme suggestion
        $this->withToken($this->token)
            ->putJson("/api/v1/platform/vaults/{$this->vault->id}/overlays/{$this->scheme->id}", [
                'field_roles' => [],
            ])
            ->assertStatus(200);

        $this->assertSame('badge', $this->presentationFields()['technique']);
        $this->assertSame(0, VaultSchemaOverlay::count());
    }

    public function test_list_overlays_returns_rows_and_resolved_presentation(): void
    {
        $this->withToken($this->token)
            ->getJson("/api/v1/platform/vaults/{$this->vault->id}/overlays")
            ->assertStatus(200)
            ->assertJsonPath('data.overlays', [])
            ->assertJsonPath('data.presentation.0.scheme_name', 'artworks');
    }

    // =========================================================================
    // schema:validate — new contract keys
    // =========================================================================

    public function test_schema_validate_enforces_role_vocabulary_and_ai_fill(): void
    {
        CollectionScheme::create([
            'name' => 'broken-semantics',
            'display_name' => 'Broken',
            'accepted_mimetypes' => [],
            'is_system' => false,
            'fields' => [
                ['name' => 'a', 'type' => 'string', 'es_type' => 'keyword',
                    'vault_roles' => ['gallery' => 'marquee']],           // unknown slot
                ['name' => 'b', 'type' => 'string', 'es_type' => 'keyword',
                    'vault_roles' => ['warehouse' => 'badge']],           // unknown purpose
                ['name' => 'c', 'type' => 'string', 'es_type' => 'keyword',
                    'ai_fill' => ['enabled' => 'yes']],                   // wrong type
            ],
        ]);

        $this->artisan('schema:validate --scheme=broken-semantics')
            ->expectsOutputToContain('unknown slot "marquee"')
            ->expectsOutputToContain('unknown purpose "warehouse"')
            ->expectsOutputToContain('ai_fill.enabled must be boolean')
            ->assertExitCode(1);
    }

    public function test_valid_semantic_keys_pass_schema_validate(): void
    {
        $this->artisan('schema:validate --scheme=artworks')->assertExitCode(0);
    }
}
