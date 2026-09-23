<?php

namespace Tests\Feature;

use App\Enums\FileRole;
use App\Enums\ResourceState;
use App\Enums\VaultPurpose;
use App\Enums\VaultState;
use App\Models\File;
use App\Models\Organization;
use App\Models\Resource;
use App\Models\SemanticTag;
use App\Models\Vault;
use App\Models\Workspace;
use App\Services\VaultLinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Epic 2.3 — human namespace /v/{org}/{vault}/…, operation grammar,
 * manifest rule, tier gating, and per-vault slugs.
 */
class VaultNamespaceTest extends TestCase
{
    use RefreshDatabase;

    private VaultLinkService $links;

    private Organization $org;

    private Workspace $ws;

    protected function setUp(): void
    {
        parent::setUp();
        $this->links = app(VaultLinkService::class);
        $this->org = Organization::factory()->create(['slug' => 'acme']);
        $this->ws = Workspace::factory()->create(['organization_id' => $this->org->id]);
    }

    private function makeVault(VaultPurpose $purpose = VaultPurpose::GALLERY, array $overrides = []): Vault
    {
        $vault = Vault::factory()->purpose($purpose)->published()->create(
            array_merge(['organization_id' => $this->org->id, 'slug' => 'my-vault'], $overrides)
        );

        DB::table('workspace_vault')->insert(['workspace_id' => $this->ws->id, 'vault_id' => $vault->id]);

        return $vault;
    }

    private function addResource(string $name, array $overrides = []): Resource
    {
        $resource = Resource::factory()->create(array_merge([
            'organization_id' => $this->org->id,
            'name' => $name,
            'state' => ResourceState::LIVE->value,
        ], $overrides));

        DB::table('dam_resource_workspace')->insert(['resource_id' => $resource->id, 'workspace_id' => $this->ws->id]);

        return $resource;
    }

    /** Slugs are minted lazily by listings — warm the vault index first. */
    private function mintIndex(): void
    {
        $this->getJson('/v/acme/my-vault')->assertStatus(200);
    }

    // =========================================================================
    // Tenancy — the boundary only ever serves its own organization (spec §2)
    // =========================================================================

    public function test_foreign_resource_in_a_linked_workspace_is_never_projected(): void
    {
        // The attach paths keep workspace membership inside one org, so this
        // pivot row should not exist — but if it ever did (bad import, a
        // future code path), the boundary itself must still refuse it.
        $vault = $this->makeVault();
        $mine = $this->addResource('Mine');
        $foreign = $this->addResource('Theirs', [
            'organization_id' => Organization::factory()->create()->id,
        ]);

        $names = collect($this->getJson('/v/acme/my-vault/resources')->assertStatus(200)->json('resources'))
            ->pluck('name');

        $this->assertContains('Mine', $names->all());
        $this->assertNotContains('Theirs', $names->all());

        // …and its own address does not resolve either.
        $link = $this->links->getOrCreateLink($vault, null, $foreign->id, null);
        $this->getJson("/h/{$vault->hash}/{$link->hash}/meta")->assertStatus(404);

        // The same address for an own-org resource still works.
        $ok = $this->links->getOrCreateLink($vault, null, $mine->id, null);
        $this->getJson("/h/{$vault->hash}/{$ok->hash}/meta")->assertStatus(200);
    }

    // =========================================================================
    // Slug generation
    // =========================================================================

    public function test_resource_slug_comes_from_name_and_deduplicates_per_vault(): void
    {
        $vault = $this->makeVault();
        $a = $this->addResource('Winter Catalogue');
        $b = $this->addResource('Winter Catalogue');

        $linkA = $this->links->getOrCreateLink($vault, null, $a->id, null);
        $linkB = $this->links->getOrCreateLink($vault, null, $b->id, null);

        $this->assertSame('winter-catalogue', $linkA->slug);
        $this->assertSame('winter-catalogue-2', $linkB->slug);
    }

