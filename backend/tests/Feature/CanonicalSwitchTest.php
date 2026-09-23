<?php

namespace Tests\Feature;

use App\Enums\FileRelation;
use App\Enums\FileRole;
use App\Enums\ResourceState;
use App\Enums\SystemFilePurpose;
use App\Models\Collection;
use App\Models\CollectionScheme;
use App\Models\File;
use App\Models\Organization;
use App\Models\Resource;
use App\Models\SearchIndex;
use App\Models\SystemFile;
use App\Models\User;
use App\Services\ElasticsearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Canonical switching end-to-end: promotion and demotion must refresh the
 * same derived artifacts (promoted metadata, resource embedding, ES document)
 * and invalidate stale resource-level AITY synthesis.
 */
class CanonicalSwitchTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Resource $resource;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($this->user->id, ['role' => 'admin']);
        $this->user->update(['last_organization_id' => $org->id]);
        $this->token = $this->user->createToken('t')->plainTextToken;

        $searchIndex = SearchIndex::create([
            'index_name' => 'tydal_test',
            'display_name' => 'Test Index',
            'is_active' => true,
        ]);

        $scheme = CollectionScheme::create([
            'name' => 'switch-scheme',
            'display_name' => 'Switch Scheme',
            'accepted_mimetypes' => [],
            'is_system' => false,
            'fields' => [],
        ]);

        $collection = Collection::factory()->create([
            'organization_id' => $org->id,
            'user_owner_id' => $this->user->id,
            'scheme_id' => $scheme->id,
            'index_id' => $searchIndex->id,
        ]);

        $this->resource = Resource::factory()->create([
            'organization_id' => $org->id,
            'collection_id' => $collection->id,
            'user_owner_id' => $this->user->id,
            'state' => ResourceState::LIVE->value,
        ]);
    }

    /** Permissive ES mock — derived-artifact refresh runs against it. */
    private function mockEs(): Mockery\MockInterface
    {
        $mock = Mockery::mock(ElasticsearchService::class);
        $mock->shouldReceive('buildChunksIndexName')->andReturn('tydal_test_chunks');
        $mock->shouldReceive('fetchChunkVectors')->andReturn([])->byDefault();
        $mock->shouldReceive('fetchMetaChunkVector')->andReturnNull()->byDefault();
        $mock->shouldReceive('indexResource')->byDefault();
        $mock->shouldReceive('indexResourceIntoVaults')->byDefault();
        $this->instance(ElasticsearchService::class, $mock);

        return $mock;
    }

    private function addGeneratedSuggestion(?string $sourceFileId = null, bool $applied = false): SystemFile
    {
        return SystemFile::create([
            'resource_id' => $this->resource->id,
            'source_file_id' => $sourceFileId,
            'purpose' => SystemFilePurpose::AI_GENERATED_NAME,
            'filename' => 'suggestion.json',
            'mime_type' => 'application/json',
            'size' => 10,
            'path' => 'system/suggestion.json',
            'disk' => 'local',
            'is_active' => true,
            'applied_at' => $applied ? now() : null,
            'metadata' => ['value' => 'Synthesized Name'],
        ]);
    }

    // =========================================================================
    // Demotion refreshes the same derived artifacts as promotion
    // =========================================================================

    public function test_demoting_canonical_recomputes_embedding_and_reindexes(): void
    {
        $canonical = File::factory()->for($this->resource)->create(['role' => FileRole::CANONICAL]);
        File::factory()->for($this->resource)->create(['role' => FileRole::SUPPORTING]);

        $mock = $this->mockEs();

        // Embedding recompute must query the NEW contributor set (both files
        // become components via the reverse transition)
        $mock->shouldReceive('fetchChunkVectors')
            ->once()
            ->withArgs(fn ($index, $resourceId, array $fileIds) => count($fileIds) === 2)
            ->andReturn([[1.0, 1.0], [3.0, 3.0]]);

        // And the ES document must be rebuilt explicitly
        $mock->shouldReceive('indexResource')->atLeast()->once();

        $this->withToken($this->token)
            ->patchJson("/api/v1/resources/{$this->resource->id}/files/{$canonical->id}", [
                'role' => 'component',
            ])
            ->assertStatus(200);

        $fresh = $this->resource->fresh();
        $this->assertEquals([2.0, 2.0], $fresh->embedding, 'embedding follows the new contributor set');
        $this->assertSame(
            FileRole::COMPONENT,
            $this->resource->files()->where('id', '!=', $canonical->id)->first()->role,
            'supporting peer reverts to component'
        );
    }

    public function test_relation_only_update_cannot_put_a_relation_on_the_canonical(): void
    {
        $canonical = File::factory()->for($this->resource)->create(['role' => FileRole::CANONICAL]);

        $this->mockEs();

        $this->withToken($this->token)
            ->patchJson("/api/v1/resources/{$this->resource->id}/files/{$canonical->id}", [
                'relation' => FileRelation::DERIVED->value,
            ])
            ->assertStatus(200);

        $this->assertNull($canonical->fresh()->relation, 'canonical files never carry a relation');
    }

    // =========================================================================
    // Stale resource-level AITY synthesis is invalidated on composition change
    // =========================================================================

    public function test_promotion_invalidates_unapplied_resource_level_suggestions(): void
    {
        $component = File::factory()->for($this->resource)->create(['role' => FileRole::COMPONENT, 'position' => 1]);
        File::factory()->for($this->resource)->create(['role' => FileRole::COMPONENT, 'position' => 2]);

        $pending = $this->addGeneratedSuggestion();                              // stale synthesis → out
        $applied = $this->addGeneratedSuggestion(applied: true);                 // history → stays
        $perFile = $this->addGeneratedSuggestion(sourceFileId: $component->id);  // per-file → stays

        $this->mockEs();

        $this->withToken($this->token)
            ->patchJson("/api/v1/resources/{$this->resource->id}/files/{$component->id}/canonical")
            ->assertStatus(200);

        $this->assertFalse($pending->fresh()->is_active, 'pending synthesis deactivated');
        $this->assertTrue($applied->fresh()->is_active, 'applied history preserved');
        $this->assertTrue($perFile->fresh()->is_active, 'per-file suggestions live with their file');
    }

    public function test_demotion_invalidates_unapplied_resource_level_suggestions(): void
    {
        $canonical = File::factory()->for($this->resource)->create(['role' => FileRole::CANONICAL]);
        $pending = $this->addGeneratedSuggestion();

        $this->mockEs();

        $this->withToken($this->token)
            ->patchJson("/api/v1/resources/{$this->resource->id}/files/{$canonical->id}", [
                'role' => 'component',
            ])
            ->assertStatus(200);

        $this->assertFalse($pending->fresh()->is_active);
    }
}
