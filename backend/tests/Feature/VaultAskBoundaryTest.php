<?php

namespace Tests\Feature;

use App\Enums\VaultPurpose;
use App\Enums\VaultState;
use App\Models\Organization;
use App\Models\Vault;
use App\Models\VaultKey;
use App\Models\Workspace;
use App\Services\VaultAskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

/**
 * Epic 5.3 — `ask` at the vault boundary: POST /v/{org}/{vault}/ask and
 * /h/{hash}/ask. Reasoning is compute, so on top of the usual published/key
 * resolution it is gated by purpose (ai|mixed answer; others deny unless
 * exposure_policy.allow_ask opts in). The loop itself is covered by
 * VaultAskLoopTest — here we pin routing, gating, and validation.
 */
class VaultAskBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Workspace $ws;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organization::factory()->create(['slug' => 'acme']);
        $this->ws = Workspace::factory()->create(['organization_id' => $this->org->id]);
    }

    private function makeVault(VaultPurpose $purpose, array $overrides = []): Vault
    {
        $vault = Vault::factory()->purpose($purpose)->published()->create(
            array_merge(['organization_id' => $this->org->id, 'slug' => 'brain'], $overrides)
        );
        DB::table('workspace_vault')->insert(['workspace_id' => $this->ws->id, 'vault_id' => $vault->id]);

        return $vault;
    }

    private function mockAskService(): void
    {
        $ask = Mockery::mock(VaultAskService::class);
        $ask->shouldReceive('ask')->andReturn([
            'answer' => 'The harbor glows at dusk.',
            'sources' => [['resource_id' => 'r1', 'resource_name' => 'Harbor', 'slug' => 'harbor', 'url' => 'http://x/h/v/l', 'pages' => [3]]],
            'vault' => ['slug' => 'brain', 'name' => 'Brain', 'purpose' => 'ai'],
            'used' => ['chunks' => 1, 'cards' => 1, 'related' => 0],
        ]);
        $this->app->instance(VaultAskService::class, $ask);
    }

    public function test_ai_vault_answers_at_the_boundary(): void
    {
        $this->makeVault(VaultPurpose::AI);
        $this->mockAskService();

        $this->postJson('/v/acme/brain/ask', ['question' => 'What glows at dusk?'])
            ->assertStatus(200)
            ->assertJsonPath('answer', 'The harbor glows at dusk.')
            ->assertJsonPath('sources.0.slug', 'harbor');
    }

    public function test_accept_event_stream_streams_tokens_then_done(): void
    {
        $this->makeVault(VaultPurpose::AI);

        $ask = Mockery::mock(VaultAskService::class);
        $ask->shouldReceive('askStreamed')->once()->andReturnUsing(
            function ($vault, $question, $k, $onDelta) {
                foreach (['The harbor ', 'glows ', 'at dusk.'] as $frag) {
                    $onDelta($frag);
                }

                return [
                    'answer' => 'The harbor glows at dusk.',
                    'sources' => [['resource_id' => 'r1', 'resource_name' => 'Harbor', 'slug' => 'harbor', 'url' => 'http://x', 'pages' => [3]]],
                    'vault' => ['slug' => 'brain', 'name' => 'Brain', 'purpose' => 'ai'],
                    'used' => ['chunks' => 1, 'cards' => 0, 'related' => 0],
                ];
            }
        );
        $this->app->instance(VaultAskService::class, $ask);

        $response = $this->call(
            'POST', '/v/acme/brain/ask',
            ['question' => 'What glows at dusk?'],
            [], [], ['HTTP_ACCEPT' => 'text/event-stream'],
        );

        $response->assertStatus(200);
        $this->assertStringContainsString('text/event-stream', $response->headers->get('Content-Type'));

        $body = $response->streamedContent();
        // Three token frames in order…
        $this->assertStringContainsString('event: token', $body);
        $this->assertStringContainsString('"text":"The harbor "', $body);
        $this->assertStringContainsString('"text":"at dusk."', $body);
        // …then a done frame carrying the full result.
        $this->assertStringContainsString('event: done', $body);
        $this->assertStringContainsString('"answer":"The harbor glows at dusk."', $body);
        $this->assertStringContainsString('"slug":"harbor"', $body);
    }

    public function test_stream_path_still_enforces_the_ask_gate(): void
    {
        $this->makeVault(VaultPurpose::GALLERY);

        $this->call(
            'POST', '/v/acme/brain/ask',
            ['question' => 'What glows at dusk?'],
            [], [], ['HTTP_ACCEPT' => 'text/event-stream'],
        )->assertStatus(403);
    }

    public function test_hash_form_answers_too(): void
    {
        $vault = $this->makeVault(VaultPurpose::MIXED);
        $this->mockAskService();

        $this->postJson('/h/'.$vault->hash.'/ask', ['question' => 'What glows at dusk?'])
            ->assertStatus(200)
            ->assertJsonPath('answer', 'The harbor glows at dusk.');
    }

    public function test_non_ai_purposes_deny_reasoning(): void
    {
        $this->makeVault(VaultPurpose::GALLERY);

        $this->postJson('/v/acme/brain/ask', ['question' => 'What glows at dusk?'])
            ->assertStatus(403);
    }

    public function test_exposure_policy_can_opt_a_vault_in(): void
    {
        $this->makeVault(VaultPurpose::GALLERY, ['exposure_policy' => ['allow_ask' => true]]);
        $this->mockAskService();

        $this->postJson('/v/acme/brain/ask', ['question' => 'What glows at dusk?'])
            ->assertStatus(200);
    }

    public function test_unpublished_vault_hides_ask_without_key_and_answers_with_it(): void
    {
        $vault = $this->makeVault(VaultPurpose::AI, ['state' => VaultState::PRIVATE->value]);
        $this->mockAskService();

        $this->postJson('/v/acme/brain/ask', ['question' => 'What glows at dusk?'])
            ->assertStatus(404);

        [, $plaintext] = VaultKey::mint($vault, 'test');
        $this->postJson('/v/acme/brain/ask', ['question' => 'What glows at dusk?'], ['X-Vault-Key' => $plaintext])
            ->assertStatus(200);
    }

    public function test_question_is_validated(): void
    {
        $this->makeVault(VaultPurpose::AI);

        $this->postJson('/v/acme/brain/ask', ['question' => 'hi'])->assertStatus(422);
        $this->postJson('/v/acme/brain/ask', [])->assertStatus(422);
    }

    public function test_meta_advertises_the_ask_tier_by_purpose(): void
    {
        $this->makeVault(VaultPurpose::AI);
        $this->assertTrue($this->getJson('/v/acme/brain/meta')->json('tiers.ask'));

        Vault::query()->delete();
        $this->makeVault(VaultPurpose::GALLERY);
        $this->assertFalse($this->getJson('/v/acme/brain/meta')->json('tiers.ask'));
    }
}