    public function test_reserved_grammar_words_are_never_slugs(): void
    {
        $vault = $this->makeVault();
        $resource = $this->addResource('Search');

        $link = $this->links->getOrCreateLink($vault, null, $resource->id, null);

        $this->assertSame('search-2', $link->slug);
    }

    // =========================================================================
    // File-slug scoping — per resource, not per vault (spec §4.3)
    // =========================================================================

    public function test_file_slug_may_equal_its_resource_slug(): void
    {
        $vault = $this->makeVault(VaultPurpose::GALLERY);
        $resource = $this->addResource('Doc');
        File::factory()->for($resource)->create(['role' => FileRole::CANONICAL, 'filename' => 'doc.pdf']);
        File::factory()->for($resource)->create(['role' => FileRole::COMPONENT, 'filename' => 'annex.pdf']);

        $this->mintIndex();
        $this->getJson('/v/acme/my-vault/doc')->assertStatus(200); // mint file slugs

        // /doc/doc — the file keeps its natural name under its resource
        $this->getJson('/v/acme/my-vault/doc/doc/meta')
            ->assertStatus(200)
            ->assertJsonPath('filename', 'doc.pdf')
            ->assertJsonPath('slug', 'doc');
    }

    public function test_same_filename_reusable_across_resources_in_one_vault(): void
    {
        $vault = $this->makeVault(VaultPurpose::GALLERY);
        $one = $this->addResource('Album One');
        $two = $this->addResource('Album Two');
        File::factory()->for($one)->create(['role' => FileRole::COMPONENT, 'filename' => 'cover.jpg', 'position' => 1]);
        File::factory()->for($one)->create(['role' => FileRole::COMPONENT, 'filename' => 'back.jpg', 'position' => 2]);
        File::factory()->for($two)->create(['role' => FileRole::COMPONENT, 'filename' => 'cover.jpg', 'position' => 1]);
        File::factory()->for($two)->create(['role' => FileRole::COMPONENT, 'filename' => 'back.jpg', 'position' => 2]);

        $this->mintIndex();
        $this->getJson('/v/acme/my-vault/album-one')->assertStatus(200);
        $this->getJson('/v/acme/my-vault/album-two')->assertStatus(200);

        // Both covers keep the clean sibling-scoped name — no -2 suffix
        $this->getJson('/v/acme/my-vault/album-one/cover/meta')
            ->assertStatus(200)->assertJsonPath('filename', 'cover.jpg');
        $this->getJson('/v/acme/my-vault/album-two/cover/meta')
            ->assertStatus(200)->assertJsonPath('filename', 'cover.jpg');
    }

    public function test_resource_slugs_do_not_compete_with_file_slugs(): void
    {
        $vault = $this->makeVault(VaultPurpose::GALLERY);
        $album = $this->addResource('Album');
        File::factory()->for($album)->create(['role' => FileRole::COMPONENT, 'filename' => 'cover.jpg', 'position' => 1]);
        File::factory()->for($album)->create(['role' => FileRole::COMPONENT, 'filename' => 'back.jpg', 'position' => 2]);

        $this->mintIndex();
        $this->getJson('/v/acme/my-vault/album')->assertStatus(200); // mints file slug "cover"

        // A resource named "Cover" still gets the clean resource slug —
        // it only competes with other RESOURCE slugs
        $coverResource = $this->addResource('Cover');
        $link = $this->links->getOrCreateLink($vault, null, $coverResource->id, null);

        $this->assertSame('cover', $link->slug);
    }

    // =========================================================================
    // Vault level — index, meta, tags, search
    // =========================================================================

    public function test_vault_index_lists_identity_cards(): void
    {
        $vault = $this->makeVault();
        $this->addResource('Alpha');
        $this->addResource('Beta');

        $this->getJson('/v/acme/my-vault')
            ->assertStatus(200)
            ->assertJsonPath('type', 'vault-index')
            ->assertJsonPath('pagination.total', 2)
            ->assertJsonPath('resources.0.name', 'Alpha')
            ->assertJsonPath('resources.0.slug', 'alpha')
            ->assertJsonPath('resources.0.path', '/v/acme/my-vault/alpha');
    }

