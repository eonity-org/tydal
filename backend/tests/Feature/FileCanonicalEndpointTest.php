<?php

namespace Tests\Feature;

use App\Enums\FileRole;
use App\Enums\ResourceState;
use App\Enums\SystemFilePurpose;
use App\Models\Collection;
use App\Models\File;
use App\Models\Organization;
use App\Models\Resource;
use App\Models\SystemFile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Feature tests for PATCH /api/v1/resources/{resourceId}/files/{fileId}/canonical
 *
 * Verifies HTTP-level authorization, DB persistence, the single-canonical-per-resource
 * constraint, and the recalculation of Resource.promoted_file_metadata after promotion.
 */
class FileCanonicalEndpointTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Organization $organization;

    private Resource $resource;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->user = User::factory()->create();
        $this->organization = Organization::factory()->create();

        $this->organization->users()->attach($this->user->id, ['role' => 'admin']);
        $this->user->update(['last_organization_id' => $this->organization->id]);

        $collection = Collection::factory()->create([
            'organization_id' => $this->organization->id,
            'user_owner_id' => $this->user->id,
            'index_id' => null,
        ]);

        $this->resource = Resource::factory()->create([
            'collection_id' => $collection->id,
            'organization_id' => $this->organization->id,
            'user_owner_id' => $this->user->id,
            'state' => ResourceState::LIVE->value,
        ]);

        $this->token = $this->user->createToken('test', ['*'], now()->addHour())->plainTextToken;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeFile(string $role = 'supporting'): File
    {
        return File::factory()->create([
            'resource_id' => $this->resource->id,
            'role' => $role,
            'is_active' => true,
            'disk' => 'local',
        ]);
    }

    private function patchCanonical(string $resourceId, string $fileId): TestResponse
    {
        return $this->withToken($this->token)
            ->patchJson("/api/v1/resources/{$resourceId}/files/{$fileId}/canonical");
    }

    // =========================================================================
    // Authorization and routing
    // =========================================================================

    public function test_unauthenticated_request_is_rejected(): void
    {
        $file = $this->makeFile();

        $this->patchJson(
            "/api/v1/resources/{$this->resource->id}/files/{$file->id}/canonical"
        )->assertStatus(401);
    }

    public function test_returns_404_when_resource_not_found(): void
    {
        $file = $this->makeFile();

        $this->patchCanonical('00000000-0000-0000-0000-000000000000', $file->id)
            ->assertStatus(404);
    }

    public function test_returns_404_when_file_not_found_on_resource(): void
    {
        $otherResource = Resource::factory()->create([
            'collection_id' => $this->resource->collection_id,
            'organization_id' => $this->organization->id,
            'user_owner_id' => $this->user->id,
        ]);
        $otherFile = File::factory()->create([
            'resource_id' => $otherResource->id,
            'disk' => 'local',
        ]);

        $this->patchCanonical($this->resource->id, $otherFile->id)
            ->assertStatus(404);
    }

    // =========================================================================
    // Happy path — canonical promotion
    // =========================================================================

    public function test_sets_file_role_to_canonical_and_returns_file(): void
    {
        $file = $this->makeFile('supporting');

        $response = $this->patchCanonical($this->resource->id, $file->id);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('file.id', $file->id)
            ->assertJsonPath('file.role', 'canonical');

        $this->assertSame('canonical', $file->fresh()->role->value);
    }

    public function test_setting_canonical_demotes_previous_canonical_to_supporting(): void
    {
        $firstCanonical = $this->makeFile('canonical');
        $secondFile = $this->makeFile('supporting');

        $this->patchCanonical($this->resource->id, $secondFile->id)
            ->assertStatus(200);

        $this->assertSame('supporting', $firstCanonical->fresh()->role->value);
        $this->assertSame('canonical', $secondFile->fresh()->role->value);
    }

    public function test_only_one_canonical_exists_after_multiple_promotions(): void
    {
        $fileA = $this->makeFile('supporting');
        $fileB = $this->makeFile('supporting');
        $fileC = $this->makeFile('supporting');

        $this->patchCanonical($this->resource->id, $fileA->id)->assertStatus(200);
        $this->patchCanonical($this->resource->id, $fileB->id)->assertStatus(200);
        $this->patchCanonical($this->resource->id, $fileC->id)->assertStatus(200);

        $canonicalCount = File::where('resource_id', $this->resource->id)
            ->where('role', FileRole::CANONICAL->value)
            ->count();

        $this->assertSame(1, $canonicalCount);
        $this->assertSame('canonical', $fileC->fresh()->role->value);
    }

    public function test_canonical_file_has_null_relation(): void
    {
        $file = $this->makeFile('supporting');
        File::where('id', $file->id)->update(['relation' => 'translation']);

        $this->patchCanonical($this->resource->id, $file->id)->assertStatus(200);

        $this->assertNull($file->fresh()->relation);
    }

    // =========================================================================
    // Metadata recalculation via HTTP
    // =========================================================================

    public function test_endpoint_recalculates_promoted_file_metadata_on_canonical_change(): void
    {
        $file = $this->makeFile('supporting');

        SystemFile::create([
            'resource_id' => $this->resource->id,
            'source_file_id' => $file->id,
            'purpose' => SystemFilePurpose::TIKA_METADATA->value,
            'filename' => 'meta.json',
            'mime_type' => 'application/json',
            'size' => 50,
            'path' => 'meta/resource.json',
            'disk' => 'local',
            'is_active' => true,
            'metadata' => ['tika_metadata' => ['Author' => 'Via HTTP']],
        ]);

        $this->patchCanonical($this->resource->id, $file->id)
            ->assertStatus(200);

        $this->assertSame('Via HTTP', $this->resource->fresh()->promoted_file_metadata['Author']);
    }

    public function test_endpoint_clears_promoted_metadata_when_no_system_files_exist(): void
    {
        $file = $this->makeFile('supporting');
        $this->resource->update(['promoted_file_metadata' => ['Author' => 'Old']]);

        $this->patchCanonical($this->resource->id, $file->id)
            ->assertStatus(200);

        $this->assertNull($this->resource->fresh()->promoted_file_metadata);
    }
}
