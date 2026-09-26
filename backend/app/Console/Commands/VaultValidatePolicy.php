<?php

namespace App\Console\Commands;

use App\Enums\VaultCapability;
use App\Enums\VaultPurpose;
use App\Models\Collection;
use App\Models\Vault;
use App\Models\Workspace;
use Illuminate\Console\Command;

/**
 * Validate every vault's `exposure_policy` against the capability vocabulary.
 *
 * The form requests reject unknown keys on write, but rows created before that
 * validation existed (or edited straight in SQL) can still carry a misspelt key
 * — which reads as "silently on the preset" rather than as an error. This
 * command fails (exit 1) on any such row so CI can hold the line, and reports
 * deliberate widenings separately as notices rather than failures.
 *
 * Sibling of `schema:validate` and `resources:audit-roles`.
 * See docs/VAULT_SYSTEM.md §6.3 for the capability contract.
 */
class VaultValidatePolicy extends Command
{
    protected $signature = 'vault:validate-policy
                            {--vault= : Only validate one vault (id, hash or slug)}
                            {--quiet-notices : Suppress the widening notices, report only errors}';

    protected $description = 'Validate vault exposure policies against the capability vocabulary';

    public function handle(): int
    {
        $query = Vault::query();

        if ($this->option('vault') !== null) {
            $needle = $this->option('vault');
            $query->where(fn ($q) => $q->where('id', $needle)
                ->orWhere('hash', $needle)
                ->orWhere('slug', $needle));
        }

        $vaults = $query->orderBy('slug')->get();

        if ($vaults->isEmpty()) {
            $this->warn('No vaults matched.');

            return self::SUCCESS;
        }

        $errorTotal = 0;
        $noticeTotal = 0;

        foreach ($vaults as $vault) {
            $errors = $this->validate($vault);
            $notices = $this->option('quiet-notices') ? [] : $this->notices($vault);

            if ($errors === [] && $notices === []) {
                $this->info("✓ {$vault->slug}");

                continue;
            }

            if ($errors === []) {
                $this->line("· {$vault->slug}");
            } else {
                $this->error("✗ {$vault->slug}");
            }

            foreach ($errors as $error) {
                $this->line("    - {$error}");
            }
            foreach ($notices as $notice) {
                $this->line("    ~ {$notice}");
            }

            $errorTotal += count($errors);
            $noticeTotal += count($notices);
        }

        $this->newLine();

        if ($noticeTotal > 0) {
            $this->line("{$noticeTotal} policy widening(s) noted (~) — deliberate overrides, not errors.");
        }

        if ($errorTotal > 0) {
            $this->error("{$errorTotal} policy violation(s) found.");

            return self::FAILURE;
        }

        $this->info('All vault policies valid.');

        return self::SUCCESS;
    }

    /**
     * Hard failures: a policy the boundary cannot honour as written.
     *
     * @return list<string>
     */
    private function validate(Vault $vault): array
    {
        // The ingest target is checked even with no policy document at all: an
        // `ai` vault accepts the op by preset, so "no overrides" is exactly the
        // case where a missing landing spot goes unnoticed until the first write.
        $policy = $vault->exposure_policy ?? [];

        if ($policy === []) {
            return $this->validateIngestTarget($vault);
        }

        $errors = [];

        foreach (VaultCapability::unknownKeys($policy) as $key) {
            $errors[] = "unknown capability \"{$key}\" — it is ignored at read time, so this vault "
                .'silently runs on its preset (known keys: '.implode(', ', VaultCapability::values()).')';
        }

        foreach ([VaultCapability::ALLOW_CHUNKS, VaultCapability::ALLOW_BINARY, VaultCapability::ALLOW_ASK] as $flag) {
            $value = $policy[$flag->value] ?? null;
            if ($value !== null && ! is_bool($value)) {
                $errors[] = "{$flag->value}: expected a boolean, got ".gettype($value);
            }
        }

        foreach ([VaultCapability::ADDRESS_ROLES, VaultCapability::CHUNK_ROLES, VaultCapability::WRITE_METHODS] as $list) {
            $value = $policy[$list->value] ?? null;
            if ($value !== null && ! is_array($value)) {
                $errors[] = "{$list->value}: expected a list, got ".gettype($value);
            }
        }

        $score = $policy[VaultCapability::RAG_MIN_SCORE->value] ?? null;
        if ($score !== null && (! is_numeric($score) || $score < 0 || $score > 1)) {
            $errors[] = 'rag_min_score: expected a number between 0 and 1';
        }

        return array_merge($errors, $this->validateIngestTarget($vault));
    }

