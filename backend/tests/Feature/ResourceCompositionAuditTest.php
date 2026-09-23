<?php

namespace Tests\Feature;

use App\Enums\FileRelation;
use App\Enums\FileRole;
use App\Exceptions\InvalidResourceComposition;
use App\Models\File;
use App\Models\Resource;
use App\Services\Interfaces\ResourceServiceInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Epic 1.1 — composition invariants in the service layer +
 * resources:audit-roles backfill audit.
 */
class ResourceCompositionAuditTest extends TestCase
{
    use RefreshDatabase;

    private ResourceServiceInterface $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ResourceServiceInterface::class);
    }

    // =========================================================================
    // Service-layer invariants (applyFileRoleInvariants)
    // =========================================================================

    public function test_canonical_upload_demotes_existing_canonical_and_components(): void
    {
        $resource = Resource::factory()->create();
        $oldCanonical = File::factory()->for($resource)->create(['role' => FileRole::CANONICAL]);
        $component = File::factory()->for($resource)->create(['role' => FileRole::COMPONENT]);

        $relation = $this->service->applyFileRoleInvariants(
            $resource, FileRole::CANONICAL, FileRelation::DERIVED, null
        );

        $this->assertNull($relation, 'canonical strips the relation');
        $this->assertSame(FileRole::SUPPORTING, $oldCanonical->fresh()->role);
        $this->assertSame(FileRole::SUPPORTING, $component->fresh()->role);
    }

    public function test_component_beside_canonical_is_rejected(): void
    {
        $resource = Resource::factory()->create();
        File::factory()->for($resource)->create(['role' => FileRole::CANONICAL]);

        $this->expectException(InvalidResourceComposition::class);

        $this->service->applyFileRoleInvariants($resource, FileRole::COMPONENT, null, null);
    }

    public function test_component_without_canonical_is_allowed(): void
    {
        $resource = Resource::factory()->create();
        File::factory()->for($resource)->create(['role' => FileRole::COMPONENT]);

        $relation = $this->service->applyFileRoleInvariants($resource, FileRole::COMPONENT, null, null);

        $this->assertNull($relation);
    }

    public function test_snapshot_usage_clears_other_snapshots(): void
    {
        $resource = Resource::factory()->create();
        $previous = File::factory()->for($resource)->create([
            'role' => FileRole::SUPPORTING,
            'usage' => ['snapshot'],
        ]);

        $this->service->applyFileRoleInvariants($resource, FileRole::SUPPORTING, null, ['snapshot']);

        $this->assertNotContains('snapshot', $previous->fresh()->usage ?? []);
    }

    // =========================================================================
    // resources:audit-roles
    // =========================================================================

    public function test_audit_passes_on_clean_data(): void
    {
        $resource = Resource::factory()->create();
        File::factory()->for($resource)->create(['role' => FileRole::CANONICAL]);
        File::factory()->for($resource)->create(['role' => FileRole::SUPPORTING]);

        $this->artisan('resources:audit-roles')
            ->expectsOutputToContain('All composition invariants hold.')
            ->assertExitCode(0);
    }

    public function test_audit_detects_and_fixes_multiple_canonicals(): void
    {
        $resource = Resource::factory()->create();
        $older = File::factory()->for($resource)->create([
            'role' => FileRole::CANONICAL, 'created_at' => now()->subDay(),
        ]);
        $newer = File::factory()->for($resource)->create([
            'role' => FileRole::CANONICAL, 'created_at' => now(),
        ]);

        $this->artisan('resources:audit-roles')->assertExitCode(1);

        $this->artisan('resources:audit-roles --fix')->assertExitCode(0);

        $this->assertSame(FileRole::CANONICAL, $newer->fresh()->role);
        $this->assertSame(FileRole::SUPPORTING, $older->fresh()->role);
    }

    public function test_audit_fixes_component_beside_canonical_and_canonical_relation(): void
    {
        $resource = Resource::factory()->create();
        File::factory()->for($resource)->create([
            'role' => FileRole::CANONICAL, 'relation' => FileRelation::DERIVED,
        ]);
        $component = File::factory()->for($resource)->create(['role' => FileRole::COMPONENT]);

        $this->artisan('resources:audit-roles --fix')->assertExitCode(0);

        $this->assertSame(FileRole::SUPPORTING, $component->fresh()->role);
        $this->assertNull($resource->files()->where('role', FileRole::CANONICAL->value)->first()->relation);
    }

    public function test_audit_reports_orphan_files_on_trashed_resources(): void
    {
        $resource = Resource::factory()->create();
        File::factory()->for($resource)->create(['role' => FileRole::CANONICAL]);
        $resource->delete(); // soft delete

        $this->artisan('resources:audit-roles')
            ->expectsOutputToContain('soft-deleted resources')
            ->assertExitCode(1);
    }
}
