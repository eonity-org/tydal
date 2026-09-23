<?php

namespace Tests\Feature;

use App\Models\Collection;
use App\Models\Organization;
use App\Models\Resource;
use App\Models\User;
use App\Services\AutoApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Verifies the single-resource AITY approve endpoint maps the `apply` flag to the
 * correct AutoApprovalService options:
 *   - apply=false → generate-only (apply_name/description/tags all false), non-destructive.
 *   - apply=true  → auto-approve (apply flags default on) with min_frequency=1, since
 *     cross-resource frequency clustering is meaningless for a single resource.
 * The service is mocked so no real LLM calls happen.
 */
class AityApproveResourceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Organization $organization;

    private Resource $resource;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->organization = Organization::factory()->create();
        $collection = Collection::factory()->create([
            'organization_id' => $this->organization->id,
            'user_owner_id' => $this->user->id,
        ]);
        $this->organization->users()->attach($this->user->id, ['role' => 'admin']);
        $this->user->update(['last_organization_id' => $this->organization->id]);

        $this->resource = Resource::factory()->create([
            'organization_id' => $this->organization->id,
            'collection_id' => $collection->id,
            'user_owner_id' => $this->user->id,
        ]);
    }

    private function token(): string
    {
        return $this->user->createToken('auth-token', ['*'], now()->addHour())->plainTextToken;
    }

    public function test_generate_only_passes_apply_flags_false(): void
    {
        $this->mock(AutoApprovalService::class, function ($m) {
            $m->shouldReceive('approve')
                ->once()
                ->with(Mockery::any(), $this->organization->id, Mockery::on(function ($opts) {
                    return ($opts['apply_name'] ?? null) === false
                        && ($opts['apply_description'] ?? null) === false
                        && ($opts['apply_tags'] ?? null) === false;
                }))
                ->andReturn(['names_applied' => 0]);
        });

        $this->withToken($this->token())
            ->postJson("/api/v1/resources/{$this->resource->id}/aity-approve", ['apply' => false])
            ->assertStatus(200)
            ->assertJson(['success' => true, 'applied' => false]);
    }

    public function test_auto_approve_passes_min_frequency_one_and_applies(): void
    {
        $this->mock(AutoApprovalService::class, function ($m) {
            $m->shouldReceive('approve')
                ->once()
                ->with(Mockery::any(), $this->organization->id, Mockery::on(function ($opts) {
                    // apply flags are NOT forced off, and frequency is relaxed to 1.
                    return ($opts['min_frequency'] ?? null) === 1
                        && ! array_key_exists('apply_tags', $opts);
                }))
                ->andReturn(['tags_applied' => 2]);
        });

        $this->withToken($this->token())
            ->postJson("/api/v1/resources/{$this->resource->id}/aity-approve", ['apply' => true])
            ->assertStatus(200)
            ->assertJson(['success' => true, 'applied' => true]);
    }

    public function test_returns_404_for_missing_resource(): void
    {
        $this->withToken($this->token())
            ->postJson('/api/v1/resources/00000000-0000-0000-0000-000000000000/aity-approve', ['apply' => false])
            ->assertStatus(404);
    }
}