    /**
     * The ingest target (every purpose's `ingest`) must exist and
     * belong to the vault's org — the consumer never chooses where its output
     * lands, so a dangling target is a write that fails at run time instead of
     * at configuration time.
     *
     * @return list<string>
     */
    private function validateIngestTarget(Vault $vault): array
    {
        $target = $vault->exposure_policy[VaultCapability::INGEST->value] ?? null;

        if ($target === null) {
            // An ai vault exists to take `ingest`; with nowhere to put the result
            // it fails on the first write. Other purposes accept `ingest` only
            // as an option, so a missing target there is not a finding.
            return $vault->purpose === VaultPurpose::AI && $vault->allowsWriteMethod('ingest')
                ? ['ingest: this vault accepts the `ingest` op but has no ingest target configured']
                : [];
        }

        if (! is_array($target)) {
            return ['ingest: expected an object with workspace_id and collection_id'];
        }

        $errors = [];
        $workspaceId = $target['workspace_id'] ?? null;
        $collectionId = $target['collection_id'] ?? null;

        if ($workspaceId === null || $collectionId === null) {
            $errors[] = 'ingest: both workspace_id and collection_id are required';

            return $errors;
        }

        $workspace = Workspace::where('organization_id', $vault->organization_id)->find($workspaceId);
        if (! $workspace) {
            $errors[] = "ingest: workspace {$workspaceId} not found in this vault's organization";
        }

        $collection = Collection::where('organization_id', $vault->organization_id)->find($collectionId);
        if (! $collection) {
            $errors[] = "ingest: collection {$collectionId} not found in this vault's organization";
        }

        // A gallery's ingest lands photographs in this workspace; unless the
        // vault projects it they are written but never shown. (An active
        // selection legitimately detaches it until `close`.)
        if ($workspace
            && $vault->purpose === VaultPurpose::GALLERY
            && $vault->allowsWriteMethod('ingest')
            && ! $vault->has_public_workspace
            && $vault->selection_snapshot === null
            && ! $vault->workspaces()->where('workspaces.id', $workspace->id)->exists()
        ) {
            $errors[] = "ingest: workspace {$workspaceId} is not attached to this vault, so ingested photographs would not appear";
        }

        return $errors;
    }

    /**
     * Not failures — overrides that widen a preset in a way worth seeing on one
     * screen, because they trade away a default the purpose chose on purpose.
     *
     * @return list<string>
     */
    private function notices(Vault $vault): array
    {
        $policy = $vault->exposure_policy;

        if (empty($policy)) {
            return [];
        }

        $notices = [];

        if ($vault->purpose === VaultPurpose::AI && ($policy['allow_binary'] ?? null) === true) {
            $notices[] = 'allow_binary: an `ai` vault exposing binary — deliberate for a transforming '
                .'consumer (VAULT_SYSTEM.md §6.3), but it means a read key reaches the bytes';
        }

        if (($policy['allow_ask'] ?? null) === true && ! in_array($vault->purpose, [VaultPurpose::AI, VaultPurpose::MIXED], true)) {
            $notices[] = 'allow_ask: a non-AI vault answering at the boundary — it can be farmed for LLM tokens';
        }

        $extraWrites = array_diff($policy['write_methods'] ?? [], $vault->purpose->writeMethods());
        if ($extraWrites !== []) {
            $notices[] = 'write_methods: widened beyond the purpose vocabulary with '.implode(', ', $extraWrites);
        }

        return $notices;
    }
}
