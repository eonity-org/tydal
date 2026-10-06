<?php

namespace Tests\Feature;

use App\Enums\AityStatus;
use App\Models\Collection;
use App\Models\Organization;
use App\Models\Resource;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * `aity:purge-batches` deletes AiTy Review batches that were reviewed (or whose
 * auto-approve job ended) more than N days ago — the workspace only, never its
 * resources.
 */
class AityPurgeBatchesTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Collection $collection;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();

        $this->organization = Organization::factory()->create();
        $this->collection = Collection::factory()->create([
            'organization_id' => $this->organization->id,
            'user_owner_id' => User::factory()->create()->id,
        ]);
        config(['autotagging.aity_batch_retention_days' => 30]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{0: Workspace, 1: list<string>}
     */
    private function batch(array $attributes = [], int $members = 2): array
    {
        $workspace = Workspace::factory()->create(array_merge([
            'organization_id' => $this->organization->id,
            'is_system' => true,
            'purpose' => 'aity_review',
        ], $attributes));

        $resources = Resource::factory()
            ->forCollection($this->collection->id)
            ->count($members)
            ->create(['organization_id' => $this->organization->id]);
        $workspace->resources()->sync($resources->pluck('id')->all());

        return [$workspace, $resources->pluck('id')->all()];
    }

    private function exists(Workspace $workspace): bool
    {
        return Workspace::whereKey($workspace->id)->exists();
    }

    public function test_an_old_reviewed_batch_is_deleted_and_its_resources_survive(): void
    {
        [$batch, $resourceIds] = $this->batch(['auto_approve_reviewed_at' => now()->subDays(31)]);

        $this->artisan('aity:purge-batches')->assertSuccessful();

        $this->assertFalse($this->exists($batch));
        $this->assertSame(2, Resource::whereIn('id', $resourceIds)->count());
        $this->assertDatabaseMissing('dam_resource_workspace', ['workspace_id' => $batch->id]);
    }

    public function test_an_old_batch_whose_auto_approve_finished_is_deleted(): void
    {
        [$done] = $this->batch(['auto_approve_status' => 'done']);
        [$failed] = $this->batch(['auto_approve_status' => 'failed']);
        Workspace::whereIn('id', [$done->id, $failed->id])->update(['updated_at' => now()->subDays(40)]);

        $this->artisan('aity:purge-batches')->assertSuccessful();

        $this->assertFalse($this->exists($done));
        $this->assertFalse($this->exists($failed));
    }

    public function test_recent_unreviewed_running_and_ordinary_workspaces_are_kept(): void
    {
        [$recent] = $this->batch(['auto_approve_reviewed_at' => now()->subDays(5)]);
        [$unreviewed] = $this->batch();
        [$running] = $this->batch(['auto_approve_status' => 'running', 'auto_approve_reviewed_at' => now()->subDays(60)]);
        [$analysing, $analysingIds] = $this->batch(['auto_approve_reviewed_at' => now()->subDays(60)]);
        Resource::whereKey($analysingIds[0])->update(['aity_status' => AityStatus::AITY_IN_PROGRESS->value]);
        $ordinary = Workspace::factory()->create([
            'organization_id' => $this->organization->id,
            'auto_approve_reviewed_at' => now()->subDays(60),
        ]);
        Workspace::query()->update(['updated_at' => now()->subDays(60)]);

        $this->artisan('aity:purge-batches')->assertSuccessful();

        foreach ([$recent, $unreviewed, $running, $analysing, $ordinary] as $kept) {
            $this->assertTrue($this->exists($kept), "workspace {$kept->name} should be kept");
        }
    }

    public function test_dry_run_deletes_nothing(): void
    {
        [$batch] = $this->batch(['auto_approve_reviewed_at' => now()->subDays(31)]);

        $this->artisan('aity:purge-batches', ['--dry-run' => true])
            ->expectsOutputToContain('Found 1')
            ->assertSuccessful();

        $this->assertTrue($this->exists($batch));
    }

    public function test_days_option_overrides_the_configured_retention(): void
    {
        [$batch] = $this->batch(['auto_approve_reviewed_at' => now()->subDays(10)]);

        $this->artisan('aity:purge-batches')->assertSuccessful();
        $this->assertTrue($this->exists($batch));

        $this->artisan('aity:purge-batches', ['--days' => 7])->assertSuccessful();
        $this->assertFalse($this->exists($batch));
    }

    public function test_zero_days_disables_the_cleanup(): void
    {
        [$batch] = $this->batch(['auto_approve_reviewed_at' => now()->subDays(365)]);

        $this->artisan('aity:purge-batches', ['--days' => 0])->assertSuccessful();
        $this->assertTrue($this->exists($batch));

        config(['autotagging.aity_batch_retention_days' => 0]);
        $this->artisan('aity:purge-batches')->assertSuccessful();
        $this->assertTrue($this->exists($batch));
    }
}
