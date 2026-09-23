<?php

namespace Tests\Feature;

use App\Enums\ResourceState;
use App\Jobs\IndexAnnotationsToElasticsearch;
use App\Models\Collection;
use App\Models\Organization;
use App\Models\Resource;
use App\Models\User;
use App\Services\ElasticsearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Tests for the IndexAnnotationsToElasticsearch job.
 *
 * The job runs synchronously in tests by calling handle() directly.
 * ElasticsearchService is mocked so no real ES connection is required.
 */
class IndexAnnotationsJobTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->organization = Organization::factory()->create();
    }

    private function makeResource(bool $active = true): Resource
    {
        $collection = Collection::factory()->create([
            'organization_id' => $this->organization->id,
            'user_owner_id' => $this->user->id,
            'index_id' => null,
        ]);

        return Resource::factory()->create([
            'collection_id' => $collection->id,
            'organization_id' => $this->organization->id,
            'user_owner_id' => $this->user->id,
            'state' => $active ? ResourceState::LIVE->value : ResourceState::ARCHIVED->value,
        ]);
    }

    // =========================================================================

    public function test_job_calls_index_annotations_for_active_resource(): void
    {
        $resource = $this->makeResource(active: true);

        $mockEs = Mockery::mock(ElasticsearchService::class);
        $mockEs->shouldReceive('indexAnnotations')
            ->once()
            ->withArgs(function (Resource $r) use ($resource): bool {
                return $r->id === $resource->id;
            });

        (new IndexAnnotationsToElasticsearch($resource->id))->handle($mockEs);
    }

    public function test_job_skips_inactive_resource(): void
    {
        $resource = $this->makeResource(active: false);

        $mockEs = Mockery::mock(ElasticsearchService::class);
        $mockEs->shouldNotReceive('indexAnnotations');

        (new IndexAnnotationsToElasticsearch($resource->id))->handle($mockEs);
    }

    public function test_job_skips_missing_resource(): void
    {
        $mockEs = Mockery::mock(ElasticsearchService::class);
        $mockEs->shouldNotReceive('indexAnnotations');

        (new IndexAnnotationsToElasticsearch('00000000-0000-0000-0000-000000000000'))->handle($mockEs);
    }

    public function test_job_exposes_resource_id_accessor(): void
    {
        $job = new IndexAnnotationsToElasticsearch('test-uuid-1234');

        $this->assertSame('test-uuid-1234', $job->getResourceId());
    }

    public function test_job_has_correct_retry_configuration(): void
    {
        $job = new IndexAnnotationsToElasticsearch('any-id');

        $this->assertSame(3, $job->tries);
        $this->assertSame(10, $job->backoff);
    }
}
