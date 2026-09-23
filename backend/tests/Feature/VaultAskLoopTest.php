<?php

namespace Tests\Feature;

use App\Enums\FileRole;
use App\Enums\ResourceRelationOrigin;
use App\Enums\ResourceRelationType;
use App\Enums\ResourceState;
use App\Enums\VaultPurpose;
use App\Enums\VaultState;
use App\Models\File;
use App\Models\Organization;
use App\Models\Resource;
use App\Models\User;
use App\Models\Vault;
use App\Models\Workspace;
use App\Services\ElasticsearchService;
use App\Services\LLM\Contracts\LlmServiceInterface;
use App\Services\Processing\Contracts\EmbeddingServiceInterface;
use App\Services\ResourceGraphService;
use App\Services\VaultAskService;
use App\Services\VaultLinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Epic 4.4 — the vault ask head's reasoning loop: detect vault context →
 * resolve overlay → retrieve through the tier-gated grammar → expand the
 * graph → respond. Guardrails: vault scope + tier policy are enforced by
 * the operation layer itself; citations stay at resource level.
 */
class VaultAskLoopTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Organization $org;

    private Workspace $ws;

    /** Floor the mocked applyRagScoreFilters received (vault override wiring). */
    private ?float $ragFloorSeen = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->org = Organization::factory()->create(['slug' => 'acme']);
        $this->org->users()->attach($this->user->id, ['role' => 'admin']);
        $this->ws = Workspace::factory()->create(['organization_id' => $this->org->id]);
    }

    private function makeVault(VaultPurpose $purpose, array $overrides = []): Vault
    {
        $vault = Vault::factory()->purpose($purpose)->create(
            array_merge(['organization_id' => $this->org->id, 'slug' => 'ai-vault'], $overrides)
        );
        DB::table('workspace_vault')->insert(['workspace_id' => $this->ws->id, 'vault_id' => $vault->id]);

        return $vault;
    }

    private function makeResource(string $name): Resource
    {
        $resource = Resource::factory()->create([
            'organization_id' => $this->org->id,
            'user_owner_id' => $this->user->id,
            'name' => $name,
            'state' => ResourceState::LIVE->value,
        ]);
        DB::table('dam_resource_workspace')->insert(['resource_id' => $resource->id, 'workspace_id' => $this->ws->id]);

        return $resource;
    }

    private function mockEs(): MockInterface
    {
        $mock = Mockery::mock(ElasticsearchService::class);
        $mock->shouldReceive('indexResource')->zeroOrMoreTimes();
        $mock->shouldReceive('indexResourceIntoVaults')->zeroOrMoreTimes();
        // Score filtering is unit-tested on the real service (RagScoreFilterTest);
        // here it passes hits through so fixtures don't need calibrated scores.
        // The floor argument is captured so tests can assert the vault's
        // exposure-policy override reaches the filter.
        $mock->shouldReceive('applyRagScoreFilters')->zeroOrMoreTimes()
            ->andReturnUsing(function (array $c, ?float $minScore = null) {
                $this->ragFloorSeen = $minScore;

                return $c;
            });
        $this->instance(ElasticsearchService::class, $mock);

        return $mock;
    }

    private function mockEmbedder(): void
    {
        $mock = Mockery::mock(EmbeddingServiceInterface::class);
        $mock->shouldReceive('embed')->andReturn([0.1, 0.2]);
        $this->instance(EmbeddingServiceInterface::class, $mock);
    }

    /** @param  string|null  $capturedContext  receives the user message content */
    private function mockLlm(string $answer, ?string &$capturedContext = null): void
    {
        $llm = Mockery::mock(LlmServiceInterface::class);
        $llm->shouldReceive('getModel')->zeroOrMoreTimes()->andReturn('test-model');
        $llm->shouldReceive('chat')->andReturnUsing(function (array $messages) use ($answer, &$capturedContext) {
            $capturedContext = $messages[1]['content'];

            return $answer;
        });
        $this->app->instance(LlmServiceInterface::class, $llm);
    }

    // =========================================================================
    // The full loop
    // =========================================================================

    public function test_full_loop_context_carries_vault_passages_cards_and_graph(): void
    {
        $vault = $this->makeVault(VaultPurpose::AI, ['indexed_at' => now()]);
        $harbor = $this->makeResource('Harbor at Dusk');
        $related = $this->makeResource('Harbor Sketches');
        $file = File::factory()->for($harbor)->create(['role' => FileRole::CANONICAL]);

        app(ResourceGraphService::class)->relate(
            $harbor, $related, ResourceRelationType::RELATED, weight: 0.88,
            origin: ResourceRelationOrigin::SEMANTIC,
        );

        $this->mockEmbedder();

        $es = $this->mockEs();
        $es->shouldReceive('buildChunksIndexName')->zeroOrMoreTimes()->andReturnUsing(fn (string $n) => "{$n}_chunks");
        $es->shouldReceive('knnSearchChunksForWorkspace')->once()->andReturn([
            ['resource_id' => $harbor->id, 'file_id' => $file->id, 'sequence' => 0, 'page_number' => 3, 'content' => 'The harbor glows at dusk.', 'score' => 0.9],
        ]);
        // Real vault-index documents carry the vault-scoped id (link hash), not
        // the resource UUID — mirror that so the chunk hit and this card dedup
        // to one source (both resolve to Harbor's single link).
        $harborHash = app(VaultLinkService::class)
            ->getOrCreateLink($vault, null, $harbor->id, null)->hash;
        $es->shouldReceive('vaultFacetFields')->andReturn([]);
        $es->shouldReceive('knnSearchVaultIndex')->once()->andReturn([
            'hits' => [[
                'id' => $harborHash, 'name' => 'Harbor at Dusk', 'slug' => 'harbor-at-dusk',
                'description' => 'Oil on canvas.', 'tags' => ['harbor'],
                'metadata' => ['technique' => 'oil'], 'score' => 0.93,
            ]],
            'total' => 1,
            'facets' => [],
        ]);

        $context = null;
        $this->mockLlm('The harbor appears in Harbor at Dusk, page 3.', $context);

        $result = app(VaultAskService::class)->ask($vault, 'What mentions the harbor?');

        // Context — all four block kinds
        $this->assertStringContainsString('[Vault]', $context);
        $this->assertStringContainsString('[Source: Harbor at Dusk, page 3]', $context);
        $this->assertStringContainsString('The harbor glows at dusk.', $context);
        $this->assertStringContainsString('[Resource: Harbor at Dusk (harbor-at-dusk)]', $context);
        $this->assertStringContainsString('technique: oil', $context);
        $this->assertStringContainsString('[Related]', $context);
        $this->assertStringContainsString('Harbor Sketches', $context);
        $this->assertStringContainsString('semantic, 0.88', $context);

        // Enumeration guarantee: Harbor Sketches never ranked in k-NN, but its
        // identity card still enters the context from the vault's own listing
        $this->assertStringContainsString('[Resource: Harbor Sketches', $context);

        // Answer + usage accounting — cards = 1 ranked + 1 from the
        // enumeration guarantee (Harbor Sketches, unranked but in the vault)
        $this->assertStringContainsString('Harbor at Dusk', $result['answer']);
        $this->assertSame(['chunks' => 1, 'cards' => 2, 'related' => 1], $result['used']);

        // Sources: resource-level only — slug + pages, never file ids
        $this->assertCount(1, $result['sources']);
        $this->assertSame([3], $result['sources'][0]['pages']);
        $this->assertArrayHasKey('slug', $result['sources'][0]);
        $this->assertArrayNotHasKey('file_id', $result['sources'][0]);
    }

    public function test_chunkless_vault_answers_from_identity_cards_alone(): void
    {
        // Gallery denies Tier 1 — the ask head must respect the vault's own policy
        $vault = $this->makeVault(VaultPurpose::GALLERY, ['indexed_at' => now()]);
        $piece = $this->makeResource('Red Piece');

        $this->mockEmbedder();

        $es = $this->mockEs();
        $es->shouldNotReceive('knnSearchChunksForWorkspace');
        $es->shouldNotReceive('searchChunksKeyword');
        $es->shouldReceive('vaultFacetFields')->andReturn([]);
        // Real vault-index cards carry the link hash as id — mirror that so the
        // enumeration guarantee recognizes this card as already ranked.
        $pieceHash = app(VaultLinkService::class)
            ->getOrCreateLink($vault, null, $piece->id, null)->hash;
        $es->shouldReceive('knnSearchVaultIndex')->once()->andReturn([
            'hits' => [[
                'id' => $pieceHash, 'name' => 'Red Piece', 'slug' => 'red-piece',
                'description' => null, 'tags' => [], 'metadata' => [], 'score' => 0.8,
            ]],
            'total' => 1,
            'facets' => [],
        ]);

        $context = null;
        $this->mockLlm('Only Red Piece matches.', $context);

        $result = app(VaultAskService::class)->ask($vault, 'anything red?');

        $this->assertSame(0, $result['used']['chunks']);
        $this->assertSame(1, $result['used']['cards']);
        $this->assertStringNotContainsString('[Source:', $context);
    }

    public function test_exposure_policy_rag_min_score_reaches_the_filter(): void
    {
        $vault = $this->makeVault(VaultPurpose::AI, [
            'indexed_at' => now(),
            'exposure_policy' => ['rag_min_score' => 0.5],
        ]);
        $this->makeResource('Doc');

        $this->mockEmbedder();

        $es = $this->mockEs();
        $es->shouldReceive('buildChunksIndexName')->zeroOrMoreTimes()->andReturnUsing(fn (string $n) => "{$n}_chunks");
        $es->shouldReceive('knnSearchChunksForWorkspace')->andReturn([]);
        $es->shouldReceive('vaultFacetFields')->andReturn([]);
        $es->shouldReceive('knnSearchVaultIndex')->andReturn(['hits' => [], 'total' => 0, 'facets' => []]);

        $this->mockLlm('answer');

        app(VaultAskService::class)->ask($vault, 'anything at all here?');

        // The vault owner's floor, not the instance default, reached the filter
        $this->assertSame(0.5, $this->ragFloorSeen);
    }

    public function test_empty_retrieval_short_circuits_without_llm_call(): void
    {
        $vault = $this->makeVault(VaultPurpose::AI); // indexed_at NULL → DB paths

        $llm = Mockery::mock(LlmServiceInterface::class);
        $llm->shouldNotReceive('chat');
        $this->app->instance(LlmServiceInterface::class, $llm);

        $es = $this->mockEs();
        $es->shouldReceive('buildChunksIndexName')->zeroOrMoreTimes()->andReturnUsing(fn (string $n) => "{$n}_chunks");
        $es->shouldReceive('knnSearchChunksForWorkspace')->andReturn([]);
        $this->mockEmbedder();

        $result = app(VaultAskService::class)->ask($vault, 'anything at all?');

        $this->assertStringContainsString('No relevant content', $result['answer']);
        $this->assertSame([], $result['sources']);
    }

    // =========================================================================
    // Endpoint
    // =========================================================================

    public function test_org_member_can_ask_even_unpublished_vaults(): void
    {
        $vault = $this->makeVault(VaultPurpose::AI, ['state' => VaultState::PRIVATE->value]);

        $es = $this->mockEs();
        $es->shouldReceive('buildChunksIndexName')->zeroOrMoreTimes()->andReturnUsing(fn (string $n) => "{$n}_chunks");
        $es->shouldReceive('knnSearchChunksForWorkspace')->andReturn([]);
        $this->mockEmbedder();
        $this->mockLlm('unused');

        $token = $this->user->createToken('t')->plainTextToken;

        $this->withToken($token)
            ->postJson("/api/v1/vaults/{$vault->id}/ask", ['question' => 'is anything here?'])
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.vault.slug', 'ai-vault');
    }

    public function test_non_member_gets_403(): void
    {
        $vault = $this->makeVault(VaultPurpose::AI);

        $stranger = User::factory()->create();
        $token = $stranger->createToken('t')->plainTextToken;

        $this->withToken($token)
            ->postJson("/api/v1/vaults/{$vault->id}/ask", ['question' => 'let me in?'])
            ->assertStatus(403);
    }

    public function test_question_is_validated(): void
    {
        $vault = $this->makeVault(VaultPurpose::AI);
        $token = $this->user->createToken('t')->plainTextToken;

        $this->withToken($token)
            ->postJson("/api/v1/vaults/{$vault->id}/ask", ['question' => 'hi'])
            ->assertStatus(422);
    }
}
