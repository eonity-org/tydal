<?php

namespace App\Values;

use App\Enums\FileRole;
use App\Enums\VaultAccessLevel;
use App\Enums\VaultCapability;
use App\Enums\VaultPurpose;

/**
 * The effective exposure policy of a vault: the purpose preset overlaid with
 * the vault's own `exposure_policy` overrides (VAULT_SYSTEM.md §6.3 — "Purpose
 * = a preset over the exposure knobs; Admin can override any of them").
 *
 * This is the one place the preset matrix lives. `Vault` used to carry it as
 * five separate `match ($this->purpose)` blocks, which meant the matrix could
 * only be read by calling six methods on a model instance — the admin UI could
 * not show what a preset grants, and switching purpose could not be previewed.
 *
 * A `null` override means "fall back to the preset", matching the `??`
 * semantics the model used before and giving the UI a natural reset gesture.
 */
final class VaultPolicy
{
    /** Capabilities gated by an access level rather than a plain value. */
    private const GATED = [
        VaultCapability::ALLOW_CHUNKS,
        VaultCapability::ALLOW_BINARY,
        VaultCapability::ALLOW_ASK,
    ];

    /**
     * @param  array<array-key, mixed>  $overrides
     */
    private function __construct(
        private readonly VaultPurpose $purpose,
        private readonly array $overrides,
    ) {}

    /**
     * @param  array<array-key, mixed>|null  $overrides  The vault's exposure_policy
     */
    public static function for(VaultPurpose $purpose, ?array $overrides = null): self
    {
        return new self($purpose, $overrides ?? []);
    }

    /**
     * The preset matrix — every purpose default, in one readable table.
     *
     * Note it is deliberately *not* a ladder: `gallery` exposes binary but not
     * chunks, while `ai` exposes chunks but not binary (metadata/chunk-first,
     * so a vault key cannot be farmed for bytes). Any UI that presents these as
     * cumulative levels will misrepresent the model.
     *
     * @return array<string, mixed>
     */
    public static function presetFor(VaultPurpose $purpose): array
    {
        return [
            VaultCapability::ALLOW_CHUNKS->value => match ($purpose) {
                VaultPurpose::AI, VaultPurpose::OBSIDIAN, VaultPurpose::MIXED => true,
                VaultPurpose::DELIVERY, VaultPurpose::GALLERY => false,
            },
            VaultCapability::ALLOW_BINARY->value => match ($purpose) {
                VaultPurpose::AI => false,
                default => true,
            },
            VaultCapability::ALLOW_ASK->value => match ($purpose) {
                VaultPurpose::AI, VaultPurpose::MIXED => true,
                default => false,
            },
            // Supporting files stay unaddressed everywhere; in `ai` vaults their
            // text still feeds Tier 1 (lyrics, transcripts) without their bytes
            // becoming reachable.
            VaultCapability::ADDRESS_ROLES->value => [
                FileRole::CANONICAL->value,
                FileRole::COMPONENT->value,
            ],
            VaultCapability::CHUNK_ROLES->value => $purpose === VaultPurpose::AI
                ? [FileRole::CANONICAL->value, FileRole::COMPONENT->value, FileRole::SUPPORTING->value]
                : [FileRole::CANONICAL->value, FileRole::COMPONENT->value],
            VaultCapability::WRITE_METHODS->value => $purpose->writeMethods(),
            // No preset: null = fall back to the instance default
            // (elasticsearch.rag_min_score).
            VaultCapability::RAG_MIN_SCORE->value => null,
            // No preset: the ingest landing spot is per-vault configuration.
            VaultCapability::INGEST->value => null,
        ];
    }

    /** The effective value of one capability. */
    public function value(VaultCapability $capability): mixed
    {
        if ($this->isOverridden($capability)) {
            return $this->overrides[$capability->value];
        }

        return self::presetFor($this->purpose)[$capability->value];
    }

    /**
     * The effective access level of a gated capability (`allow_chunks`,
     * `allow_binary`, `allow_ask`). Presets are booleans, so they coerce:
     * `true` → inherit, `false` → denied. A vault may override with an explicit
     * level to require a key on a capability its own `state` would otherwise
     * leave open.
     */
    public function levelOf(VaultCapability $capability): VaultAccessLevel
    {
        return VaultAccessLevel::coerce($this->value($capability));
    }

    /**
     * Whether this vault set the capability itself. A `null` entry counts as
     * absent, so clearing a field in the admin UI restores the preset.
     */
    public function isOverridden(VaultCapability $capability): bool
    {
        return array_key_exists($capability->value, $this->overrides)
            && $this->overrides[$capability->value] !== null;
    }

    /** @return 'preset'|'override' */
    public function sourceOf(VaultCapability $capability): string
    {
        return $this->isOverridden($capability) ? 'override' : 'preset';
    }

    /**
     * The whole matrix with provenance — what `/meta` publishes and the admin
     * UI renders, so an operator can see which values came from the preset and
     * which this vault chose.
     *
     * @return array<string, array{value: mixed, source: string}>
     */
    public function effective(): array
    {
        $matrix = [];

        foreach (VaultCapability::cases() as $capability) {
            $entry = [
                'value' => $this->value($capability),
                'source' => $this->sourceOf($capability),
            ];

            // Gated capabilities also report the resolved level, so a consumer
            // can tell "open because the vault is public" from "open only to a
            // key" without re-deriving it from the boolean.
            if (in_array($capability, self::GATED, true)) {
                $entry['level'] = $this->levelOf($capability)->value;
            }

            $matrix[$capability->value] = $entry;
        }

        return $matrix;
    }
}
