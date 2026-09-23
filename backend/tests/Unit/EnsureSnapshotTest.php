<?php

namespace Tests\Unit;

use App\Enums\ResourceState;
use App\Enums\SystemFilePurpose;
use App\Jobs\ExtractEmbeddedPreview;
use App\Models\Collection;
use App\Models\File;
use App\Models\Organization;
use App\Models\Resource;
use App\Models\SystemFile;
use App\Models\User;
use App\Services\ResourceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Unit tests for ResourceService::ensureSnapshot().
 *
 * ensureSnapshot() is the deterministic, race-free resolver for "which file is starred (and
 * gets the rendered preview)". It runs at commit/update time. The motivating bug: several PDF
 * components dropped together upload in parallel, each independently claims the snapshot, and
 * ExtractEmbeddedPreview's guard then makes the concurrent renders skip each other — leaving
 * multiple files tagged snapshot and no (or a mismatched) preview. ensureSnapshot collapses
 * that to a single best candidate and renders its preview.
 *
 * ExtractEmbeddedPreview is faked (Bus::fake) so these tests don't shell out to pdftoppm.
 */
class EnsureSnapshotTest extends TestCase
{
    use RefreshDatabase;

    private ResourceService $service;

    private Resource $resource;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->service = app(ResourceService::class);

        $user = User::factory()->create();
        $org = Organization::factory()->create();

        // index_id = null keeps IndexResourceToElasticsearch a no-op.
        $collection = Collection::factory()->create([
            'organization_id' => $org->id,
            'user_owner_id' => $user->id,
            'index_id' => null,
        ]);

        $this->resource = Resource::factory()->create([
            'collection_id' => $collection->id,
            'organization_id' => $org->id,
            'user_owner_id' => $user->id,
            'state' => ResourceState::LIVE->value,
        ]);
    }

    /**
     * @param  array<int,string>|null  $usage
     */
    private function makeFile(string $role, string $ext, ?array $usage = null): File
    {
        $mime = match ($ext) {
            'pdf' => 'application/pdf',
            'jpg' => 'image/jpeg',
            'mp3' => 'audio/mpeg',
            'zip' => 'application/zip',
        };

        return File::factory()->create([
            'resource_id' => $this->resource->id,
            'role' => $role,
            'mime_type' => $mime,
            'filename' => uniqid().'.'.$ext,
            'usage' => $usage,
            'is_active' => true,
            'uncommitted_at' => null,
            'disk' => 'local',
        ]);
    }

    private function snapshotIds(): array
    {
        return File::where('resource_id', $this->resource->id)
            ->where('is_active', true)
            ->get()
            ->filter(fn (File $f) => $f->isSnapshot())
            ->pluck('id')
            ->all();
    }

    // =========================================================================

    public function test_collapses_multiple_snapshots_to_a_single_file_and_renders_its_preview(): void
    {
        Bus::fake();

        // Three committed PDF components, all tagged snapshot — the parallel-race aftermath.
        $first = $this->makeFile('component', 'pdf', ['snapshot']);
        $second = $this->makeFile('component', 'pdf', ['snapshot']);
        $third = $this->makeFile('component', 'pdf', ['snapshot']);

        $this->service->ensureSnapshot($this->resource);

        // Exactly one snapshot survives, and it's the earliest-created (lowest ordered uuid).
        $this->assertSame([$first->id], $this->snapshotIds());

        // A preview render is dispatched for the surviving snapshot.
        Bus::assertDispatched(ExtractEmbeddedPreview::class);
    }

    public function test_respects_an_existing_single_snapshot(): void
    {
        Bus::fake();

        $image = $this->makeFile('component', 'jpg', ['snapshot']);
        $this->makeFile('component', 'pdf'); // unstarred peer

        $this->service->ensureSnapshot($this->resource);

        // The user's single existing star is left untouched...
        $this->assertSame([$image->id], $this->snapshotIds());
        // ...and an image needs no rendered preview.
        Bus::assertNotDispatched(ExtractEmbeddedPreview::class);
    }

    public function test_prefers_image_over_pdf_when_nothing_is_starred(): void
    {
        Bus::fake();

        $this->makeFile('component', 'pdf');  // created first, but lower priority
        $image = $this->makeFile('component', 'jpg');

        $this->service->ensureSnapshot($this->resource);

        $this->assertSame([$image->id], $this->snapshotIds());
        Bus::assertNotDispatched(ExtractEmbeddedPreview::class);
    }

    public function test_canonical_wins_over_other_files(): void
    {
        Bus::fake();

        $this->makeFile('component', 'jpg');         // image, but not canonical
        $canonical = $this->makeFile('canonical', 'pdf');

        $this->service->ensureSnapshot($this->resource);

        $this->assertSame([$canonical->id], $this->snapshotIds());
        Bus::assertDispatched(ExtractEmbeddedPreview::class); // canonical PDF → render
    }

    public function test_rerenders_when_existing_preview_belongs_to_a_different_file(): void
    {
        Bus::fake();

        $first = $this->makeFile('component', 'pdf', ['snapshot']);
        $second = $this->makeFile('component', 'pdf', ['snapshot']);

        // A stray preview rendered from the *second* file (a race leftover).
        SystemFile::create([
            'resource_id' => $this->resource->id,
            'source_file_id' => $second->id,
            'purpose' => SystemFilePurpose::PREVIEW_SNAPSHOT->value,
            'filename' => 'preview-snapshot.jpg',
            'mime_type' => 'image/jpeg',
            'size' => 100,
            'path' => "{$this->resource->id}/system/preview-snapshot.jpg",
            'disk' => 'local',
            'is_active' => true,
        ]);

        $this->service->ensureSnapshot($this->resource);

        // Snapshot collapses to the earliest file, whose preview doesn't exist yet → re-render.
        $this->assertSame([$first->id], $this->snapshotIds());
        Bus::assertDispatched(ExtractEmbeddedPreview::class);
    }

    public function test_no_render_when_existing_preview_matches_the_snapshot(): void
    {
        Bus::fake();

        $first = $this->makeFile('component', 'pdf', ['snapshot']);
        $second = $this->makeFile('component', 'pdf', ['snapshot']);

        // Preview already rendered from the file that will win (earliest).
        SystemFile::create([
            'resource_id' => $this->resource->id,
            'source_file_id' => $first->id,
            'purpose' => SystemFilePurpose::PREVIEW_SNAPSHOT->value,
            'filename' => 'preview-snapshot.jpg',
            'mime_type' => 'image/jpeg',
            'size' => 100,
            'path' => "{$this->resource->id}/system/preview-snapshot.jpg",
            'disk' => 'local',
            'is_active' => true,
        ]);

        $this->service->ensureSnapshot($this->resource);

        $this->assertSame([$first->id], $this->snapshotIds());
        Bus::assertNotDispatched(ExtractEmbeddedPreview::class);
    }
}
