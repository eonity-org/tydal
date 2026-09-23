<?php

namespace Tests\Feature;

use App\Enums\ResourceState;
use App\Enums\SystemFilePurpose;
use App\Models\Collection;
use App\Models\File;
use App\Models\FileChunk;
use App\Models\Organization;
use App\Models\Resource;
use App\Models\SystemFile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Feature tests for DELETE /api/v1/resources/{resourceId}/files/{fileId} (removeFile).
 *
 * Focus: when a file is removed, its derived artifacts must be purged too. The
 * system_files.source_file_id FK is nullOnDelete, so without explicit cleanup the
 * AITY suggestion / extracted-text archives are orphaned on disk and in the DB.
 */
class RemoveFileArtifactCleanupTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Resource $resource;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->user = User::factory()->create();
        $organization = Organization::factory()->create();

        $organization->users()->attach($this->user->id, ['role' => 'admin']);
        $this->user->update(['last_organization_id' => $organization->id]);

        $collection = Collection::factory()->create([
            'organization_id' => $organization->id,
            'user_owner_id' => $this->user->id,
            'index_id' => null,
        ]);

        $this->resource = Resource::factory()->create([
            'collection_id' => $collection->id,
            'organization_id' => $organization->id,
            'user_owner_id' => $this->user->id,
            'state' => ResourceState::LIVE->value,
        ]);

        $this->token = $this->user->createToken('test', ['*'], now()->addHour())->plainTextToken;
    }

    private function makeFile(): File
    {
        return File::factory()->create([
            'resource_id' => $this->resource->id,
            'is_active' => true,
            'disk' => 'local',
        ]);
    }

    /**
     * Create a SystemFile row with a matching on-disk archive blob.
     */
    private function makeArchive(File $file, SystemFilePurpose $purpose, string $suffix): SystemFile
    {
        $path = "{$this->resource->id}/archives/{$file->id}_{$suffix}.json";
        Storage::disk('local')->put($path, json_encode(['value' => 'x']));

        return SystemFile::create([
            'resource_id' => $this->resource->id,
            'source_file_id' => $file->id,
            'purpose' => $purpose->value,
            'filename' => "{$suffix}.json",
            'mime_type' => 'application/json',
            'size' => 12,
            'path' => $path,
            'disk' => 'local',
            'is_active' => true,
        ]);
    }

    private function removeFile(string $resourceId, string $fileId): TestResponse
    {
        return $this->withToken($this->token)
            ->deleteJson("/api/v1/resources/{$resourceId}/files/{$fileId}");
    }

    public function test_removing_file_deletes_its_suggestion_archives_from_disk_and_db(): void
    {
        $file = $this->makeFile();

        $name = $this->makeArchive($file, SystemFilePurpose::AI_SUGGESTED_NAME, 'suggested_name');
        $desc = $this->makeArchive($file, SystemFilePurpose::AI_SUGGESTED_DESCRIPTION, 'suggested_description');
        $tags = $this->makeArchive($file, SystemFilePurpose::AI_SUGGESTED_TAGS, 'suggested_tags');

        $this->removeFile($this->resource->id, $file->id)->assertStatus(200);

        // On-disk archives are gone
        Storage::disk('local')->assertMissing($name->path);
        Storage::disk('local')->assertMissing($desc->path);
        Storage::disk('local')->assertMissing($tags->path);

        // DB rows are gone (not merely orphaned with source_file_id = null)
        $this->assertDatabaseMissing('system_files', ['id' => $name->id]);
        $this->assertDatabaseMissing('system_files', ['id' => $desc->id]);
        $this->assertDatabaseMissing('system_files', ['id' => $tags->id]);
    }

    public function test_removing_file_deletes_associated_file_chunks(): void
    {
        $file = $this->makeFile();
        $archive = $this->makeArchive($file, SystemFilePurpose::EXTRACTED_TEXT, 'extracted');

        $chunk = FileChunk::create([
            'resource_id' => $this->resource->id,
            'source_file_id' => $file->id,
            'archive_file_id' => $archive->id,
            'sequence' => 0,
            'word_count' => 3,
        ]);

        $this->removeFile($this->resource->id, $file->id)->assertStatus(200);

        $this->assertDatabaseMissing('file_chunks', ['id' => $chunk->id]);
        $this->assertDatabaseMissing('system_files', ['id' => $archive->id]);
    }

    public function test_removing_file_leaves_other_files_artifacts_intact(): void
    {
        $fileA = $this->makeFile();
        $fileB = $this->makeFile();

        $archiveA = $this->makeArchive($fileA, SystemFilePurpose::AI_SUGGESTED_NAME, 'suggested_name');
        $archiveB = $this->makeArchive($fileB, SystemFilePurpose::AI_SUGGESTED_NAME, 'suggested_name');

        $this->removeFile($this->resource->id, $fileA->id)->assertStatus(200);

        $this->assertDatabaseMissing('system_files', ['id' => $archiveA->id]);
        Storage::disk('local')->assertMissing($archiveA->path);

        $this->assertDatabaseHas('system_files', ['id' => $archiveB->id]);
        Storage::disk('local')->assertExists($archiveB->path);
    }
}