    public function test_vault_index_filters_by_tag(): void
    {
        $vault = $this->makeVault();
        $tagged = $this->addResource('Tagged');
        $this->addResource('Untagged');

        $tag = SemanticTag::factory()->create(['label' => 'winter', 'organization_id' => $this->org->id]);
        $tagged->semanticTags()->attach($tag->id);

        $this->getJson('/v/acme/my-vault?tag=winter')
            ->assertStatus(200)
            ->assertJsonPath('pagination.total', 1)
            ->assertJsonPath('resources.0.name', 'Tagged');
    }

    public function test_unpublished_vault_is_not_reachable_by_slug(): void
    {
        $this->makeVault(VaultPurpose::GALLERY, ['state' => VaultState::PRIVATE->value]);

        $this->getJson('/v/acme/my-vault')->assertStatus(404);
    }

    public function test_vault_meta_is_self_description(): void
    {
        $this->makeVault(VaultPurpose::AI);
        $this->addResource('Doc');

        $this->getJson('/v/acme/my-vault/meta')
            ->assertStatus(200)
            ->assertJsonPath('type', 'vault')
            ->assertJsonPath('purpose', 'ai')
            ->assertJsonPath('organization', 'acme')
            ->assertJsonPath('resource_count', 1)
            ->assertJsonPath('tiers.identity', true)
            ->assertJsonPath('tiers.chunks', true)
            ->assertJsonPath('tiers.binary', false);
    }

    public function test_vault_tags_aggregates_counts(): void
    {
        $vault = $this->makeVault();
        $a = $this->addResource('A');
        $b = $this->addResource('B');

        $tag = SemanticTag::factory()->create(['label' => 'shared', 'organization_id' => $this->org->id]);
        $a->semanticTags()->attach($tag->id);
        $b->semanticTags()->attach($tag->id);

        $this->getJson('/v/acme/my-vault/tags')
            ->assertStatus(200)
            ->assertJsonPath('tags.0.name', 'shared')
            ->assertJsonPath('tags.0.count', 2);
    }

    public function test_vault_search_matches_name(): void
    {
        $this->makeVault();
        $this->addResource('Solar panel manual');
        $this->addResource('Unrelated');

        $this->getJson('/v/acme/my-vault/search?q=solar')
            ->assertStatus(200)
            ->assertJsonPath('pagination.total', 1)
            ->assertJsonPath('results.0.name', 'Solar panel manual');
    }

    // =========================================================================
    // Manifest rule
    // =========================================================================

    public function test_single_exposed_file_serves_binary_when_tier2_allows(): void
    {
        Storage::fake('s3');
        Storage::disk('s3')->put('files/one.jpg', 'jpeg-bytes');

        $vault = $this->makeVault(VaultPurpose::GALLERY);
        $resource = $this->addResource('Single Photo');
        File::factory()->for($resource)->create([
            'role' => FileRole::CANONICAL,
            'path' => 'files/one.jpg',
            'disk' => 's3',
            'mime_type' => 'image/jpeg',
        ]);

        $this->mintIndex();

        $this->get('/v/acme/my-vault/single-photo')
            ->assertStatus(200)
            ->assertHeader('Content-Type', 'image/jpeg');
    }

    public function test_multiple_exposed_files_return_ordered_manifest(): void
    {
        $vault = $this->makeVault(VaultPurpose::GALLERY);
        $resource = $this->addResource('Album');
        File::factory()->for($resource)->create(['role' => FileRole::COMPONENT, 'filename' => 'track-b.mp3', 'position' => 2]);
        File::factory()->for($resource)->create(['role' => FileRole::COMPONENT, 'filename' => 'track-a.mp3', 'position' => 1]);
        File::factory()->for($resource)->create(['role' => FileRole::SUPPORTING, 'filename' => 'notes.txt']);

        $this->mintIndex();

        $response = $this->getJson('/v/acme/my-vault/album')
            ->assertStatus(200)
            ->assertJsonPath('type', 'manifest')
            ->assertJsonPath('resource.slug', 'album');

        $files = $response->json('files');
        $this->assertCount(2, $files); // supporting is unaddressed
        $this->assertSame('track-a.mp3', $files[0]['filename']);
        $this->assertSame('track-b.mp3', $files[1]['filename']);
        $this->assertNotNull($files[0]['url']); // gallery exposes binary addresses
    }

