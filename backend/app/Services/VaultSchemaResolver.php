<?php

namespace App\Services;

use App\Enums\ResourceState;
use App\Enums\VaultPurpose;
use App\Models\CollectionScheme;
use App\Models\Vault;
use App\Models\VaultSchemaOverlay;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The semantic mapping engine (Epic 3.1, VAULT_SYSTEM.md §6 / ARCH §3.3):
 * resolves what each schema field MEANS inside a given vault purpose.
 *
 * Fields are free (schemes are fully user-configurable); the ROLE VOCABULARY
 * is the fixed contract clients depend on. Resolution is layered:
 *
 *   structural preset (contract flags, never field names)
 *     ← scheme `vault_roles` (the scheme author's intent)
 *       ← vault_schema_overlays row (this vault's override)
 *
 * Resource built-ins (name, description, snapshot, tags) fill the core slots
 * unconditionally — every scheme has them because they are columns, not
 * fields. Unknown fields in overlays are ignored (drift tolerance): a scheme
 * edit must never 500 a published exhibition.
 */
class VaultSchemaResolver
{
    /**
     * The fixed presentation vocabulary, per purpose. `mixed` accepts the
     * union; `delivery` has no presentation semantics (it serves bytes).
     */
    public const ROLE_VOCABULARY = [
        'gallery' => ['caption', 'subcaption', 'image', 'badge', 'detail', 'credit', 'hidden'],
        'obsidian' => ['node_label', 'body', 'property', 'link_source', 'hidden'],
        'ai' => ['identity', 'retrieval_text', 'facet', 'context', 'hidden'],
    ];

    /**
     * Core slots served by resource BUILT-INS — stable for every scheme.
     *
     * @return array<string, string>
     */
    public static function coreSlots(VaultPurpose $purpose): array
    {
        return match ($purpose) {
            VaultPurpose::GALLERY => ['caption' => 'name', 'subcaption' => 'description', 'image' => 'snapshot', 'badge' => 'tags'],
            VaultPurpose::OBSIDIAN => ['node_label' => 'name', 'body' => 'description', 'property' => 'tags'],
            VaultPurpose::AI => ['identity' => 'name', 'context' => 'description', 'facet' => 'tags'],
            default => [],
        };
    }

    /** @return list<string> */
    public static function slotsFor(string $purpose): array
    {
        if ($purpose === VaultPurpose::MIXED->value) {
            return array_values(array_unique(array_merge(...array_values(self::ROLE_VOCABULARY))));
        }

        return self::ROLE_VOCABULARY[$purpose] ?? [];
    }

    /** Purposes that may appear as keys in vault_roles / overlay maps. */
    public static function mappablePurposes(): array
    {
        return array_keys(self::ROLE_VOCABULARY);
    }

    /**
     * The resolved presentation of a vault: one block per scheme whose
     * resources the vault projects. This is what get_vault / /meta ship so
     * decoupled clients never resolve anything themselves.
     *
     * @return list<array{scheme: string, scheme_name: string, core: array<string, string>, fields: array<string, string>}>
     */
    public function presentationFor(Vault $vault): array
    {
        $purpose = $vault->purpose->value;

        if ($purpose === VaultPurpose::DELIVERY->value) {
            return [];
        }

        $overlays = VaultSchemaOverlay::where('vault_id', $vault->id)
            ->get()
            ->keyBy('scheme_id');

        $blocks = [];
        foreach ($this->projectedSchemes($vault) as $scheme) {
            $overlay = $overlays->get($scheme->id);

            $blocks[] = [
                'scheme' => $scheme->id,
                'scheme_name' => $scheme->name,
                'core' => self::coreSlots($vault->purpose),
                'fields' => $this->resolveFields($scheme, $purpose, $overlay !== null ? $overlay->field_roles : []),
            ];
        }

        return $blocks;
    }

    /**
     * Resolve every field of a scheme to its slot for one purpose.
     *
     * @param  array<string, array<string, string>>  $overlayRoles  field → purpose → slot
     * @return array<string, string> field → slot
     */
    public function resolveFields(CollectionScheme $scheme, string $purpose, array $overlayRoles = []): array
    {
        $resolved = [];
        $validSlots = self::slotsFor($purpose);

        foreach ($scheme->fields ?? [] as $field) {
            $name = $field['name'] ?? null;
            if (! is_string($name) || $name === '') {
                continue;
            }

            // 3 — vault overlay wins (drift-tolerant: only known fields reach here)
            $slot = $overlayRoles[$name][$purpose] ?? null;

            // 2 — scheme author's suggestion
            $slot ??= $field['vault_roles'][$purpose] ?? null;

            // 1 — structural preset from contract flags (never field names)
            $slot ??= $this->presetSlot($field, $purpose);

            // Vocabulary is the contract — anything else degrades to hidden
            $resolved[$name] = in_array($slot, $validSlots, true) ? $slot : 'hidden';
        }

        return $resolved;
    }

    /**
     * Structural preset: derive a default slot from the field's own contract
     * flags. Works for ANY scheme because it never assumes a field name.
     */
    private function presetSlot(array $field, string $purpose): string
    {
        $isFacet = ($field['is_facet'] ?? false) === true;
        $inForm = ($field['display_in_form'] ?? false) === true;

        return match ($purpose) {
            'gallery' => $isFacet ? 'badge' : ($inForm ? 'detail' : 'hidden'),
            'obsidian' => ($isFacet || $inForm) ? 'property' : 'hidden',
            'ai' => $isFacet ? 'facet' : ($inForm ? 'context' : 'hidden'),
            default => 'hidden',
        };
    }

    /**
     * Distinct schemes of the collections whose resources this vault
     * projects — same scope rule as the vault index.
     *
     * @return Collection<int, CollectionScheme>
     */
    private function projectedSchemes(Vault $vault): Collection
    {
        $wsIds = DB::table('workspace_vault')->where('vault_id', $vault->id)->pluck('workspace_id');

        $collectionIds = DB::table('resources')
            ->where('state', ResourceState::LIVE->value)
            ->whereNull('deleted_at')
            ->where(function ($q) use ($wsIds, $vault) {
                $q->whereIn('id', function ($sub) use ($wsIds) {
                    $sub->select('resource_id')->from('dam_resource_workspace')->whereIn('workspace_id', $wsIds);
                });

                if ($vault->has_public_workspace) {
                    $q->orWhere('organization_id', $vault->organization_id);
                }
            })
            ->distinct()
            ->pluck('collection_id')
            ->filter();

        $schemeIds = DB::table('collections')->whereIn('id', $collectionIds)->distinct()->pluck('scheme_id')->filter();

        return CollectionScheme::whereIn('id', $schemeIds)->get();
    }
}
