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
use App\Models\VaultLink;
use App\Models\Workspace;
use App\Services\VaultLinkService;
use Hashids\Hashids;
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
    // File slugs (spec §4.3) — a per-vault code by default, the filename on opt-in
    // =========================================================================

    public function test_file_slug_is_a_short_code_never_the_uploaded_filename(): void
    {
        $vault = $this->makeVault(VaultPurpose::GALLERY);
        $resource = $this->addResource('Las Médulas at dusk');
        $photo = File::factory()->for($resource)->create([
            'role' => FileRole::CANONICAL, 'filename' => '199008_InmaLimon_K23_23.jpg',
        ]);

        $slug = $this->links->getOrCreateLink($vault, null, $resource->id, $photo->id)->slug;

        $this->assertMatchesRegularExpression('/^[0-9a-z]{3}$/', $slug);
        foreach (['inmalimon', 'k23', '199008', 'medulas'] as $fragment) {
            $this->assertStringNotContainsString($fragment, $slug);
        }
    }

    public function test_file_code_is_stable_per_vault_and_unrelated_across_vaults(): void
    {
        $one = $this->makeVault(VaultPurpose::GALLERY);
        $two = $this->makeVault(VaultPurpose::GALLERY, ['slug' => 'other']);
        $resource = $this->addResource('Album');
        $photo = File::factory()->for($resource)->create(['role' => FileRole::COMPONENT, 'filename' => 'a.jpg', 'position' => 1]);

        $inOne = $this->links->getOrCreateLink($one, null, $resource->id, $photo->id)->slug;
        $inTwo = $this->links->getOrCreateLink($two, null, $resource->id, $photo->id)->slug;
        $this->assertNotSame($inOne, $inTwo, 'the same file must not be recognisable across vaults');

        // Renaming, reordering or re-roling the file never moves its address
        $photo->update(['filename' => 'renamed.jpg', 'position' => 7, 'role' => FileRole::SUPPORTING]);
        $resource->update(['name' => 'Renamed album']);
        $links = $this->links->reslugFileLinks($one, $resource->id);
        $this->assertSame([], $links);
        $this->assertSame($inOne, VaultLink::where('vault_id', $one->id)->where('file_id', $photo->id)->value('slug'));
    }

    public function test_link_hash_survives_a_clash_with_another_vault(): void
    {
        $vault = $this->makeVault(VaultPurpose::GALLERY);
        $other = $this->makeVault(VaultPurpose::GALLERY, ['slug' => 'other']);
        $resource = $this->addResource('Album');

        // Another vault already holds the exact string this vault would give
        // the next link id (salts differ, so encodings can coincide). The
        // squatter takes an id first; the link under test gets the next one.
        $elsewhere = $this->addResource('Elsewhere');
        $squatter = VaultLink::create([
            'vault_id' => $other->id, 'resource_id' => $elsewhere->id,
            'link_key' => hash('sha256', 'squatter'), 'slug' => 'squatter', 'hash' => 'placeholder',
        ]);
        $expected = (new Hashids($vault->salt, 8))->encode($squatter->id + 1);
        $squatter->update(['hash' => $expected]);

        $link = $this->links->getOrCreateLink($vault, null, $resource->id, null);

        $this->assertNotSame($expected, $link->hash);
        $this->assertGreaterThan(8, strlen($link->hash));
    }

    public function test_sibling_codes_are_distinct(): void
    {
        $vault = $this->makeVault(VaultPurpose::GALLERY);
        $resource = $this->addResource('Album');
        $slugs = [];
        for ($i = 1; $i <= 30; $i++) {
            $file = File::factory()->for($resource)->create(['role' => FileRole::COMPONENT, 'filename' => "p{$i}.jpg", 'position' => $i]);
            $slugs[] = $this->links->getOrCreateLink($vault, null, $resource->id, $file->id)->slug;
        }

        $this->assertCount(30, array_unique($slugs));
    }

    public function test_filename_slugs_on_opt_in_may_equal_the_resource_slug(): void
    {
        config(['tydal.export_visible_filenames' => true]);
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

    public function test_filename_slugs_on_opt_in_are_sibling_scoped(): void
    {
        config(['tydal.export_visible_filenames' => true]);
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

        // …and a resource named "Cover" still gets the clean resource slug:
        // resource slugs only compete with other resource slugs
        $link = $this->links->getOrCreateLink($vault, null, $this->addResource('Cover')->id, null);
        $this->assertSame('cover', $link->slug);
    }

    public function test_reslug_command_replaces_filename_slugs_with_codes_and_keeps_hashes(): void
    {
        $vault = $this->makeVault(VaultPurpose::GALLERY);
        $resource = $this->addResource('Album');
        $p1 = File::factory()->for($resource)->create(['role' => FileRole::COMPONENT, 'filename' => 'IMG_0412.jpg', 'position' => 1]);
        $p2 = File::factory()->for($resource)->create(['role' => FileRole::COMPONENT, 'filename' => 'IMG_0413.jpg', 'position' => 2]);

        // Links minted under the old rule carried the filename
        $l1 = $this->links->getOrCreateLink($vault, null, $resource->id, $p1->id);
        $l2 = $this->links->getOrCreateLink($vault, null, $resource->id, $p2->id);
        $codes = [$l1->slug, $l2->slug];
        $l1->update(['slug' => 'img-0412']);
        $l2->update(['slug' => 'img-0413']);
        $hashes = [$l1->hash, $l2->hash];

        $this->artisan('vault:reslug-files', ['--dry-run' => true])->assertSuccessful();
        $this->assertSame(['img-0412', 'img-0413'], [$l1->fresh()->slug, $l2->fresh()->slug]);

        $this->artisan('vault:reslug-files')->assertSuccessful();
        $this->assertSame($codes, [$l1->fresh()->slug, $l2->fresh()->slug]);
        $this->assertSame($hashes, [$l1->fresh()->hash, $l2->fresh()->hash]);

        // Idempotent: a second run has nothing to change
        $this->artisan('vault:reslug-files')
            ->expectsOutputToContain('nothing to change')
            ->assertSuccessful();
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

    public function test_download_name_is_the_code_never_the_uploaded_filename(): void
    {
        Storage::fake('s3');
        Storage::disk('s3')->put('files/x.jpg', 'jpeg-bytes');

        $this->makeVault(VaultPurpose::GALLERY);
        $resource = $this->addResource('Single Photo');
        File::factory()->for($resource)->create([
            'role' => FileRole::CANONICAL, 'filename' => '199008_InmaLimon_K23_23.JPG',
            'path' => 'files/x.jpg', 'disk' => 's3', 'mime_type' => 'image/jpeg',
        ]);

        $this->mintIndex();
        $disposition = $this->get('/v/acme/my-vault/single-photo')->assertStatus(200)->headers->get('Content-Disposition');

        // A single-file resource streams from the resource address, so no file
        // link exists yet: the name still carries the file's code
        $this->assertMatchesRegularExpression('/single-photo-[0-9a-z]{3}\.jpg/', (string) $disposition);
        $this->assertStringNotContainsString('InmaLimon', (string) $disposition);
    }

    public function test_download_name_is_the_uploaded_filename_when_exported(): void
    {
        config(['tydal.export_visible_filenames' => true]);
        Storage::fake('s3');
        Storage::disk('s3')->put('files/x.jpg', 'jpeg-bytes');

        $this->makeVault(VaultPurpose::GALLERY);
        $resource = $this->addResource('Single Photo');
        File::factory()->for($resource)->create([
            'role' => FileRole::CANONICAL, 'filename' => 'portrait-of-ana.jpg',
            'path' => 'files/x.jpg', 'disk' => 's3', 'mime_type' => 'image/jpeg',
        ]);

        $this->mintIndex();
        $disposition = $this->get('/v/acme/my-vault/single-photo')->assertStatus(200)->headers->get('Content-Disposition');

        $this->assertStringContainsString('portrait-of-ana.jpg', (string) $disposition);
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
        $this->assertSame([1, 2], [$files[0]['position'], $files[1]['position']]);
        // Uploaded names stay inside TYDAL: each file goes by its resource and code
        $this->assertSame('album-'.$files[0]['slug'].'.mp3', $files[0]['filename']);
        $this->assertSame('album-'.$files[1]['slug'].'.mp3', $files[1]['filename']);
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

        $first = VaultLink::where('vault_id', $vault->id)->where('file_id', File::where('position', 1)->value('id'))->value('slug');
        $this->getJson("/v/acme/my-vault/album/{$first}/meta")
            ->assertStatus(200)
            ->assertJsonPath('type', 'file')
            ->assertJsonPath('filename', "album-{$first}.mp3")
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

        $first = VaultLink::where('vault_id', $vault->id)->where('file_id', File::where('position', 1)->value('id'))->value('slug');
        $this->getJson("/v/acme/my-vault/album/{$first}/download")->assertStatus(403);
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