    public function test_ai_vault_returns_manifest_without_binary_urls_even_for_single_file(): void
    {
        $vault = $this->makeVault(VaultPurpose::AI);
        $resource = $this->addResource('Doc');
        File::factory()->for($resource)->create(['role' => FileRole::CANONICAL, 'filename' => 'doc.pdf']);

        $this->mintIndex();

        $this->getJson('/v/acme/my-vault/doc')
            ->assertStatus(200)
            ->assertJsonPath('type', 'manifest')
            ->assertJsonPath('files.0.url', null);
    }

    // =========================================================================
    // Resource operations & tier gating
    // =========================================================================

    public function test_resource_meta_and_tags_are_tier0(): void
    {
        $vault = $this->makeVault(VaultPurpose::AI);
        $resource = $this->addResource('Doc');
        $tag = SemanticTag::factory()->create(['label' => 'legal', 'organization_id' => $this->org->id]);
        $resource->semanticTags()->attach($tag->id);

        $this->mintIndex();

        $this->getJson('/v/acme/my-vault/doc/meta')
            ->assertStatus(200)
            ->assertJsonPath('type', 'resource')
            ->assertJsonPath('name', 'Doc')
            ->assertJsonPath('chunks_available', true);

        $this->getJson('/v/acme/my-vault/doc/tags')
            ->assertStatus(200)
            ->assertJsonPath('tags.0', 'legal');
    }

    public function test_links_op_is_tier2_gated(): void
    {
        $this->makeVault(VaultPurpose::AI);
        $this->addResource('Doc');

        $this->mintIndex();

        // ai preset: binary off by default
        $this->getJson('/v/acme/my-vault/doc/links')->assertStatus(403);
    }

    public function test_links_op_mints_urls_when_tier2_allows(): void
    {
        $vault = $this->makeVault(VaultPurpose::OBSIDIAN);
        $resource = $this->addResource('Doc');
        File::factory()->for($resource)->create(['role' => FileRole::CANONICAL, 'filename' => 'doc.pdf']);

        $this->mintIndex();

        $response = $this->getJson('/v/acme/my-vault/doc/links')->assertStatus(200);

        $this->assertStringContainsString("/h/{$vault->hash}/", $response->json('resource.url'));
        $this->assertStringContainsString("/h/{$vault->hash}/", $response->json('files.0.url'));
    }

    public function test_exposure_policy_override_can_enable_binary_on_ai_vault(): void
    {
        $this->makeVault(VaultPurpose::AI, ['exposure_policy' => ['allow_binary' => true]]);
        $this->addResource('Doc');

        $this->mintIndex();

        $this->getJson('/v/acme/my-vault/doc/links')->assertStatus(200);
    }

    public function test_chunks_op_is_tier1_gated(): void
    {
        $this->makeVault(VaultPurpose::GALLERY);
        $this->addResource('Photo');

        $this->mintIndex();

        // gallery preset: chunks off
        $this->getJson('/v/acme/my-vault/photo/chunks')->assertStatus(403);
    }

    public function test_chunks_op_returns_empty_without_search_index(): void
    {
        $this->makeVault(VaultPurpose::AI);
        $this->addResource('Doc');

        $this->mintIndex();

        $this->getJson('/v/acme/my-vault/doc/chunks')
            ->assertStatus(200)
            ->assertJsonPath('type', 'chunks')
            ->assertJsonPath('items', []);
    }

    public function test_related_finds_resources_sharing_tags(): void
    {
        $vault = $this->makeVault();
        $a = $this->addResource('First');
        $b = $this->addResource('Second');
        $this->addResource('Lonely');

        $tag = SemanticTag::factory()->create(['label' => 'linked', 'organization_id' => $this->org->id]);
        $a->semanticTags()->attach($tag->id);
        $b->semanticTags()->attach($tag->id);

        $this->mintIndex();

        $this->getJson('/v/acme/my-vault/first/related')
            ->assertStatus(200)
            ->assertJsonPath('resources.0.name', 'Second')
            ->assertJsonCount(1, 'resources');
    }

