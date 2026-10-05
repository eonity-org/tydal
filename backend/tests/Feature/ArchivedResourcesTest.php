<?php

namespace Tests\Feature;

use App\Enums\ResourceState;
use App\Models\Collection;
use App\Models\Organization;
use App\Models\Resource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
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

    public function test_requires_authentication(): void
    {
        $this->getJson('/api/v1/resources/archived')->assertStatus(401);
    }
}
