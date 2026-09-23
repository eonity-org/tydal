<?php

namespace Tests\Feature;

use App\Enums\FileRole;
use App\Enums\VaultAccessLevel;
use App\Enums\VaultCapability;
use App\Enums\VaultCredential;
use App\Enums\VaultPurpose;
use App\Jobs\RebuildVaultIndex;
use App\Models\Collection;
use App\Models\Organization;
use App\Models\Resource;
use App\Models\User;
use App\Models\Vault;
use App\Models\VaultKey;
use App\Models\VaultLink;
use App\Models\Workspace;
use App\Values\VaultPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The capability matrix (VAULT_SYSTEM.md §6.3) — purpose is a preset over the
 * exposure knobs and `exposure_policy` overrides any of them. These tests pin
 * the preset table itself, so a change to a default is a deliberate edit here
 * rather than a silent behavior shift in a consuming product.
 */
class VaultPolicyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The documented preset matrix. Deliberately *not* a ladder: gallery
     * exposes binary but not chunks; ai exposes chunks but not binary.
     *
     * @return array<string, array{0: VaultPurpose, 1: bool, 2: bool, 3: bool, 4: list<string>}>
     */
    public static function presetProvider(): array
    {
        $base = [FileRole::CANONICAL->value, FileRole::COMPONENT->value];
        $withSupporting = [...$base, FileRole::SUPPORTING->value];

        return [
            // purpose, chunks, binary, ask, chunk_roles, write_methods
            'delivery' => [VaultPurpose::DELIVERY, false, true, false, $base, []],
            'gallery' => [VaultPurpose::GALLERY, false, true, false, $base, ['activate', 'open', 'close']],
            'obsidian' => [VaultPurpose::OBSIDIAN, true, true, false, $base, []],
            'ai' => [VaultPurpose::AI, true, false, true, $withSupporting, ['ingest']],
            'mixed' => [VaultPurpose::MIXED, true, true, true, $base, []],
        ];
    }

    /**
     * @param  list<string>  $chunkRoles
     * @param  list<string>  $writeMethods
     */
    #[DataProvider('presetProvider')]
    public function test_preset_matrix_matches_the_spec(
        VaultPurpose $purpose,
        bool $chunks,
        bool $binary,
        bool $ask,
        array $chunkRoles,
        array $writeMethods,
    ): void {
        $vault = Vault::factory()->create(['purpose' => $purpose->value]);

        $this->assertSame($chunks, $vault->allowsChunks(), 'allowsChunks');
        $this->assertSame($binary, $vault->allowsBinary(), 'allowsBinary');
        $this->assertSame($ask, $vault->allowsAsk(), 'allowsAsk');
        $this->assertSame($chunkRoles, $vault->chunkRoles(), 'chunkRoles');
        $this->assertSame(
            [FileRole::CANONICAL->value, FileRole::COMPONENT->value],
            $vault->addressRoles(),
            'addressRoles',
        );

        foreach ($writeMethods as $method) {
            $this->assertTrue($vault->allowsWriteMethod($method), "allows {$method}");
        }
        $this->assertFalse($vault->allowsWriteMethod('nonsense'));
    }

    public function test_overrides_win_over_the_preset(): void
    {
        // The ImageLab case: an `ai` vault that must hand out source pixels.
        $vault = Vault::factory()->create([
            'purpose' => VaultPurpose::AI->value,
            'exposure_policy' => ['allow_binary' => true],
        ]);

        $this->assertTrue($vault->allowsBinary());
        $this->assertTrue($vault->allowsChunks(), 'untouched knobs keep their preset');
    }

    public function test_null_override_falls_back_to_the_preset(): void
    {
        // Clearing a field in the admin UI must restore the preset rather than
        // pin the knob to a falsy value.
        $vault = Vault::factory()->create([
            'purpose' => VaultPurpose::AI->value,
            'exposure_policy' => ['allow_binary' => null],
        ]);

        $this->assertFalse($vault->allowsBinary());
        $this->assertSame('preset', $vault->policy()->sourceOf(VaultCapability::ALLOW_BINARY));
    }

    public function test_effective_matrix_reports_provenance(): void
    {
        $policy = VaultPolicy::for(VaultPurpose::AI, ['allow_binary' => true]);
        $matrix = $policy->effective();

        $this->assertSame(true, $matrix['allow_binary']['value']);
        $this->assertSame('override', $matrix['allow_binary']['source']);
        $this->assertSame(true, $matrix['allow_chunks']['value']);
        $this->assertSame('preset', $matrix['allow_chunks']['source']);

        $this->assertSame(
            VaultCapability::values(),
            array_keys($matrix),
            'every capability is reported, in vocabulary order',
        );
    }

    public function test_write_methods_can_be_widened_by_policy(): void
    {
        $vault = Vault::factory()->create([
            'purpose' => VaultPurpose::MIXED->value,
            'exposure_policy' => ['write_methods' => ['ingest']],
        ]);

        $this->assertTrue($vault->allowsWriteMethod('ingest'));
    }

    // =========================================================================
    // Access levels — per-capability openness, independent of vaults.state
    // =========================================================================

    public function test_legacy_booleans_map_onto_levels(): void
    {
        $this->assertSame(VaultAccessLevel::INHERIT, VaultAccessLevel::coerce(true));
        $this->assertSame(VaultAccessLevel::DENIED, VaultAccessLevel::coerce(false));
        // Absent falls back to the preset, not to a level.
        $this->assertSame(VaultAccessLevel::INHERIT, VaultAccessLevel::coerce(null));
    }

    public function test_key_level_narrows_a_public_vault(): void
    {
        // The case the boolean model could not express: list publicly, but keep
        // the binaries behind a credential on the same vault.
        $org = Organization::factory()->create(['slug' => 'acme']);
        $vault = Vault::factory()->create([
            'organization_id' => $org->id,
            'slug' => 'my-vault',
            'purpose' => VaultPurpose::GALLERY->value,
            'state' => 'public',
            'exposure_policy' => ['allow_binary' => 'key'],
        ]);
        [, $plaintext] = VaultKey::mint($vault, 'reader');

        // Keyless: the vault is public, so identity answers but binary does not.
        $this->getJson('/v/acme/my-vault/meta')
            ->assertStatus(200)
            ->assertJsonPath('tiers.binary', false)
            ->assertJsonPath('capabilities.allow_binary.level', 'key');

        // With the key, the same vault exposes binary.
        $this->withHeader('X-Vault-Key', $plaintext)
            ->getJson('/v/acme/my-vault/meta')
            ->assertStatus(200)
            ->assertJsonPath('tiers.binary', true);
    }

    public function test_denied_level_refuses_even_with_a_key(): void
    {
        $org = Organization::factory()->create(['slug' => 'acme']);
        $vault = Vault::factory()->create([
            'organization_id' => $org->id,
            'slug' => 'my-vault',
            'purpose' => VaultPurpose::GALLERY->value,
            'state' => 'public',
            'exposure_policy' => ['allow_binary' => 'denied'],
        ]);
        [, $plaintext] = VaultKey::mint($vault, 'reader');

        $this->withHeader('X-Vault-Key', $plaintext)
            ->getJson('/v/acme/my-vault/meta')
            ->assertStatus(200)
            ->assertJsonPath('tiers.binary', false);
    }

    public function test_inherit_level_answers_without_a_key_on_a_public_vault(): void
    {
        $org = Organization::factory()->create(['slug' => 'acme']);
        Vault::factory()->create([
            'organization_id' => $org->id,
            'slug' => 'my-vault',
            'purpose' => VaultPurpose::GALLERY->value,
            'state' => 'public',
            'exposure_policy' => ['allow_binary' => 'inherit'],
        ]);

        $this->getJson('/v/acme/my-vault/meta')
            ->assertStatus(200)
            ->assertJsonPath('tiers.binary', true);
    }

    public function test_a_vault_outside_the_boundary_defaults_to_open(): void
    {
        // Admin API, jobs and tests load vaults without a credential; those
        // callers are authorized by org membership, so a `key` level must not
        // silently close the tier for them.
        $vault = Vault::factory()->create([
            'purpose' => VaultPurpose::GALLERY->value,
            'exposure_policy' => ['allow_binary' => 'inherit'],
        ]);

        $this->assertSame(VaultCredential::OPEN, $vault->credential());
        $this->assertTrue($vault->allowsBinary());
    }

    public function test_level_is_accepted_and_validated_over_the_api(): void
    {
        $this->putPolicy(['allow_binary' => 'key'])->assertStatus(200);
        $this->putPolicy(['allow_binary' => false])->assertStatus(200);

        $this->putPolicy(['allow_binary' => 'sometimes'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('exposure_policy.allow_binary');
    }

    // =========================================================================
    // Validation — the vocabulary is closed
    // =========================================================================

    public function test_unknown_policy_key_is_rejected(): void
    {
        $this->putPolicy(['allow_binaries' => true])
            ->assertStatus(422)
            ->assertJsonValidationErrors('exposure_policy');
    }

    public function test_known_policy_key_is_accepted(): void
    {
        $this->putPolicy(['allow_binary' => true])->assertStatus(200);
    }

    public function test_policy_values_are_typed(): void
    {
        $this->putPolicy(['allow_binary' => 'yes-please'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('exposure_policy.allow_binary');

        $this->putPolicy(['rag_min_score' => 5])
            ->assertStatus(422)
            ->assertJsonValidationErrors('exposure_policy.rag_min_score');

        $this->putPolicy(['chunk_roles' => ['not-a-role']])
            ->assertStatus(422)
            ->assertJsonValidationErrors('exposure_policy.chunk_roles.0');
    }

    /**
     * Regression guard: before the vocabulary was declared, a misspelt key was
     * accepted and then ignored, leaving the vault silently on its preset.
     */
    public function test_typo_no_longer_stores_a_dead_knob(): void
    {
        $vault = $this->vault();

        $this->asSuperAdmin()
            ->putJson("/api/v1/platform/vaults/{$vault->id}", [
                'exposure_policy' => ['allow_binaryy' => true],
            ])
            ->assertStatus(422);

        $this->assertFalse($vault->fresh()->allowsBinary());
    }

    // =========================================================================
    // Update semantics — a capability is not a hash-domain change
    // =========================================================================

    public function test_capability_change_does_not_purge_links(): void
    {
        $vault = $this->vault();
        $this->linkFor($vault);

        $this->asSuperAdmin()
            ->putJson("/api/v1/platform/vaults/{$vault->id}", [
                'exposure_policy' => ['allow_binary' => true],
            ])
            ->assertStatus(200);

        $this->assertDatabaseCount('vault_links', 1);
        $this->assertTrue($vault->fresh()->allowsBinary());
    }

    public function test_role_filter_change_rebuilds_the_index_without_purging(): void
    {
        Queue::fake();

        $vault = $this->vault();
        $vault->forceFill(['indexed_at' => now()])->saveQuietly();
        $this->linkFor($vault);

        $this->asSuperAdmin()
            ->putJson("/api/v1/platform/vaults/{$vault->id}", [
                'exposure_policy' => ['chunk_roles' => [FileRole::CANONICAL->value]],
            ])
            ->assertStatus(200);

        // Role filters change what the index contains, not the hash domain.
        $this->assertDatabaseCount('vault_links', 1);
        $this->assertNull($vault->fresh()->indexed_at);
        Queue::assertPushed(RebuildVaultIndex::class);
    }

    public function test_non_projection_capability_does_not_rebuild_the_index(): void
    {
        Queue::fake();

        $vault = $this->vault();
        $vault->forceFill(['indexed_at' => now()])->saveQuietly();

        $this->asSuperAdmin()
            ->putJson("/api/v1/platform/vaults/{$vault->id}", [
                'exposure_policy' => ['allow_binary' => true],
            ])
            ->assertStatus(200);

        $this->assertNotNull($vault->fresh()->indexed_at);
        Queue::assertNotPushed(RebuildVaultIndex::class);
    }

    // =========================================================================
    // The matrix endpoint the admin UI renders from
    // =========================================================================

    public function test_capabilities_endpoint_serves_vocabulary_and_presets(): void
    {
        $response = $this->asSuperAdmin()
            ->getJson('/api/v1/platform/vaults/capabilities')
            ->assertStatus(200);

        $keys = array_column($response->json('data.capabilities'), 'key');
        $this->assertSame(VaultCapability::values(), $keys);

        // The presets served must be the ones the backend actually applies.
        $this->assertFalse($response->json('data.presets.ai.values.allow_binary'));
        $this->assertTrue($response->json('data.presets.gallery.values.allow_binary'));
        $this->assertSame(
            ['activate', 'open', 'close'],
            $response->json('data.presets.gallery.values.write_methods'),
        );
    }

    // =========================================================================
    // vault:validate-policy — the CI guard for rows written before validation
    // =========================================================================

    public function test_validate_policy_passes_on_a_clean_policy(): void
    {
        Vault::factory()->create([
            'purpose' => VaultPurpose::GALLERY->value,
            'exposure_policy' => ['allow_chunks' => true],
        ]);

        $this->artisan('vault:validate-policy')
            ->assertExitCode(0);
    }

    public function test_validate_policy_fails_on_an_unknown_key(): void
    {
        // The exact shape the form requests now reject, but which older rows
        // (or a direct SQL edit) can still carry.
        $vault = Vault::factory()->create([
            'purpose' => VaultPurpose::AI->value,
            'exposure_policy' => ['allow_binaryy' => true],
        ]);

        $this->artisan('vault:validate-policy', ['--vault' => $vault->slug])
            ->expectsOutputToContain('unknown capability "allow_binaryy"')
            ->assertExitCode(1);
    }

    public function test_validate_policy_fails_on_a_dangling_ingest_target(): void
    {
        $vault = Vault::factory()->create([
            'purpose' => VaultPurpose::AI->value,
            'exposure_policy' => ['ingest' => ['workspace_id' => 99999, 'collection_id' => 99999]],
        ]);

        $this->artisan('vault:validate-policy', ['--vault' => $vault->slug])
            ->expectsOutputToContain('workspace 99999 not found')
            ->assertExitCode(1);
    }

    public function test_validate_policy_fails_when_ingest_is_accepted_with_no_target(): void
    {
        // An `ai` vault accepts `ingest` by preset; without a target the first
        // write fails at run time instead of at configuration time.
        $vault = Vault::factory()->create(['purpose' => VaultPurpose::AI->value]);

        $this->artisan('vault:validate-policy', ['--vault' => $vault->slug])
            ->expectsOutputToContain('no ingest target configured')
            ->assertExitCode(1);
    }

    public function test_validate_policy_notices_a_widening_without_failing(): void
    {
        // The ImageLab shape: deliberate, so it reports but does not fail CI.
        // Target and vault must share an org — that is the invariant being kept.
        $org = Organization::factory()->create();

        $vault = Vault::factory()->create([
            'organization_id' => $org->id,
            'purpose' => VaultPurpose::AI->value,
            'exposure_policy' => [
                'allow_binary' => true,
                'ingest' => [
                    'workspace_id' => Workspace::factory()->create(['organization_id' => $org->id])->id,
                    'collection_id' => Collection::factory()->create(['organization_id' => $org->id])->id,
                ],
            ],
        ]);

        $this->artisan('vault:validate-policy', ['--vault' => $vault->slug])
            ->expectsOutputToContain('an `ai` vault exposing binary')
            ->assertExitCode(0);
    }

    public function test_validate_policy_rejects_a_target_in_another_organization(): void
    {
        // The landing spot is vault-configured precisely so a consumer cannot
        // steer output; a cross-org target would defeat that.
        $vault = Vault::factory()->create([
            'purpose' => VaultPurpose::AI->value,
            'exposure_policy' => [
                'ingest' => [
                    'workspace_id' => Workspace::factory()->create()->id,
                    'collection_id' => Collection::factory()->create()->id,
                ],
            ],
        ]);

        $this->artisan('vault:validate-policy', ['--vault' => $vault->slug])
            ->expectsOutputToContain("not found in this vault's organization")
            ->assertExitCode(1);
    }

    // =========================================================================
    // Back-compat — the published surface is unchanged
    // =========================================================================

    /**
     * `/meta`'s tiers block is what @tydal/client, vault-mcp, Full Frame and
     * ImageLab read. Moving the presets into VaultPolicy must not move it.
     */
    #[DataProvider('presetProvider')]
    public function test_meta_tiers_block_is_unchanged(
        VaultPurpose $purpose,
        bool $chunks,
        bool $binary,
        bool $ask,
    ): void {
        $org = Organization::factory()->create(['slug' => 'acme']);
        Vault::factory()->create([
            'organization_id' => $org->id,
            'slug' => 'my-vault',
            'purpose' => $purpose->value,
            'state' => 'public',
        ]);

        $this->getJson('/v/acme/my-vault/meta')
            ->assertStatus(200)
            ->assertJsonPath('purpose', $purpose->value)
            ->assertJsonPath('tiers.identity', true)
            ->assertJsonPath('tiers.chunks', $chunks)
            ->assertJsonPath('tiers.binary', $binary)
            ->assertJsonPath('tiers.ask', $ask);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function vault(): Vault
    {
        return Vault::factory()->create(['purpose' => VaultPurpose::AI->value]);
    }

    private function linkFor(Vault $vault): VaultLink
    {
        return VaultLink::factory()->create([
            'vault_id' => $vault->id,
            'workspace_id' => Workspace::factory()->create()->id,
            'resource_id' => Resource::factory()->create()->id,
        ]);
    }

    /** @param array<string, mixed> $policy */
    private function putPolicy(array $policy): TestResponse
    {
        $vault = $this->vault();

        return $this->asSuperAdmin()
            ->putJson("/api/v1/platform/vaults/{$vault->id}", ['exposure_policy' => $policy]);
    }

    private function asSuperAdmin(): self
    {
        $token = User::factory()->superAdmin()->create()->createToken('test')->plainTextToken;

        return $this->withToken($token);
    }
}
