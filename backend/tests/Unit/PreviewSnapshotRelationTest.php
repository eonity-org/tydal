<?php

namespace Tests\Unit;

use App\Enums\SystemFilePurpose;
use App\Models\Collection;
use App\Models\Organization;
use App\Models\Resource;
use App\Models\SystemFile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Regression test for Resource::previewSnapshotSystemFile().
 *
 * The relation used latestOfMany(), whose inner MAX-aggregate subquery ignores the chained
 * where('purpose'|'is_active') clauses. It therefore keyed off the resource's globally-latest
 * SystemFile — typically an ai_suggested_* row created AFTER the preview — which the outer
 * purpose filter then rejected, resolving the relation (and preview_snapshot_url) to null even
 * though a valid, active preview existed. Symptom: PDF cards showed no preview in Chrome.
 */
class PreviewSnapshotRelationTest extends TestCase
{
    use RefreshDatabase;

    public function test_preview_relation_resolves_even_when_newer_systemfiles_exist(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $collection = Collection::factory()->create([
            'organization_id' => $org->id,
            'user_owner_id' => $user->id,
            'index_id' => null,
        ]);
        $resource = Resource::factory()->create([
            'collection_id' => $collection->id,
            'organization_id' => $org->id,
            'user_owner_id' => $user->id,
        ]);

        // 1) The preview snapshot is created first.
        $preview = SystemFile::create([
            'resource_id' => $resource->id,
            'purpose' => SystemFilePurpose::PREVIEW_SNAPSHOT->value,
            'filename' => 'preview-snapshot.jpg',
            'mime_type' => 'image/jpeg',
            'size' => 1000,
            'path' => "{$resource->id}/system/preview-snapshot.jpg",
            'disk' => 'public',
            'is_active' => true,
        ]);
        $preview->forceFill(['updated_at' => now()->subMinutes(2)])->saveQuietly();

        // 2) Later, higher-id / newer systemfiles of OTHER purposes are created (extraction +
        //    AI suggestions) — exactly the ordering the real pipeline produces.
        foreach ([SystemFilePurpose::EXTRACTED_TEXT, SystemFilePurpose::AI_SUGGESTED_NAME, SystemFilePurpose::AI_SUGGESTED_TAGS] as $purpose) {
            $sf = SystemFile::create([
                'resource_id' => $resource->id,
                'purpose' => $purpose->value,
                'filename' => $purpose->value.'.json',
                'mime_type' => 'application/json',
                'size' => 10,
                'path' => "{$resource->id}/system/{$purpose->value}.json",
                'disk' => 'public',
                'is_active' => true,
            ]);
            $sf->forceFill(['updated_at' => now()])->saveQuietly();
        }

        // Eager-load like show()/catalogue do, then read what the frontend actually receives.
        $fresh = Resource::with('previewSnapshotSystemFile')->find($resource->id);

        $this->assertNotNull($fresh->previewSnapshotSystemFile, 'preview relation must resolve');
        $this->assertSame($preview->id, $fresh->previewSnapshotSystemFile->id);
        $this->assertNotNull($fresh->preview_snapshot_url, 'preview_snapshot_url must be populated');
        $this->assertStringContainsString('preview-snapshot.jpg', $fresh->preview_snapshot_url);
    }
}
