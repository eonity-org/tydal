<?php

namespace Tests\Feature;

use App\Enums\ResourceState;
use App\Models\Collection;
use App\Models\Organization;
use App\Models\Resource;
use App\Models\User;
use App\Models\Workspace;
use App\Services\RagService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature tests for POST /workspaces/{id}/ask — workspace RAG endpoint.
 *
 * RagService is replaced with a Laravel container mock so no real
 * ES, embedder, or LLM calls are made.
 */
class WorkspaceAskTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Organization $organization;

    private Workspace $workspace;

    private Resource $resource;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create();
        $this->organization = Organization::factory()->create();
        $this->organization->users()->attach($this->admin->id, ['role' => 'admin']);
        $this->admin->update(['last_organization_id' => $this->organization->id]);

        $collection = Collection::factory()->create([
            'organization_id' => $this->organization->id,
            'user_owner_id' => $this->admin->id,
        ]);

        $this->workspace = Workspace::factory()->create([
            'organization_id' => $this->organization->id,
            'user_owner_id' => $this->admin->id,
            'is_default' => false,
        ]);

        $this->resource = Resource::factory()->create([
            'organization_id' => $this->organization->id,
            'collection_id' => $collection->id,
            'user_owner_id' => $this->admin->id,
            'state' => ResourceState::LIVE->value,
        ]);
        $this->workspace->resources()->attach($this->resource->id);
    }

    // =========================================================================
    // Authentication and authorization
    // =========================================================================

    public function test_ask_requires_authentication(): void
    {
        $response = $this->postJson("/api/v1/workspaces/{$this->workspace->id}/ask", [
            'question' => 'What are the brand colors?',
        ]);

        $response->assertStatus(401);
    }

    public function test_viewer_can_ask(): void
    {
        $viewer = User::factory()->create();
        $this->organization->users()->attach($viewer->id, ['role' => 'viewer']);
        $viewer->update(['last_organization_id' => $this->organization->id]);

        $this->mock(RagService::class, function ($mock) {
            $mock->shouldReceive('askForWorkspace')
                ->andReturn(['answer' => 'ok', 'sources' => []]);
        });

        $token = $viewer->createToken('t')->plainTextToken;

        $this->withToken($token)
            ->postJson("/api/v1/workspaces/{$this->workspace->id}/ask", [
                'question' => 'What is here?',
            ])
            ->assertStatus(200);
    }

    // =========================================================================
    // Validation
    // =========================================================================

    public function test_ask_validates_question_is_required(): void
    {
        $token = $this->admin->createToken('t')->plainTextToken;

        $this->withToken($token)
            ->postJson("/api/v1/workspaces/{$this->workspace->id}/ask", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['question']);
    }

    public function test_ask_validates_question_minimum_length(): void
    {
        $token = $this->admin->createToken('t')->plainTextToken;

        $this->withToken($token)
            ->postJson("/api/v1/workspaces/{$this->workspace->id}/ask", ['question' => 'Hi'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['question']);
    }

    public function test_ask_validates_k_must_be_at_least_1(): void
    {
        $token = $this->admin->createToken('t')->plainTextToken;

        $this->withToken($token)
            ->postJson("/api/v1/workspaces/{$this->workspace->id}/ask", [
                'question' => 'What is here?',
                'k' => 0,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['k']);
    }

    // =========================================================================
    // Not found
    // =========================================================================

    public function test_ask_returns_404_for_unknown_workspace(): void
    {
        $token = $this->admin->createToken('t')->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/v1/workspaces/99999/ask', [
                'question' => 'What is in this workspace?',
            ])
            ->assertStatus(404);
    }

    // =========================================================================
    // Success
    // =========================================================================

    public function test_ask_returns_answer_and_sources_on_success(): void
    {
        $this->mock(RagService::class, function ($mock) {
            $mock->shouldReceive('askForWorkspace')
                ->once()
                ->andReturn([
                    'answer' => 'The safety regulations require helmets.',
                    'sources' => [
                        [
                            'chunk_id' => 'chunk-1',
                            'resource_id' => $this->resource->id,
                            'resource_name' => 'Safety Manual',
                            'file_id' => 'file-1',
                            'page_number' => 3,
                        ],
                    ],
                ]);
        });

        $token = $this->admin->createToken('t')->plainTextToken;

        $this->withToken($token)
            ->postJson("/api/v1/workspaces/{$this->workspace->id}/ask", [
                'question' => 'What are the safety regulations?',
            ])
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.answer', 'The safety regulations require helmets.')
            ->assertJsonStructure(['data' => ['answer', 'sources']]);
    }

    public function test_ask_forwards_k_parameter_to_rag_service(): void
    {
        $this->mock(RagService::class, function ($mock) {
            $mock->shouldReceive('askForWorkspace')
                ->withArgs(function ($workspace, string $question, int $k): bool {
                    return $k === 10;
                })
                ->once()
                ->andReturn(['answer' => 'ok', 'sources' => []]);
        });

        $token = $this->admin->createToken('t')->plainTextToken;

        $this->withToken($token)
            ->postJson("/api/v1/workspaces/{$this->workspace->id}/ask", [
                'question' => 'What are the brand colors?',
                'k' => 10,
            ])
            ->assertStatus(200);
    }

    // =========================================================================
    // Error responses
    // =========================================================================

    public function test_ask_returns_422_when_rag_service_throws_runtime_exception(): void
    {
        $this->mock(RagService::class, function ($mock) {
            $mock->shouldReceive('askForWorkspace')
                ->andThrow(new \RuntimeException('No vector search index is available for this workspace.'));
        });

        $token = $this->admin->createToken('t')->plainTextToken;

        $this->withToken($token)
            ->postJson("/api/v1/workspaces/{$this->workspace->id}/ask", [
                'question' => 'What is in this workspace?',
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'No vector search index is available for this workspace.');
    }

    public function test_ask_returns_503_when_rag_service_throws_generic_exception(): void
    {
        $this->mock(RagService::class, function ($mock) {
            $mock->shouldReceive('askForWorkspace')
                ->andThrow(new \Exception('Upstream timeout'));
        });

        $token = $this->admin->createToken('t')->plainTextToken;

        $this->withToken($token)
            ->postJson("/api/v1/workspaces/{$this->workspace->id}/ask", [
                'question' => 'What is in this workspace?',
            ])
            ->assertStatus(503)
            ->assertJsonPath('success', false);
    }
}
