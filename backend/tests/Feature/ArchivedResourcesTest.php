<?php

namespace Tests\Feature;

use App\Enums\ResourceState;
use App\Models\Collection;
use App\Models\Organization;
use App\Models\Resource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * GET /resources/archived — the Archived page's listing (#25). Archived
 * resources leave every catalogue, search and vault, so this is the one place
 * they can be found again. Same scope and rule as the trash listing.
 */
class ArchivedResourcesTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Collection $collection;

    protected function setUp(): void
    {
        parent::setUp();

        // ES (re)indexing jobs from the model hooks are not under test.
        Bus::fake();

        $this->organization = Organization::factory()->create();
        $this->collection = Collection::factory()->create([
            'organization_id' => $this->organization->id,
            'user_owner_id' => User::factory()->create()->id,
        ]);
    }

    private function member(string $role, ?Organization $organization = null): User
    {
        $organization ??= $this->organization;
        $user = User::factory()->create();
        $organization->users()->attach($user->id, ['role' => $role]);
        $user->update(['last_organization_id' => $organization->id]);

        return $user;
    }

    private function resource(User $owner, ResourceState $state, array $attributes = []): Resource
    {
        return Resource::factory()
            ->forCollection($attributes['collection_id'] ?? $this->collection->id)
            ->create(array_merge([
                'organization_id' => $this->organization->id,
                'user_owner_id' => $owner->id,
                'state' => $state,
            ], $attributes));
    }

    /** @return array<int, string> */
    private function listedIds(User $user): array
    {
        $token = $user->createToken('auth-token', ['*'], now()->addHour())->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/v1/resources/archived');
        $response->assertStatus(200)->assertJson(['success' => true]);

        return collect($response->json('data'))->pluck('id')->all();
    }

    public function test_lists_only_archived_resources_not_live_draft_or_deleted(): void
    {
        $admin = $this->member('admin');

        $archived = $this->resource($admin, ResourceState::ARCHIVED);
        $this->resource($admin, ResourceState::LIVE);
        $this->resource($admin, ResourceState::DRAFT);
        $this->resource($admin, ResourceState::ARCHIVED)->delete(); // archived, then trashed

        $this->assertSame([$archived->id], $this->listedIds($admin));
    }

    public function test_editor_sees_only_their_own_archived_resources(): void
    {
        $editor = $this->member('editor');
        $colleague = $this->member('editor');

        $own = $this->resource($editor, ResourceState::ARCHIVED);
        $this->resource($colleague, ResourceState::ARCHIVED);

        $this->assertSame([$own->id], $this->listedIds($editor));
    }

    public function test_admin_sees_every_archived_resource_of_the_organization(): void
    {
        $admin = $this->member('admin');
        $editor = $this->member('editor');

        $ids = [
            $this->resource($admin, ResourceState::ARCHIVED)->id,
            $this->resource($editor, ResourceState::ARCHIVED)->id,
        ];

        $this->assertEqualsCanonicalizing($ids, $this->listedIds($admin));
    }

    public function test_viewer_may_list_their_own_archived_resources(): void
    {
        $viewer = $this->member('viewer');
        $own = $this->resource($viewer, ResourceState::ARCHIVED);

        $this->assertSame([$own->id], $this->listedIds($viewer));
    }

    public function test_another_organizations_archived_resources_never_appear(): void
    {
        $admin = $this->member('admin');

        $other = Organization::factory()->create();
        $otherCollection = Collection::factory()->create([
            'organization_id' => $other->id,
            'user_owner_id' => $admin->id,
        ]);
        $this->resource($admin, ResourceState::ARCHIVED, [
            'organization_id' => $other->id,
            'collection_id' => $otherCollection->id,
        ]);

        $own = $this->resource($admin, ResourceState::ARCHIVED);

        $this->assertSame([$own->id], $this->listedIds($admin));
    }

    // ── archived_at ────────────────────────────────────────────────────────

    public function test_archiving_through_an_update_stamps_archived_at_and_setting_live_clears_it(): void
    {
        $admin = $this->member('admin');
        $resource = $this->resource($admin, ResourceState::LIVE);
        $this->assertNull($resource->archived_at);

        $this->travelTo(now()->startOfSecond());
        $resource->update(['state' => ResourceState::ARCHIVED]);
        $this->assertEquals(now(), $resource->fresh()->archived_at);

        // Another write while archived leaves the stamp alone.
        $this->travel(5)->minutes();
        $resource->fresh()->update(['name' => 'Renamed while archived']);
        $this->assertEquals(now()->subMinutes(5), $resource->fresh()->archived_at);

        $resource->fresh()->update(['state' => ResourceState::LIVE]);
        $this->assertNull($resource->fresh()->archived_at);
    }

    public function test_creating_an_archived_resource_stamps_archived_at(): void
    {
        $admin = $this->member('admin');

        $this->assertNotNull($this->resource($admin, ResourceState::ARCHIVED)->fresh()->archived_at);
        $this->assertNull($this->resource($admin, ResourceState::DRAFT)->fresh()->archived_at);
    }

    public function test_bulk_state_stamps_and_clears_archived_at(): void
    {
        $admin = $this->member('admin');
        $token = $admin->createToken('auth-token', ['*'], now()->addHour())->plainTextToken;
        $resource = $this->resource($admin, ResourceState::LIVE);

        $this->withToken($token)
            ->postJson('/api/v1/resources/bulk/state', ['resource_ids' => [$resource->id], 'state' => 'archived'])
            ->assertOk();
        $this->assertNotNull($resource->fresh()->archived_at);

        $this->withToken($token)
            ->postJson('/api/v1/resources/bulk/state', ['resource_ids' => [$resource->id], 'state' => 'live'])
            ->assertOk();
        $this->assertNull($resource->fresh()->archived_at);
    }

    public function test_listing_sorts_by_archived_at_by_default_not_updated_at(): void
    {
        $admin = $this->member('admin');

        // Archived first but touched most recently: updated_at would put it first.
        $older = $this->resource($admin, ResourceState::ARCHIVED, ['archived_at' => now()->subDays(3)]);
        $newer = $this->resource($admin, ResourceState::ARCHIVED, ['archived_at' => now()->subDay()]);
        $older->forceFill(['updated_at' => now()->addMinute()])->saveQuietly();

        $this->assertSame([$newer->id, $older->id], $this->listedIds($admin));

        $token = $admin->createToken('auth-token', ['*'], now()->addHour())->plainTextToken;
        $asc = $this->withToken($token)
            ->getJson('/api/v1/resources/archived?sort_by=archived_at&sort_dir=asc')
            ->assertOk()
            ->json('data');
        $this->assertSame([$older->id, $newer->id], array_column($asc, 'id'));
        $this->assertNotNull($asc[0]['archived_at']);

        // updated_at is still accepted, for older clients.
        $byUpdated = $this->withToken($token)
            ->getJson('/api/v1/resources/archived?sort_by=updated_at')
            ->assertOk()
            ->json('data');
        $this->assertSame([$older->id, $newer->id], array_column($byUpdated, 'id'));
    }

    public function test_migration_backfills_archived_rows_from_updated_at_and_reruns_safely(): void
    {
        $this->assertTrue(Schema::hasColumn('resources', 'archived_at'));

        $admin = $this->member('admin');
        $archived = $this->resource($admin, ResourceState::ARCHIVED);
        $live = $this->resource($admin, ResourceState::LIVE);

        // As an archived row from before the column existed looks.
        DB::table('resources')->where('id', $archived->id)
            ->update(['archived_at' => null, 'updated_at' => '2026-09-01 12:00:00']);

        $migration = require database_path('migrations/2026_10_06_000000_add_archived_at_to_resources.php');
        $migration->up(); // column already there: guarded, only the backfill runs

        $this->assertSame('2026-09-01 12:00:00', DB::table('resources')->where('id', $archived->id)->value('archived_at'));
        $this->assertNull(DB::table('resources')->where('id', $live->id)->value('archived_at'));
    }

    public function test_requires_authentication(): void
    {
        $this->getJson('/api/v1/resources/archived')->assertStatus(401);
    }
}
