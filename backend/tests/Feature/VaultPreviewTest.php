<?php

namespace Tests\Feature;

use App\Enums\FileRole;
use App\Enums\ResourceState;
use App\Enums\SystemFilePurpose;
use App\Enums\VaultPurpose;
use App\Models\File;
use App\Models\Organization;
use App\Models\Resource;
use App\Models\SystemFile;
use App\Models\Vault;
use App\Models\VaultKey;
use App\Models\Workspace;
use App\Services\VaultLinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Resource `preview` op + card `preview` field — the resource's designated
 * face (snapshot-flagged file, else the rendered PREVIEW_SNAPSHOT system
 * file), served through the vault boundary instead of raw storage URLs.
 */
class VaultPreviewTest extends TestCase
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
        Storage::fake('public');
    }

    private function makeVault(VaultPurpose $purpose = VaultPurpose::GALLERY, array $overrides = []): Vault
    {
        $vault = Vault::factory()->purpose($purpose)->published()->create(
            array_merge(['organization_id' => $this->org->id, 'slug' => 'my-vault'], $overrides)
        );

        DB::table('workspace_vault')->insert(['workspace_id' => $this->ws->id, 'vault_id' => $vault->id]);

        return $vault;
    }

    private function addResource(string $name): Resource
    {
        $resource = Resource::factory()->create([
            'organization_id' => $this->org->id,
            'name' => $name,
            'state' => ResourceState::LIVE->value,
        ]);

        DB::table('dam_resource_workspace')->insert(['resource_id' => $resource->id, 'workspace_id' => $this->ws->id]);

        return $resource;
    }

    private function addSnapshotFile(Resource $resource, string $path = 'previews/face.jpg'): File
    {
        Storage::disk('public')->put($path, 'snapshot-image-bytes');

        return File::factory()->for($resource)->create([
            'role' => FileRole::SUPPORTING,
            'usage' => ['snapshot'],
            'filename' => basename($path),
            'mime_type' => 'image/jpeg',
            'disk' => 'public',
            'path' => $path,
        ]);
    }

    /** Slugs are minted lazily by listings — warm the vault index first. */
    private function mintIndex(): void
    {
        $this->getJson('/v/acme/my-vault')->assertStatus(200);
    }

    private function addPreviewSystemFile(Resource $resource, string $path = 'system/preview.png'): SystemFile
    {
        Storage::disk('public')->put($path, 'rendered-first-page');

        return SystemFile::create([
            'resource_id' => $resource->id,
            'purpose' => SystemFilePurpose::PREVIEW_SNAPSHOT,
            'filename' => basename($path),
            'mime_type' => 'image/png',
            'size' => 18,
            'path' => $path,
            'disk' => 'public',
            'is_active' => true,
        ]);
    }

    // =========================================================================
    // Card field
    // =========================================================================

    public function test_card_carries_preview_url_when_snapshot_exists(): void
    {
        $vault = $this->makeVault();
        $resource = $this->addResource('Winter Catalogue');
        $this->addSnapshotFile($resource);

        $card = $this->getJson('/v/acme/my-vault')->assertStatus(200)->json('resources.0');

        $this->assertNotNull($card['preview']);
        $this->assertStringEndsWith('/preview', $card['preview']);
        $this->assertStringContainsString('/h/'.$vault->hash.'/', $card['preview']);
    }

    public function test_card_preview_is_null_without_snapshot_or_rendered_preview(): void
    {
        $this->makeVault();
        $this->addResource('Bare Resource');

        $card = $this->getJson('/v/acme/my-vault')->assertStatus(200)->json('resources.0');

        $this->assertNull($card['preview']);
    }

    // =========================================================================
    // The op — both address forms, both preview sources
    // =========================================================================

    public function test_preview_streams_the_snapshot_file(): void
    {
        $this->makeVault();
        $resource = $this->addResource('Winter Catalogue');
        $this->addSnapshotFile($resource);
        $this->mintIndex();

        $response = $this->get('/v/acme/my-vault/winter-catalogue/preview');

        $response->assertStatus(200)->assertHeader('Content-Type', 'image/jpeg');
        $this->assertSame('snapshot-image-bytes', $response->streamedContent());
    }

    public function test_preview_falls_back_to_rendered_system_file(): void
    {
        $vault = $this->makeVault();
        $resource = $this->addResource('Tourism Declaration');
        $this->addPreviewSystemFile($resource);

        $link = $this->links->getOrCreateLink($vault, null, $resource->id, null);
        $response = $this->get('/h/'.$vault->hash.'/'.$link->hash.'/preview');

        $response->assertStatus(200)->assertHeader('Content-Type', 'image/png');
        $this->assertSame('rendered-first-page', $response->streamedContent());
    }

    public function test_snapshot_file_wins_over_rendered_system_file(): void
    {
        $this->makeVault();
        $resource = $this->addResource('Winter Catalogue');
        $this->addPreviewSystemFile($resource);
        $this->addSnapshotFile($resource);
        $this->mintIndex();

        $response = $this->get('/v/acme/my-vault/winter-catalogue/preview');

        $this->assertSame('snapshot-image-bytes', $response->streamedContent());
    }

    public function test_preview_404s_when_none_exists(): void
    {
        $this->makeVault();
        $this->addResource('Bare Resource');

        $this->getJson('/v/acme/my-vault/bare-resource/preview')->assertStatus(404);
    }

    public function test_preview_denied_when_vault_does_not_expose_binary(): void
    {
        // AI-purpose vaults default to binary off — preview is Tier 2.
        $this->makeVault(VaultPurpose::AI);
        $resource = $this->addResource('Winter Catalogue');
        $this->addSnapshotFile($resource);
        $this->mintIndex();

        $this->getJson('/v/acme/my-vault/winter-catalogue/preview')->assertStatus(403);
    }

    // =========================================================================
    // Renditions — vault-scoped addresses, no raw storage URLs
    // =========================================================================

    private function addConvertedSnapshot(Resource $resource): array
    {
        $file = $this->addSnapshotFile($resource);
        $media = $resource->media()->create([
            'collection_name' => 'files', 'name' => 'face', 'file_name' => 'face.jpg',
            'mime_type' => 'image/jpeg', 'disk' => 'public', 'conversions_disk' => 'public',
            'size' => 20, 'manipulations' => [], 'custom_properties' => [],
            'generated_conversions' => ['medium' => true, 'large' => true, 'small' => false],
            'responsive_images' => [],
        ]);
        $file->update(['media_id' => $media->id]);
        $bytes = base64_decode('UklGRiIAAABXRUJQVlA4IBYAAAAwAQCdASoBAAEADsD+JaQAA3AAAAAA');
        foreach (['medium', 'large'] as $name) {
            Storage::disk('public')->put($media->getPathRelativeToRoot($name), $bytes);
        }

        return [$media, $bytes];
    }

    public function test_card_advertises_generated_preview_sizes_and_streams_them_without_download_permission(): void
    {
        $vault = $this->makeVault(overrides: ['is_downloadable' => false]);
        $resource = $this->addResource('Converted Face');
        [$media, $bytes] = $this->addConvertedSnapshot($resource);
        $card = $this->getJson('/h/'.$vault->hash.'/resources')->assertOk()->json('resources.0');
        $this->assertEqualsCanonicalizing(['original', 'ai-prepared', 'medium', 'large'], array_column($card['preview_renditions'], 'name'));
        foreach ($card['preview_renditions'] as $rendition) {
            $this->assertStringStartsWith($card['preview'], $rendition['url']);
            $this->assertStringNotContainsString('/storage/', $rendition['url']);
        }
        foreach (['/h/'.$vault->hash.'/'.$card['id'], '/v/acme/my-vault/converted-face'] as $path) {
            $response = $this->get($path.'/preview?rendition=medium')->assertOk()->assertHeader('Content-Type', 'image/webp');
            $this->assertSame($bytes, $response->streamedContent());
        }
        $this->get('/h/'.$vault->hash.'/'.$card['id'].'/download')->assertForbidden();
        $this->get($card['preview'].'?rendition=small')->assertNotFound();
        $this->get($card['preview'].'?rendition=unknown')->assertNotFound();
        $this->get($card['preview'].'?rendition[]=medium')->assertNotFound();
        Storage::disk('public')->delete($media->getPathRelativeToRoot('large'));
        $this->get($card['preview'].'?rendition=large')->assertNotFound();
    }

    /**
     * The bare/default preview link (card.preview, chosen by nothing in
     * particular) must not be a back door to the untouched original once a
     * vault opts out of downloads — only an explicit ?rendition=original ask
     * still reaches it (see test_ai_prepared_preserves_fitting_bytes_and_reports_actual_metadata_without_caching).
     */
    public function test_default_preview_degrades_to_largest_conversion_without_download_permission(): void
    {
        $vault = $this->makeVault(overrides: ['is_downloadable' => false]);
        $resource = $this->addResource('Converted Face');
        [, $bytes] = $this->addConvertedSnapshot($resource);
        $card = $this->getJson('/h/'.$vault->hash.'/resources')->assertOk()->json('resources.0');

        $response = $this->get($card['preview'])->assertOk()->assertHeader('Content-Type', 'image/webp');
        $this->assertSame($bytes, $response->streamedContent());
    }

    public function test_default_preview_serves_original_when_vault_allows_downloads(): void
    {
        $vault = $this->makeVault(overrides: ['is_downloadable' => true]);
        $resource = $this->addResource('Converted Face');
        $this->addConvertedSnapshot($resource);
        $card = $this->getJson('/h/'.$vault->hash.'/resources')->assertOk()->json('resources.0');

        $response = $this->get($card['preview'])->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->assertSame('snapshot-image-bytes', $response->streamedContent());
    }

    public function test_preview_sizes_retain_private_vault_and_binary_gates(): void
    {
        $vault = $this->makeVault(overrides: ['state' => 'private']);
        $resource = $this->addResource('Private Face');
        $this->addConvertedSnapshot($resource);
        $link = $this->links->getOrCreateLink($vault, null, $resource->id, null);
        [, $key] = VaultKey::mint($vault, 'read');
        $url = '/h/'.$vault->hash.'/'.$link->hash.'/preview?rendition=medium';
        $this->get($url)->assertNotFound();
        $this->withHeaders(['X-Vault-Key' => $key])->get($url)->assertOk();
        $vault->update(['exposure_policy' => ['allow_binary' => false]]);
        $this->withHeaders(['X-Vault-Key' => $key])->get($url)->assertForbidden();
    }

    private function pngBytes(): string
    {
        $image = imagecreatetruecolor(640, 400);
        imagefill($image, 0, 0, imagecolorallocate($image, 120, 180, 220));
        ob_start();
        imagepng($image, null, 0);
        $bytes = ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }

    public function test_ai_prepared_preserves_fitting_bytes_and_reports_actual_metadata_without_caching(): void
    {
        $this->makeVault(overrides: ['is_downloadable' => false]);
        $resource = $this->addResource('Vision Face');
        $file = $this->addSnapshotFile($resource, 'previews/face.png');
        $file->update(['mime_type' => 'image/png']);
        $original = $this->pngBytes();
        Storage::disk('public')->put($file->path, $original);
        $files = Storage::disk('public')->allFiles();
        $card = $this->getJson('/v/acme/my-vault')->assertOk()->json('resources.0');
        $ai = collect($card['preview_renditions'])->firstWhere('name', 'ai-prepared');
        $this->assertTrue($ai['on_demand']);
        $this->assertSame(5242880, $ai['max_bytes']);
        foreach ([$ai['url'], '/v/acme/my-vault/vision-face/preview?rendition=ai-prepared'] as $url) {
            $response = $this->get($url)->assertOk()
                ->assertHeader('Content-Type', 'image/png')
                ->assertHeader('Content-Length', (string) strlen($original))
                ->assertHeader('X-Tydal-Image-Rendition', 'ai-prepared')
                ->assertHeader('X-Tydal-Image-Width', '640')
                ->assertHeader('X-Tydal-Image-Height', '400')
                ->assertHeader('X-Tydal-Image-Max-Bytes', '5242880');
            $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
            $this->assertSame($original, $response->streamedContent());
        }
        $compressed = $this->get($ai['url'].'&max_bytes=65536')->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg')
            ->assertHeader('X-Tydal-Image-Max-Bytes', '65536')
            ->assertHeader('X-Tydal-Image-Width', '640')
            ->assertHeader('X-Tydal-Image-Height', '400')->streamedContent();
        $this->assertLessThanOrEqual(65536, strlen($compressed));
        $this->assertSame('image/jpeg', getimagesizefromstring($compressed)['mime']);
        $this->assertSame($original, Storage::disk('public')->get($file->path));
        $this->assertSame($files, Storage::disk('public')->allFiles());
        $response = $this->get($card['preview'].'?rendition=original')->assertOk()
            ->assertHeader('X-Tydal-Image-Rendition', 'original')
            ->assertHeader('X-Tydal-Image-Width', '640');
        $this->assertSame($original, $response->streamedContent());
    }

    public function test_ai_prepared_supports_generated_system_previews(): void
    {
        $this->makeVault();
        $resource = $this->addResource('Document Face');
        $file = $this->addPreviewSystemFile($resource);
        $bytes = $this->pngBytes();
        Storage::disk('public')->put($file->path, $bytes);
        $card = $this->getJson('/v/acme/my-vault')->assertOk()->json('resources.0');
        $ai = collect($card['preview_renditions'])->firstWhere('name', 'ai-prepared');
        $this->assertSame($bytes, $this->get($ai['url'])->assertOk()->streamedContent());
    }

    public function test_ai_prepared_retains_access_and_current_workspace_membership_checks(): void
    {
        $vault = $this->makeVault(overrides: ['state' => 'private', 'is_downloadable' => false]);
        $resource = $this->addResource('Private Face');
        $file = $this->addSnapshotFile($resource);
        Storage::disk('public')->put($file->path, $this->pngBytes());
        $link = $this->links->getOrCreateLink($vault, null, $resource->id, null);
        [, $key] = VaultKey::mint($vault, 'read');
        $url = '/h/'.$vault->hash.'/'.$link->hash.'/preview?rendition=ai-prepared';
        $this->get($url)->assertNotFound();
        $this->withHeaders(['X-Vault-Key' => $key])->get($url)->assertOk();
        $vault->update(['exposure_policy' => ['allow_binary' => false]]);
        $this->get($url)->assertForbidden();
        $vault->update(['exposure_policy' => ['allow_binary' => true]]);
        DB::table('dam_resource_workspace')->where('resource_id', $resource->id)->delete();
        $this->get($url)->assertNotFound();
    }

    public function test_ai_prepared_rejects_bad_budgets_and_invalid_images_without_fallback(): void
    {
        $this->makeVault();
        $resource = $this->addResource('Invalid Face');
        $file = $this->addSnapshotFile($resource);
        $card = $this->getJson('/v/acme/my-vault')->assertOk()->json('resources.0');
        // Even image-only Accept headers receive JSON errors, never a redirect.
        foreach (['abc', '0', '65535', '20971521', '65536.5', ''] as $budget) {
            $this->withHeaders(['Accept' => 'image/*'])->get($card['preview'].'?rendition=ai-prepared&max_bytes='.$budget)
                ->assertStatus(422)->assertHeader('Content-Type', 'application/json');
        }
        $this->get($card['preview'].'?rendition=ai-prepared&max_bytes[]=65536')->assertStatus(422);
        $this->get($card['preview'].'?rendition=original&max_bytes=65536')->assertStatus(422);
        $this->get($card['preview'].'?rendition=ai-prepared')->assertStatus(422);
        Storage::disk('public')->delete($file->path);
        $this->get($card['preview'].'?rendition=ai-prepared')->assertNotFound();
    }

    public function test_rendition_urls_stay_inside_the_vault_boundary(): void
    {
        $vault = $this->makeVault();
        $resource = $this->addResource('Winter Catalogue');
        File::factory()->for($resource)->create([
            'role' => FileRole::CANONICAL,
            'filename' => 'cover.jpg',
            'mime_type' => 'image/jpeg',
            'disk' => 'public',
            'path' => 'files/cover.jpg',
        ]);

        $this->mintIndex();
        // File slugs are minted by the manifest op.
        $this->getJson('/v/acme/my-vault/winter-catalogue/files')->assertStatus(200);

        $payload = $this->getJson('/v/acme/my-vault/winter-catalogue/cover/renditions')
            ->assertStatus(200)
            ->json();

        foreach ($payload['renditions'] as $rendition) {
            $this->assertStringContainsString('/h/'.$vault->hash.'/', $rendition['url']);
            $this->assertStringNotContainsString('/storage/', $rendition['url']);
        }
    }
}
