<?php

namespace Tests\Feature;

use App\Enums\ResourceState;
use App\Jobs\SyncResourcesToElasticsearch;
use App\Models\Collection;
use App\Models\Organization;
use App\Models\Resource;
use App\Models\SemanticTag;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The dashboard basket's bulk actions: workspace membership, lifecycle state
 * and semantic tagging over a set of resources.
 */
class BulkResourceActionsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Organization $organization;

    private Collection $collection;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->organization = Organization::factory()->create();
        $this->collection = Collection::factory()->create([
            'organization_id' => $this->organization->id,
            'user_owner_id' => $this->user->id,
        ]);

        $this->organization->users()->attach($this->user->id, ['role' => 'admin']);
        $this->user->update(['last_organization_id' => $this->organization->id]);

        $this->token = $this->user->createToken('auth-token', ['*'], now()->addHour())->plainTextToken;

        // The reindex is queued, not run — assert on dispatch, not on ES.
        Bus::fake();
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, resource> */
    private function makeResources(int $count, array $attributes = [])
    {
        return Resource::factory()
            ->forCollection($this->collection->id)
            ->count($count)
            ->create(array_merge([
                'organization_id' => $this->organization->id,
                'user_owner_id' => $this->user->id,
            ], $attributes));
    }

    private function workspace(array $attributes = []): Workspace
    {
        return Workspace::factory()->create(array_merge([
            'organization_id' => $this->organization->id,
            'user_owner_id' => $this->user->id,
        ], $attributes));
    }

    // ---------------------------------------------------------------- workspace

    public function test_bulk_attach_adds_every_resource_to_the_workspace(): void
    {
        $workspace = $this->workspace();
        $resources = $this->makeResources(3);
        $ids = $resources->pluck('id')->all();

        $response = $this->withToken($this->token)
            ->postJson("/api/v1/workspaces/{$workspace->id}/resources/bulk-attach", [
                'resource_ids' => $ids,
            ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true, 'requested' => 3, 'applied' => 3, 'skipped' => []]);

        $this->assertEqualsCanonicalizing($ids, $workspace->resources()->pluck('resources.id')->all());
        Bus::assertDispatchedSync(SyncResourcesToElasticsearch::class);
    }

    public function test_bulk_attach_is_idempotent(): void
    {
        $workspace = $this->workspace();
        $ids = $this->makeResources(2)->pluck('id')->all();

        foreach (range(1, 2) as $_) {
            $this->withToken($this->token)
                ->postJson("/api/v1/workspaces/{$workspace->id}/resources/bulk-attach", ['resource_ids' => $ids])
                ->assertStatus(200);
        }

        $this->assertCount(2, $workspace->resources()->get());
    }

    public function test_bulk_detach_removes_membership(): void
    {
        $workspace = $this->workspace();
        $resources = $this->makeResources(3);
        $workspace->resources()->sync($resources->pluck('id')->all());

        $goneIds = $resources->take(2)->pluck('id')->all();

        $this->withToken($this->token)
            ->postJson("/api/v1/workspaces/{$workspace->id}/resources/bulk-detach", ['resource_ids' => $goneIds])
            ->assertStatus(200)
            ->assertJson(['applied' => 2]);

        $this->assertEquals(
            [$resources->last()->id],
            $workspace->resources()->pluck('resources.id')->all()
        );
    }

    public function test_bulk_attach_skips_resources_from_another_organization(): void
    {
        $workspace = $this->workspace();
        $mine = $this->makeResources(2);

        $otherOrg = Organization::factory()->create();
        $otherCollection = Collection::factory()->create([
            'organization_id' => $otherOrg->id,
            'user_owner_id' => $this->user->id,
        ]);
        $foreign = Resource::factory()->forCollection($otherCollection->id)->create([
            'organization_id' => $otherOrg->id,
            'user_owner_id' => $this->user->id,
        ]);

        $response = $this->withToken($this->token)
            ->postJson("/api/v1/workspaces/{$workspace->id}/resources/bulk-attach", [
                'resource_ids' => [...$mine->pluck('id')->all(), $foreign->id],
            ]);

        // Partial success: the two legitimate ones land, the foreign one is
        // reported back rather than failing the whole request.
        $response->assertStatus(200)
            ->assertJson([
                'requested' => 3,
                'applied' => 2,
                'skipped' => [['id' => $foreign->id, 'reason' => 'foreign_organization']],
            ]);

        $this->assertCount(2, $workspace->resources()->get());
    }

    public function test_bulk_attach_refuses_the_default_workspace(): void
    {
        $workspace = $this->workspace(['is_default' => true]);

        $this->withToken($this->token)
            ->postJson("/api/v1/workspaces/{$workspace->id}/resources/bulk-attach", [
                'resource_ids' => $this->makeResources(1)->pluck('id')->all(),
            ])
            ->assertStatus(422);
    }

    public function test_bulk_action_is_capped(): void
    {
        $workspace = $this->workspace();
        $ids = array_map(fn () => (string) Str::orderedUuid(), range(1, 201));

        $this->withToken($this->token)
            ->postJson("/api/v1/workspaces/{$workspace->id}/resources/bulk-attach", ['resource_ids' => $ids])
            ->assertStatus(422)
            ->assertJsonValidationErrors('resource_ids');
    }

    // ------------------------------------------------------------- index timing

    public function test_a_small_batch_reindexes_inline_so_the_caller_can_refetch(): void
    {
        $workspace = $this->workspace();
        $ids = $this->makeResources(3)->pluck('id')->all();

        $this->withToken($this->token)
            ->postJson("/api/v1/workspaces/{$workspace->id}/resources/bulk-attach", ['resource_ids' => $ids])
            ->assertStatus(200)
            ->assertJson(['indexing' => 'immediate']);

        Bus::assertDispatchedSync(SyncResourcesToElasticsearch::class);
    }

    public function test_a_batch_over_the_threshold_is_queued_and_says_so(): void
    {
        $workspace = $this->workspace();
        $ids = $this->makeResources(SyncResourcesToElasticsearch::SYNC_THRESHOLD + 1)->pluck('id')->all();

        // The dashboard refetches straight after the response, so it needs to
        // know when that refetch will race the reindex.
        $this->withToken($this->token)
            ->postJson("/api/v1/workspaces/{$workspace->id}/resources/bulk-attach", ['resource_ids' => $ids])
            ->assertStatus(200)
            ->assertJson(['indexing' => 'queued']);

        Bus::assertDispatched(SyncResourcesToElasticsearch::class);
    }

    // -------------------------------------------------------------------- state

    public function test_bulk_state_moves_every_resource(): void
    {
        $resources = $this->makeResources(3, ['state' => ResourceState::DRAFT->value]);

        $this->withToken($this->token)
            ->postJson('/api/v1/resources/bulk/state', [
                'resource_ids' => $resources->pluck('id')->all(),
                'state' => 'live',
            ])
            ->assertStatus(200)
            ->assertJson(['applied' => 3]);

        foreach ($resources as $resource) {
            $this->assertEquals(ResourceState::LIVE, $resource->fresh()->state);
        }

        Bus::assertDispatchedSync(SyncResourcesToElasticsearch::class);
    }

    public function test_bulk_state_to_archived_queues_a_sync_that_removes_the_documents(): void
    {
        $resources = $this->makeResources(2, ['state' => ResourceState::LIVE->value]);
        $ids = $resources->pluck('id')->all();

        $this->withToken($this->token)
            ->postJson('/api/v1/resources/bulk/state', ['resource_ids' => $ids, 'state' => 'archived'])
            ->assertStatus(200);

        foreach ($resources as $resource) {
            $this->assertFalse($resource->fresh()->state->isVisible());
        }

        // The job syncs rather than indexes, so the archived resources are the
        // ones it will delete — the direction is decided from their state.
        Bus::assertDispatchedSync(
            SyncResourcesToElasticsearch::class,
            fn (SyncResourcesToElasticsearch $job) => empty(array_diff($ids, $job->getResourceIds()))
        );
    }

    public function test_bulk_state_records_a_state_changed_event(): void
    {
        $resource = $this->makeResources(1, ['state' => ResourceState::DRAFT->value])->first();

        $this->withToken($this->token)
            ->postJson('/api/v1/resources/bulk/state', [
                'resource_ids' => [$resource->id],
                'state' => 'live',
            ])
            ->assertStatus(200);

        $this->assertDatabaseHas('resource_events', [
            'resource_id' => $resource->id,
            'event_type' => 'state_changed',
        ]);
    }

    /**
     * The acting user is an org admin (see setUp), and administrators may act
     * on anything in their organization — authorship protects a resource from
     * peers, not from the people who run the organization.
     */
    public function test_bulk_state_covers_resources_an_admin_does_not_own(): void
    {
        $mine = $this->makeResources(1, ['state' => ResourceState::DRAFT->value])->first();

        $someoneElse = User::factory()->create();
        $theirs = Resource::factory()->forCollection($this->collection->id)->create([
            'organization_id' => $this->organization->id,
            'user_owner_id' => $someoneElse->id,
            'state' => ResourceState::DRAFT->value,
        ]);

        $this->withToken($this->token)
            ->postJson('/api/v1/resources/bulk/state', [
                'resource_ids' => [$mine->id, $theirs->id],
                'state' => 'live',
            ])
            ->assertStatus(200)
            ->assertJson(['applied' => 2, 'skipped' => []]);

        $this->assertEquals(ResourceState::LIVE, $mine->fresh()->state);
        $this->assertEquals(ResourceState::LIVE, $theirs->fresh()->state);
    }

    /** An editor's reach stops at their own work — the other half of that rule. */
    public function test_bulk_state_skips_resources_an_editor_does_not_own(): void
    {
        $editor = User::factory()->create();
        $this->organization->users()->attach($editor->id, ['role' => 'editor']);
        $editor->update(['last_organization_id' => $this->organization->id]);
        $editorToken = $editor->createToken('auth-token', ['*'], now()->addHour())->plainTextToken;

        $mine = Resource::factory()->forCollection($this->collection->id)->create([
            'organization_id' => $this->organization->id,
            'user_owner_id' => $editor->id,
            'state' => ResourceState::DRAFT->value,
        ]);
        $theirs = Resource::factory()->forCollection($this->collection->id)->create([
            'organization_id' => $this->organization->id,
            'user_owner_id' => $this->user->id,
            'state' => ResourceState::DRAFT->value,
        ]);

        $this->withToken($editorToken)
            ->postJson('/api/v1/resources/bulk/state', [
                'resource_ids' => [$mine->id, $theirs->id],
                'state' => 'live',
            ])
            ->assertStatus(200)
            ->assertJson([
                'applied' => 1,
                'skipped' => [['id' => $theirs->id, 'reason' => 'forbidden']],
            ]);

        $this->assertEquals(ResourceState::LIVE, $mine->fresh()->state);
        $this->assertEquals(ResourceState::DRAFT, $theirs->fresh()->state);
    }

    public function test_bulk_state_rejects_an_unknown_state(): void
    {
        $this->withToken($this->token)
            ->postJson('/api/v1/resources/bulk/state', [
                'resource_ids' => $this->makeResources(1)->pluck('id')->all(),
                'state' => 'published',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('state');
    }

    // --------------------------------------------------------------------- tags

    public function test_bulk_tag_add_preserves_tags_the_resources_already_carry(): void
    {
        $existing = SemanticTag::factory()->create(['organization_id' => $this->organization->id]);
        $incoming = SemanticTag::factory()->create(['organization_id' => $this->organization->id]);

        $resources = $this->makeResources(2);
        foreach ($resources as $resource) {
            $resource->semanticTags()->sync([$existing->id]);
        }

        $this->withToken($this->token)
            ->postJson('/api/v1/resources/bulk/semantic-tags', [
                'resource_ids' => $resources->pluck('id')->all(),
                'tag_ids' => [$incoming->id],
                'mode' => 'add',
            ])
            ->assertStatus(200)
            ->assertJson(['applied' => 2]);

        // The regression a sync() would cause: the pre-existing tag survives.
        foreach ($resources as $resource) {
            $this->assertEqualsCanonicalizing(
                [$existing->id, $incoming->id],
                $resource->fresh()->semanticTags->pluck('id')->all()
            );
        }
    }

    public function test_bulk_tag_remove_detaches_only_the_named_tags(): void
    {
        $keep = SemanticTag::factory()->create(['organization_id' => $this->organization->id]);
        $drop = SemanticTag::factory()->create(['organization_id' => $this->organization->id]);

        $resource = $this->makeResources(1)->first();
        $resource->semanticTags()->sync([$keep->id, $drop->id]);

        $this->withToken($this->token)
            ->postJson('/api/v1/resources/bulk/semantic-tags', [
                'resource_ids' => [$resource->id],
                'tag_ids' => [$drop->id],
                'mode' => 'remove',
            ])
            ->assertStatus(200);

        $this->assertEquals([$keep->id], $resource->fresh()->semanticTags->pluck('id')->all());
    }

    public function test_bulk_tag_refuses_tags_from_another_organization(): void
    {
        $foreignTag = SemanticTag::factory()->create([
            'organization_id' => Organization::factory()->create()->id,
        ]);

        $this->withToken($this->token)
            ->postJson('/api/v1/resources/bulk/semantic-tags', [
                'resource_ids' => $this->makeResources(1)->pluck('id')->all(),
                'tag_ids' => [$foreignTag->id],
                'mode' => 'add',
            ])
            ->assertStatus(422);
    }

    public function test_bulk_tag_rejects_an_unknown_mode(): void
    {
        $tag = SemanticTag::factory()->create(['organization_id' => $this->organization->id]);

        $this->withToken($this->token)
            ->postJson('/api/v1/resources/bulk/semantic-tags', [
                'resource_ids' => $this->makeResources(1)->pluck('id')->all(),
                'tag_ids' => [$tag->id],
                'mode' => 'replace',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('mode');
    }
}
