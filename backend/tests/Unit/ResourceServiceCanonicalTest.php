<?php

namespace Tests\Unit;

use App\Enums\FileRole;
use App\Enums\ResourceState;
use App\Enums\SystemFilePurpose;
use App\Models\Collection;
use App\Models\File;
use App\Models\Organization;
use App\Models\Resource;
use App\Models\SystemFile;
use App\Models\User;
use App\Services\ResourceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Unit tests for ResourceService::recalculatePromotedMetadata() and setCanonical().
 *
 * The canonical file is the single source of truth for resource metadata:
 *   - recalculatePromotedMetadata() merges tika_metadata from SystemFiles sourced
 *     from the canonical file (oldest-first — newest updated_at wins on conflict).
 *   - setCanonical() demotes the existing canonical (and any component peers) to
 *     'supporting' — the only role peers may hold alongside a canonical — promotes
 *     the target file to 'canonical', then recalculates metadata and triggers ES sync.
 */
class ResourceServiceCanonicalTest extends TestCase
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

        // index_id = null keeps IndexResourceToElasticsearch::dispatchSync() a no-op
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

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeFile(string $role = 'supporting', bool $active = true): File
    {
        return File::factory()->create([
            'resource_id' => $this->resource->id,
            'role' => $role,
            'is_active' => $active,
            'disk' => 'local',
        ]);
    }

    private function makeTikaSystemFile(File $file, array $tikaMeta): SystemFile
    {
        return SystemFile::create([
            'resource_id' => $this->resource->id,
            'source_file_id' => $file->id,
            'purpose' => SystemFilePurpose::TIKA_METADATA->value,
            'filename' => 'metadata.json',
            'mime_type' => 'application/json',
            'size' => 100,
            'path' => 'meta/test.json',
            'disk' => 'local',
            'is_active' => true,
            'metadata' => ['tika_metadata' => $tikaMeta],
        ]);
    }

    // =========================================================================
    // recalculatePromotedMetadata
    // =========================================================================

    public function test_no_canonical_file_sets_promoted_metadata_to_null(): void
    {
        $this->resource->update(['promoted_file_metadata' => ['stale' => 'value']]);

        $this->service->recalculatePromotedMetadata($this->resource);

        $this->assertNull($this->resource->fresh()->promoted_file_metadata);
    }

    public function test_canonical_file_saves_its_tika_metadata(): void
    {
        $file = $this->makeFile('canonical');
        $this->makeTikaSystemFile($file, ['Author' => 'Alice', 'Title' => 'Doc A']);

        $this->service->recalculatePromotedMetadata($this->resource);

        $meta = $this->resource->fresh()->promoted_file_metadata;
        $this->assertSame('Alice', $meta['Author']);
        $this->assertSame('Doc A', $meta['Title']);
    }

    public function test_multiple_system_files_for_canonical_merge_newest_key_winning(): void
    {
        $file = $this->makeFile('canonical');

        // Older SystemFile
        $this->makeTikaSystemFile($file, ['Author' => 'Alice', 'Pages' => '10']);

        $this->travel(1)->minute();

        // Newer SystemFile — Author and Language change, Pages stays from older
        $this->makeTikaSystemFile($file, ['Author' => 'Bob', 'Language' => 'en']);

        $this->service->recalculatePromotedMetadata($this->resource);

        $meta = $this->resource->fresh()->promoted_file_metadata;
        $this->assertSame('Bob', $meta['Author']);   // newest wins on conflict
        $this->assertSame('10', $meta['Pages']);    // unique key from older file preserved
        $this->assertSame('en', $meta['Language']); // unique key from newer file
    }

    public function test_inactive_canonical_file_is_excluded(): void
    {
        $this->makeFile('canonical', false); // active=false

        $this->resource->update(['promoted_file_metadata' => ['stale' => 'value']]);

        $this->service->recalculatePromotedMetadata($this->resource);

        $this->assertNull($this->resource->fresh()->promoted_file_metadata);
    }

    public function test_canonical_file_without_tika_system_file_yields_null(): void
    {
        $this->makeFile('canonical');
        // No SystemFile created

        $this->service->recalculatePromotedMetadata($this->resource);

        $this->assertNull($this->resource->fresh()->promoted_file_metadata);
    }

    public function test_non_canonical_file_tika_data_is_not_included(): void
    {
        $canonical = $this->makeFile('canonical');
        $supporting = $this->makeFile('supporting');

        $this->makeTikaSystemFile($canonical, ['Author' => 'Included']);
        $this->makeTikaSystemFile($supporting, ['Author' => 'Excluded', 'Extra' => 'should-be-absent']);

        $this->service->recalculatePromotedMetadata($this->resource);

        $meta = $this->resource->fresh()->promoted_file_metadata;
        $this->assertSame('Included', $meta['Author']);
        $this->assertArrayNotHasKey('Extra', $meta);
    }

    public function test_system_file_without_tika_key_in_metadata_is_skipped(): void
    {
        $file = $this->makeFile('canonical');
        SystemFile::create([
            'resource_id' => $this->resource->id,
            'source_file_id' => $file->id,
            'purpose' => SystemFilePurpose::TIKA_METADATA->value,
            'filename' => 'metadata.json',
            'mime_type' => 'application/json',
            'size' => 10,
            'path' => 'meta/empty.json',
            'disk' => 'local',
            'is_active' => true,
            'metadata' => ['other_key' => 'value'],  // no tika_metadata key
        ]);

        $this->service->recalculatePromotedMetadata($this->resource);

        $this->assertNull($this->resource->fresh()->promoted_file_metadata);
    }

    // =========================================================================
    // setCanonical
    // =========================================================================

    public function test_set_canonical_promotes_file_and_recalculates_metadata(): void
    {
        $file = $this->makeFile('supporting');
        $this->makeTikaSystemFile($file, ['Author' => 'Charlie']);

        $this->service->setCanonical($this->resource, $file);

        $this->assertSame('canonical', $file->fresh()->role->value);
        $this->assertSame('Charlie', $this->resource->fresh()->promoted_file_metadata['Author']);
    }

    public function test_set_canonical_demotes_previous_canonical_to_supporting(): void
    {
        $first = $this->makeFile('canonical');
        $second = $this->makeFile('supporting');

        $this->service->setCanonical($this->resource, $second);

        $this->assertSame('supporting', $first->fresh()->role->value);
        $this->assertSame('canonical', $second->fresh()->role->value);
    }

    public function test_set_canonical_clears_relation_on_promoted_file(): void
    {
        $file = $this->makeFile('supporting');
        File::where('id', $file->id)->update(['relation' => 'translation']);

        $this->service->setCanonical($this->resource, $file);

        $this->assertNull($file->fresh()->relation);
    }

    public function test_set_canonical_clears_metadata_when_no_system_files_exist(): void
    {
        $file = $this->makeFile('supporting');
        $this->resource->update(['promoted_file_metadata' => ['Author' => 'Old']]);

        $this->service->setCanonical($this->resource, $file);

        $this->assertSame('canonical', $file->fresh()->role->value);
        $this->assertNull($this->resource->fresh()->promoted_file_metadata);
    }

    public function test_only_one_canonical_exists_after_multiple_set_canonical_calls(): void
    {
        $fileA = $this->makeFile('supporting');
        $fileB = $this->makeFile('supporting');
        $fileC = $this->makeFile('supporting');

        $this->service->setCanonical($this->resource, $fileA);
        $this->service->setCanonical($this->resource, $fileB);
        $this->service->setCanonical($this->resource, $fileC);

        $canonicalCount = File::where('resource_id', $this->resource->id)
            ->where('role', FileRole::CANONICAL->value)
            ->count();

        $this->assertSame(1, $canonicalCount);
        $this->assertSame('canonical', $fileC->fresh()->role->value);
    }
}
