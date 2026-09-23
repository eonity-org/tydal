<?php

namespace Tests\Feature;

use App\Enums\FileRole;
use App\Enums\SystemFilePurpose;
use App\Models\File;
use App\Models\Resource;
use App\Models\SystemFile;
use App\Services\Interfaces\ResourceServiceInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Epic 1.1 — component metadata aggregation: without a canonical, ALL active
 * component files contribute equally, merged in manifest order (position,
 * then upload order); on key conflicts the first contributor wins
 * (Decision A). Supporting files never contribute.
 */
class ComponentMetadataAggregationTest extends TestCase
{
    use RefreshDatabase;

    private ResourceServiceInterface $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ResourceServiceInterface::class);
    }

    private function addTika(Resource $resource, File $file, array $tika): void
    {
        SystemFile::create([
            'resource_id' => $resource->id,
            'source_file_id' => $file->id,
            'purpose' => SystemFilePurpose::EXTRACTED_TEXT,
            'filename' => $file->filename.'.txt.gz',
            'mime_type' => 'application/gzip',
            'size' => 10,
            'path' => 'system/'.$file->id.'.txt.gz',
            'disk' => 'local',
            'is_active' => true,
            'metadata' => ['tika_metadata' => $tika],
        ]);
    }

    public function test_canonical_remains_sole_contributor(): void
    {
        $resource = Resource::factory()->create();
        $canonical = File::factory()->for($resource)->create(['role' => FileRole::CANONICAL]);
        $supporting = File::factory()->for($resource)->create(['role' => FileRole::SUPPORTING]);

        $this->addTika($resource, $canonical, ['title' => 'Canonical Title']);
        $this->addTika($resource, $supporting, ['title' => 'Supporting Title', 'extra' => 'leak']);

        $this->service->recalculatePromotedMetadata($resource);

        $promoted = $resource->fresh()->promoted_file_metadata;
        $this->assertSame('Canonical Title', $promoted['title']);
        $this->assertArrayNotHasKey('extra', $promoted);
    }

    public function test_components_merge_in_position_order_first_wins(): void
    {
        $resource = Resource::factory()->create();
        $second = File::factory()->for($resource)->create([
            'role' => FileRole::COMPONENT, 'position' => 2,
        ]);
        $first = File::factory()->for($resource)->create([
            'role' => FileRole::COMPONENT, 'position' => 1,
        ]);

        $this->addTika($resource, $first, ['title' => 'Letter One', 'author' => 'Ada']);
        $this->addTika($resource, $second, ['title' => 'Letter Two', 'pages' => 4]);

        $this->service->recalculatePromotedMetadata($resource);

        $promoted = $resource->fresh()->promoted_file_metadata;
        $this->assertSame('Letter One', $promoted['title'], 'first-by-position wins the conflict');
        $this->assertSame('Ada', $promoted['author']);
        $this->assertSame(4, $promoted['pages'], 'non-conflicting keys union across components');
    }

    public function test_supporting_never_contributes_in_component_mode(): void
    {
        $resource = Resource::factory()->create();
        $component = File::factory()->for($resource)->create(['role' => FileRole::COMPONENT, 'position' => 1]);
        $supporting = File::factory()->for($resource)->create(['role' => FileRole::SUPPORTING]);

        $this->addTika($resource, $component, ['title' => 'Component']);
        $this->addTika($resource, $supporting, ['leak' => 'nope']);

        $this->service->recalculatePromotedMetadata($resource);

        $promoted = $resource->fresh()->promoted_file_metadata;
        $this->assertSame('Component', $promoted['title']);
        $this->assertArrayNotHasKey('leak', $promoted);
    }

    public function test_no_contributors_clears_promotion(): void
    {
        $resource = Resource::factory()->create();
        $supporting = File::factory()->for($resource)->create(['role' => FileRole::SUPPORTING]);
        $this->addTika($resource, $supporting, ['leak' => 'nope']);

        $resource->update(['promoted_file_metadata' => ['stale' => 'value']]);

        $this->service->recalculatePromotedMetadata($resource);

        $this->assertNull($resource->fresh()->promoted_file_metadata);
    }

    public function test_contributor_ids_order_positions_before_nulls(): void
    {
        $resource = Resource::factory()->create();
        $unpositioned = File::factory()->for($resource)->create([
            'role' => FileRole::COMPONENT, 'position' => null, 'created_at' => now()->subHour(),
        ]);
        $positioned = File::factory()->for($resource)->create([
            'role' => FileRole::COMPONENT, 'position' => 1, 'created_at' => now(),
        ]);

        $ids = $this->service->metadataContributorIds($resource);

        $this->assertSame([$positioned->id, $unpositioned->id], $ids);
    }
}