    // =========================================================================
    // File level
    // =========================================================================

    public function test_file_slug_resolves_and_meta_works(): void
    {
        $vault = $this->makeVault(VaultPurpose::GALLERY);
        $resource = $this->addResource('Album');
        File::factory()->for($resource)->create([
            'role' => FileRole::COMPONENT, 'filename' => 'track-one.mp3', 'position' => 1,
        ]);
        File::factory()->for($resource)->create([
            'role' => FileRole::COMPONENT, 'filename' => 'track-two.mp3', 'position' => 2,
        ]);

        $this->mintIndex();

        // Manifest mints the file slugs
        $this->getJson('/v/acme/my-vault/album')->assertStatus(200);

        $this->getJson('/v/acme/my-vault/album/track-one/meta')
            ->assertStatus(200)
            ->assertJsonPath('type', 'file')
            ->assertJsonPath('filename', 'track-one.mp3')
            ->assertJsonPath('role', 'component')
            ->assertJsonPath('position', 1);
    }

    public function test_file_binary_is_tier2_gated(): void
    {
        $vault = $this->makeVault(VaultPurpose::AI);
        $resource = $this->addResource('Doc');
        File::factory()->for($resource)->create(['role' => FileRole::CANONICAL, 'filename' => 'doc.pdf']);

        $this->mintIndex();

        // Mint the file slug via the manifest (it shares the per-vault slug
        // space with the resource slug, so read it back rather than assume)
        $manifest = $this->getJson('/v/acme/my-vault/doc')->assertStatus(200);
        $fileSlug = $manifest->json('files.0.slug');

        $this->get("/v/acme/my-vault/doc/{$fileSlug}")->assertStatus(403);
    }

    public function test_file_download_requires_downloadable_vault(): void
    {
        $vault = $this->makeVault(VaultPurpose::GALLERY, ['is_downloadable' => false]);
        $resource = $this->addResource('Album');
        File::factory()->for($resource)->create(['role' => FileRole::COMPONENT, 'filename' => 'a.mp3', 'position' => 1]);
        File::factory()->for($resource)->create(['role' => FileRole::COMPONENT, 'filename' => 'b.mp3', 'position' => 2]);

        $this->mintIndex();

        $this->getJson('/v/acme/my-vault/album')->assertStatus(200);

        $this->getJson('/v/acme/my-vault/album/a/download')->assertStatus(403);
    }

    // =========================================================================
    // Hash-form grammar parity
    // =========================================================================

    public function test_hash_form_dispatches_same_grammar(): void
    {
        $vault = $this->makeVault(VaultPurpose::AI);
        $resource = $this->addResource('Doc');

        $link = $this->links->getOrCreateLink($vault, null, $resource->id, null);

        $this->getJson("/h/{$vault->hash}/{$link->hash}/meta")
            ->assertStatus(200)
            ->assertJsonPath('type', 'resource')
            ->assertJsonPath('name', 'Doc');

        $this->getJson("/h/{$vault->hash}/{$link->hash}/links")->assertStatus(403);
    }

    public function test_hash_form_default_get_follows_manifest_rule(): void
    {
        $vault = $this->makeVault(VaultPurpose::GALLERY);
        $resource = $this->addResource('Album');
        File::factory()->for($resource)->create(['role' => FileRole::COMPONENT, 'filename' => 'a.mp3', 'position' => 1]);
        File::factory()->for($resource)->create(['role' => FileRole::COMPONENT, 'filename' => 'b.mp3', 'position' => 2]);

        $link = $this->links->getOrCreateLink($vault, null, $resource->id, null);

        $this->getJson("/h/{$vault->hash}/{$link->hash}")
            ->assertStatus(200)
            ->assertJsonPath('type', 'manifest')
            ->assertJsonCount(2, 'files');
    }
}
