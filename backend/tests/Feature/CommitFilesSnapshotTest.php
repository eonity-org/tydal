<?php

namespace Tests\Feature;

use App\Enums\ResourceState;
use App\Jobs\ExtractEmbeddedPreview;
use App\Models\Collection;
use App\Models\File;
use App\Models\Organization;
use App\Models\Resource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Feature test for POST /api/v1/resources/{id}/commit-files.
 *
 * Reproduces the multi-component upload scenario end-to-end: several PDF components uploaded in
 * parallel (deferred/uncommitted) each auto-claim the snapshot, leaving the resource with more
 * than one starred file. Committing must resolve that to exactly one starred file via
 * ResourceService::ensureSnapshot().
 */
class CommitFilesSnapshotTest extends TestCase
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
        $org = Organization::factory()->create();
        $org->users()->attach($this->user->id, ['role' => 'admin']);
        $this->user->update(['last_organization_id' => $org->id]);

        $collection = Collection::factory()->create([
            'organization_id' => $org->id,
            'user_owner_id' => $this->user->id,
            'index_id' => null,
        ]);

        $this->resource = Resource::factory()->create([
            'collection_id' => $collection->id,
            'organization_id' => $org->id,
            'user_owner_id' => $this->user->id,
            'state' => ResourceState::LIVE->value,
        ]);

        $this->token = $this->user->createToken('test', ['*'], now()->addHour())->plainTextToken;
    }

    public function test_committing_multi_component_uploads_collapses_to_one_snapshot(): void
    {
        Bus::fake();

        // Three uncommitted PDF components belonging to this user, all tagged snapshot — exactly
        // the state several parallel deferred uploads leave behind.
        $ids = [];
        foreach (range(1, 3) as $i) {
            $ids[] = File::factory()->create([
                'resource_id' => $this->resource->id,
                'role' => 'component',
                'mime_type' => 'application/pdf',
                'filename' => "part-{$i}.pdf",
                'usage' => ['snapshot'],
                'is_active' => true,
                'uncommitted_at' => now(),
                'uncommitted_by' => $this->user->id,
                'disk' => 'local',
            ])->id;
        }

        $this->withToken($this->token)
            ->postJson("/api/v1/resources/{$this->resource->id}/commit-files")
            ->assertStatus(200)
            ->assertJsonPath('data.committed', 3);

        // Files are committed and exactly one is starred.
        $committedSnapshots = File::where('resource_id', $this->resource->id)
            ->whereNull('uncommitted_at')
            ->where('is_active', true)
            ->get()
            ->filter(fn (File $f) => $f->isSnapshot());

        $this->assertCount(1, $committedSnapshots, 'exactly one file should remain starred');
        $this->assertSame($ids[0], $committedSnapshots->first()->id, 'earliest-uploaded file wins');

        // A preview render is dispatched for the resolved snapshot.
        Bus::assertDispatched(ExtractEmbeddedPreview::class);
    }
}
