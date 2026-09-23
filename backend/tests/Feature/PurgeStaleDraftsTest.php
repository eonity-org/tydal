<?php

namespace Tests\Feature;

use App\Enums\ResourceState;
use App\Models\Collection;
use App\Models\Organization;
use App\Models\Resource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Verifies the abandoned-draft reaper (resources:purge-drafts):
 *   - hard-deletes draft resources untouched past the TTL,
 *   - leaves recent drafts and non-draft resources alone,
 *   - --dry-run deletes nothing.
 *
 * Resources are created in a collection without a SearchIndex so the synchronous
 * ES index/delete boot hooks return early (see ElasticsearchService::deleteResource).
 */
class PurgeStaleDraftsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('local');
    }

    private function makeResource(ResourceState $state, int $updatedHoursAgo): Resource
    {
        $resource = Resource::factory()->create([
            'organization_id' => Organization::factory(),
            'collection_id' => Collection::factory(), // no SearchIndex → ES hooks no-op
            'user_owner_id' => User::factory(),
            'state' => $state,
        ]);

        // Bypass Eloquent's auto-touch so updated_at lands in the past.
        DB::table($resource->getTable())
            ->where('id', $resource->id)
            ->update(['updated_at' => now()->subHours($updatedHoursAgo)]);

        return $resource->fresh();
    }

    public function test_purges_draft_untouched_past_ttl(): void
    {
        $stale = $this->makeResource(ResourceState::DRAFT, 2);

        // Seed the on-disk layout a real draft leaves behind (see the manual `ls`):
        //   public disk → {id}/media/*   (Spatie media)
        //   local disk  → {id}/archives/*.json  (AITY suggestion archives)
        $mediaDisk = config('media-library.disk_name', 'public');
        Storage::disk($mediaDisk)->put("{$stale->id}/media/1/asset.jpg", 'bytes');
        Storage::disk('local')->put("{$stale->id}/archives/{$stale->id}_suggested_name.json", '{}');

        $this->artisan('resources:purge-drafts', ['--older-than' => 1])
            ->assertSuccessful();

        // Hard-deleted — not even soft-deleted/trashed.
        $this->assertNull(Resource::withTrashed()->find($stale->id));
        // Both storage dirs swept.
        $this->assertFalse(Storage::disk($mediaDisk)->exists("{$stale->id}/media/1/asset.jpg"));
        $this->assertFalse(Storage::disk($mediaDisk)->exists($stale->id));
        $this->assertFalse(Storage::disk('local')->exists($stale->id));
    }

    public function test_keeps_recent_draft(): void
    {
        $recent = $this->makeResource(ResourceState::DRAFT, 0); // updated just now

        $this->artisan('resources:purge-drafts', ['--older-than' => 1])
            ->assertSuccessful();

        $this->assertNotNull(Resource::find($recent->id));
    }

    public function test_keeps_non_draft_even_when_old(): void
    {
        $published = $this->makeResource(ResourceState::LIVE, 5);

        $this->artisan('resources:purge-drafts', ['--older-than' => 1])
            ->assertSuccessful();

        $this->assertNotNull(Resource::find($published->id));
    }

    public function test_dry_run_deletes_nothing(): void
    {
        $stale = $this->makeResource(ResourceState::DRAFT, 2);

        $this->artisan('resources:purge-drafts', ['--older-than' => 1, '--dry-run' => true])
            ->assertSuccessful();

        $this->assertNotNull(Resource::find($stale->id));
    }
}
