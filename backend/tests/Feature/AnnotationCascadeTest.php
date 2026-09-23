<?php

namespace Tests\Feature;

use App\Enums\ResourceState;
use App\Enums\TagReviewer;
use App\Enums\TagVocabulary;
use App\Jobs\IndexAnnotationsToElasticsearch;
use App\Models\Collection;
use App\Models\Organization;
use App\Models\Resource;
use App\Models\SemanticTag;
use App\Models\User;
use App\Models\Workspace;
use App\Services\WorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Verifies that annotation re-index jobs are dispatched correctly when
 * tag labels/icons or workspace names are changed.
 *
 * Uses Queue::fake() — the cascade dispatches IndexAnnotationsToElasticsearch
 * with dispatch() (async), which the fake queue captures without executing.
 *
 * Resources are created in collections without a SearchIndex so that the
 * synchronous IndexResourceToElasticsearch::dispatchSync() boot hook returns
 * early and never hits a real ES cluster.
 */
class AnnotationCascadeTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Organization $organization;

    private Collection $collection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create();
        $this->organization = Organization::factory()->create();

        // No index_id — boot hooks on resource creation are safe
        $this->collection = Collection::factory()->create([
            'organization_id' => $this->organization->id,
            'user_owner_id' => $this->admin->id,
            'index_id' => null,
        ]);

        $this->organization->users()->attach($this->admin->id, ['role' => 'admin']);
        $this->admin->update(['last_organization_id' => $this->organization->id]);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function createActiveResource(): Resource
    {
        return Resource::factory()->create([
            'collection_id' => $this->collection->id,
            'organization_id' => $this->organization->id,
            'user_owner_id' => $this->admin->id,
            'state' => ResourceState::LIVE->value,
        ]);
    }

    private function createTag(string $label, string $entityType = 'tag'): SemanticTag
    {
        return SemanticTag::create([
            'organization_id' => $this->organization->id,
            'label' => $label,
            'slug' => Str::slug($label),
            'entity_type' => $entityType,
            'is_active' => true,
            'vocabulary' => TagVocabulary::ORGANIZATION->value,
            'reviewer' => TagReviewer::USER->value,
        ]);
    }

    private function token(): string
    {
        return $this->admin->createToken('t')->plainTextToken;
    }

    // =========================================================================
    // SemanticTagController::update() cascade
    // =========================================================================

    public function test_tag_label_change_dispatches_annotation_job_for_each_active_resource(): void
    {
        $tag = $this->createTag('Paris');
        $resource = $this->createActiveResource();
        $resource->semanticTags()->attach($tag->id);

        Queue::fake();

        $this->withToken($this->token())
            ->putJson("/api/v1/semantic-tags/{$tag->id}", ['label' => 'Lyon'])
            ->assertStatus(200);

        Queue::assertPushed(IndexAnnotationsToElasticsearch::class, function ($job) use ($resource) {
            return $job->getResourceId() === $resource->id;
        });
    }

    public function test_tag_icon_change_dispatches_annotation_job(): void
    {
        $tag = $this->createTag('Berlin', 'place');
        $resource = $this->createActiveResource();
        $resource->semanticTags()->attach($tag->id);

        Queue::fake();

        $this->withToken($this->token())
            ->putJson("/api/v1/semantic-tags/{$tag->id}", ['entity_type' => 'thing'])
            ->assertStatus(200);

        Queue::assertPushed(IndexAnnotationsToElasticsearch::class, 1);
    }

    public function test_tag_color_only_change_does_not_dispatch_annotation_job(): void
    {
        $tag = $this->createTag('Rome');
        $resource = $this->createActiveResource();
        $resource->semanticTags()->attach($tag->id);

        Queue::fake();

        $this->withToken($this->token())
            ->putJson("/api/v1/semantic-tags/{$tag->id}", ['color' => '#FF0000'])
            ->assertStatus(200);

        Queue::assertNothingPushed();
    }

    public function test_tag_label_change_only_dispatches_for_active_resources(): void
    {
        $tag = $this->createTag('Amsterdam');
        $activeRes = $this->createActiveResource();
        $inactiveRes = Resource::factory()->create([
            'collection_id' => $this->collection->id,
            'organization_id' => $this->organization->id,
            'user_owner_id' => $this->admin->id,
            'state' => ResourceState::ARCHIVED->value,
        ]);

        $activeRes->semanticTags()->attach($tag->id);
        $inactiveRes->semanticTags()->attach($tag->id);

        Queue::fake();

        $this->withToken($this->token())
            ->putJson("/api/v1/semantic-tags/{$tag->id}", ['label' => 'Rotterdam'])
            ->assertStatus(200);

        // Only the active resource should get a job
        Queue::assertPushed(IndexAnnotationsToElasticsearch::class, 1);
        Queue::assertPushed(IndexAnnotationsToElasticsearch::class, function ($job) use ($activeRes) {
            return $job->getResourceId() === $activeRes->id;
        });
    }

    // =========================================================================
    // SemanticTagController::destroy() cascade — must run BEFORE detach
    // =========================================================================

    public function test_tag_deletion_dispatches_annotation_job_for_attached_resources(): void
    {
        $tag = $this->createTag('Madrid');
        $resource = $this->createActiveResource();
        $resource->semanticTags()->attach($tag->id);

        Queue::fake();

        $this->withToken($this->token())
            ->deleteJson("/api/v1/semantic-tags/{$tag->id}")
            ->assertStatus(200);

        Queue::assertPushed(IndexAnnotationsToElasticsearch::class, function ($job) use ($resource) {
            return $job->getResourceId() === $resource->id;
        });

        // Tag and pivot row must also be gone
        $this->assertDatabaseMissing('semantic_tags', ['id' => $tag->id]);
        $this->assertDatabaseMissing('semantic_tag_resource', [
            'semantic_tag_id' => $tag->id,
            'resource_id' => $resource->id,
        ]);
    }

    // =========================================================================
    // WorkspaceService::updateWorkspace() cascade
    // =========================================================================

    public function test_workspace_name_change_dispatches_annotation_job_for_active_resources(): void
    {
        $workspace = Workspace::factory()->create([
            'organization_id' => $this->organization->id,
            'user_owner_id' => $this->admin->id,
            'name' => 'Design',
            'is_default' => false,
        ]);
        $resource = $this->createActiveResource();
        $workspace->resources()->attach($resource->id);

        Queue::fake();

        $this->withToken($this->token())
            ->putJson("/api/v1/workspaces/{$workspace->id}", ['name' => 'Design Team'])
            ->assertStatus(200);

        Queue::assertPushed(IndexAnnotationsToElasticsearch::class, function ($job) use ($resource) {
            return $job->getResourceId() === $resource->id;
        });
    }

    public function test_workspace_description_only_change_does_not_dispatch_annotation_job(): void
    {
        $workspace = Workspace::factory()->create([
            'organization_id' => $this->organization->id,
            'user_owner_id' => $this->admin->id,
            'name' => 'Marketing',
            'is_default' => false,
        ]);
        $resource = $this->createActiveResource();
        $workspace->resources()->attach($resource->id);

        Queue::fake();

        $this->withToken($this->token())
            ->putJson("/api/v1/workspaces/{$workspace->id}", ['description' => 'Updated description'])
            ->assertStatus(200);

        Queue::assertNothingPushed();
    }

    public function test_workspace_name_change_dispatches_service_directly(): void
    {
        $workspace = Workspace::factory()->create([
            'organization_id' => $this->organization->id,
            'user_owner_id' => $this->admin->id,
            'name' => 'OldName',
            'is_default' => false,
        ]);
        $resource = $this->createActiveResource();
        $workspace->resources()->attach($resource->id);

        Queue::fake();

        /** @var WorkspaceService $svc */
        $svc = app(WorkspaceService::class);
        $svc->updateWorkspace($workspace->id, ['name' => 'NewName']);

        Queue::assertPushed(IndexAnnotationsToElasticsearch::class, function ($job) use ($resource) {
            return $job->getResourceId() === $resource->id;
        });
    }

    public function test_workspace_no_name_change_dispatches_nothing_via_service(): void
    {
        $workspace = Workspace::factory()->create([
            'organization_id' => $this->organization->id,
            'user_owner_id' => $this->admin->id,
            'name' => 'Stable',
            'is_default' => false,
        ]);
        $resource = $this->createActiveResource();
        $workspace->resources()->attach($resource->id);

        Queue::fake();

        /** @var WorkspaceService $svc */
        $svc = app(WorkspaceService::class);
        $svc->updateWorkspace($workspace->id, ['description' => 'Some description']);

        Queue::assertNothingPushed();
    }
}
