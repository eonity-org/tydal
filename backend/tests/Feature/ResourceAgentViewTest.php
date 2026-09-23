<?php

namespace Tests\Feature;

use App\Enums\VaultState;
use App\Models\Collection;
use App\Models\File;
use App\Models\FileChunk;
use App\Models\Organization;
use App\Models\Resource;
use App\Models\SemanticTag;
use App\Models\User;
use App\Models\Vault;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ResourceAgentViewTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Organization $organization;

    private Collection $collection;

    private Resource $resource;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('s3');

        $this->user = User::factory()->create();
        $this->organization = Organization::factory()->create();
        $this->collection = Collection::factory()->create([
            'organization_id' => $this->organization->id,
            'user_owner_id' => $this->user->id,
        ]);
        $this->organization->users()->attach($this->user->id, ['role' => 'admin']);
        $this->user->update(['last_organization_id' => $this->organization->id]);

        $this->resource = Resource::factory()
            ->forCollection($this->collection->id)
            ->create([
                'organization_id' => $this->organization->id,
                'user_owner_id' => $this->user->id,
            ]);
    }

    private function token(): string
    {
        return $this->user->createToken('auth-token', ['*'], now()->addHour())->plainTextToken;
    }

    public function test_agent_view_returns_lean_shape_without_pipeline_internals(): void
    {
        $response = $this->withToken($this->token())
            ->getJson("/api/v1/resources/{$this->resource->id}/agent-view");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'resource' => [
                        'id', 'slug', 'name', 'description', 'type', 'state',
                        'tags', 'metadata', 'collection', 'files', 'vault_links',
                        'content' => ['has_chunks', 'chunk_count'],
                        'workspaces', 'created_at', 'updated_at',
                    ],
                ],
            ]);

        // Pipeline-audit internals from the admin show() shape must not leak here.
        $body = $response->json('data.resource');
        $this->assertArrayNotHasKey('ai_suggestions_status', $body);
        $this->assertArrayNotHasKey('under_auto_approve', $body);
        $this->assertArrayNotHasKey('promoted_file_metadata', $body);
    }

    public function test_agent_view_includes_tags_and_slug(): void
    {
        $tag = SemanticTag::factory()->create([
            'organization_id' => $this->organization->id,
            'label' => 'Astrophysics',
        ]);
        $this->resource->semanticTags()->attach($tag->id);
        $this->resource->update(['slug' => 'nebula-report']);

        $response = $this->withToken($this->token())
            ->getJson("/api/v1/resources/{$this->resource->id}/agent-view");

        $response->assertStatus(200)
            ->assertJsonPath('data.resource.slug', 'nebula-report')
            ->assertJsonPath('data.resource.tags.0.label', 'Astrophysics');
    }

    public function test_agent_view_inlines_files_with_direct_urls(): void
    {
        File::factory()->forResource($this->resource->id)->create([
            'filename' => 'report.pdf',
            'mime_type' => 'application/pdf',
        ]);

        $response = $this->withToken($this->token())
            ->getJson("/api/v1/resources/{$this->resource->id}/agent-view");

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data.resource.files')
            ->assertJsonPath('data.resource.files.0.filename', 'report.pdf');
        $this->assertNotEmpty($response->json('data.resource.files.0.url'));
    }

    public function test_agent_view_inlines_vault_links_when_workspace_has_a_vault(): void
    {
        $workspace = Workspace::factory()->create(['organization_id' => $this->organization->id]);
        $vault = Vault::factory()->create(['state' => VaultState::PRIVATE->value]);
        $workspace->vaults()->attach($vault->id);
        $this->resource->workspaces()->attach($workspace->id);

        $response = $this->withToken($this->token())
            ->getJson("/api/v1/resources/{$this->resource->id}/agent-view");

        $response->assertStatus(200);
        $this->assertNotEmpty($response->json('data.resource.vault_links'));
        $this->assertSame($vault->name, $response->json('data.resource.vault_links.0.vault_name'));
    }

    public function test_agent_view_vault_links_are_empty_without_a_vault(): void
    {
        $response = $this->withToken($this->token())
            ->getJson("/api/v1/resources/{$this->resource->id}/agent-view");

        $response->assertStatus(200)
            ->assertJsonPath('data.resource.vault_links', []);
    }

    public function test_agent_view_reports_chunk_availability(): void
    {
        $file = File::factory()->forResource($this->resource->id)->create();
        FileChunk::insert([
            [
                'id' => (string) Str::uuid(),
                'resource_id' => $this->resource->id,
                'source_file_id' => $file->id,
                'sequence' => 0,
                'page_number' => 1,
                'word_count' => 5,
                'char_start' => 0,
                'char_end' => 30,
                'created_at' => now(),
            ],
        ]);

        $response = $this->withToken($this->token())
            ->getJson("/api/v1/resources/{$this->resource->id}/agent-view");

        $response->assertStatus(200)
            ->assertJsonPath('data.resource.content.has_chunks', true)
            ->assertJsonPath('data.resource.content.chunk_count', 1)
            ->assertJsonPath('data.resource.files.0.content.has_chunks', true);
    }

    public function test_agent_view_returns_404_for_missing_resource(): void
    {
        $this->withToken($this->token())
            ->getJson('/api/v1/resources/'.Str::uuid().'/agent-view')
            ->assertStatus(404);
    }

    public function test_guest_cannot_access_agent_view(): void
    {
        $this->getJson("/api/v1/resources/{$this->resource->id}/agent-view")
            ->assertStatus(401);
    }
}
