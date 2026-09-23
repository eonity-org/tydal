<?php

namespace App\Console\Commands;

use App\Models\SearchIndex;
use App\Services\ElasticsearchService;
use App\Support\IndexVocabulary;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Show what actually lives in each search index: its collections, the schemes
 * behind them, and the field vocabulary they merge into.
 *
 * An ES index is one mapping shared by every document in it, so several schemes
 * on one index form a contract — a field name means one thing there. Nothing
 * made that visible before: `SchemesTab` lists indexes but only edits their
 * visibility, and no surface showed *which schemes share an index*, which is
 * the one fact you need before deciding whether they agree.
 *
 *   php artisan search:indexes                # every index
 *   php artisan search:indexes --index=NAME   # one, in detail
 *   php artisan search:indexes --check        # problems only, exit 1 on conflicts
 *   php artisan search:indexes --check --strict   # divergences fail too
 *
 * Sibling of `schema:validate` (one scheme's fields, in isolation) —
 * this is the cross-scheme axis that only exists once an index is shared.
 */
class SearchIndexes extends Command
{
    protected $signature = 'search:indexes
        {--index= : Only inspect one index by name}
        {--check : Report only problems; exit 1 when a conflict is found}
        {--strict : With --check, divergences fail the run too}';

    protected $description = 'Inspect search indexes: their collections, schemes and merged field vocabulary';

    public function handle(ElasticsearchService $es): int
    {
        $query = SearchIndex::query()->orderBy('index_name');

        if ($name = $this->option('index')) {
            $query->where('index_name', $name);
        }

        $indexes = $query->get();

        if ($indexes->isEmpty()) {
            $this->warn('No search indexes matched.');

            return self::SUCCESS;
        }

        $check = (bool) $this->option('check');
        $strict = (bool) $this->option('strict');
        $conflictTotal = 0;
        $divergenceTotal = 0;

        foreach ($indexes as $index) {
            $collections = $index->collections()->with('scheme')->orderBy('id')->get();
            $vocabulary = $es->vocabularyFor($index);

            $conflictTotal += count($vocabulary->conflicts());
            $divergenceTotal += count($vocabulary->divergences());

            if ($check && ! $vocabulary->hasConflicts() && $vocabulary->divergences() === []) {
                continue;
            }

            $this->heading($index, $collections->count());

            if (! $check) {
                $this->collections($collections);
                $this->vocabulary($vocabulary);
            }

            $this->problems($vocabulary);
            $this->newLine();
        }

        return $this->summary($check, $strict, $conflictTotal, $divergenceTotal);
    }

    private function heading(SearchIndex $index, int $collectionCount): void
    {
        $state = $index->is_active ? '' : ' <fg=yellow>[inactive]</>';
        $visibility = $index->visibility->value;

        $this->line("<options=bold>{$index->index_name}</> <fg=gray>({$index->display_name} · {$visibility} · "
            ."{$collectionCount} collection(s))</>{$state}");
    }

    /** @param Collection<int, \App\Models\Collection> $collections */
    private function collections($collections): void
    {
        if ($collections->isEmpty()) {
            // Nothing points here, so the mapping would be the base fields only.
            $this->line('  <fg=yellow>no collections — this index has no scheme-derived mapping</>');

            return;
        }

        foreach ($collections as $collection) {
            $scheme = $collection->scheme->name ?? '<fg=yellow>no scheme</>';
            $this->line("  <fg=gray>collection</> {$collection->slug} <fg=gray>(#{$collection->id}) →</> {$scheme}");
        }
    }

    private function vocabulary(IndexVocabulary $vocabulary): void
    {
        $terms = $vocabulary->terms();

        if ($terms === []) {
            return;
        }

        $this->line('  <fg=gray>vocabulary ('.count($terms).' term(s)):</>');

        $width = max(array_map('strlen', array_keys($terms)));

        foreach ($terms as $name => $term) {
            $mapping = IndexVocabulary::describe($term['es']);
            $facet = $term['is_facet'] ? ' <fg=cyan>facet</>' : '';
            // More than one scheme on a term is the shared-vocabulary case
            // working: one field, one type, many schemes.
            $shared = count($term['schemes']) > 1 ? ' <fg=gray>×'.count($term['schemes']).'</>' : '';

            $this->line(sprintf(
                '    %-'.$width.'s  <fg=green>%-14s</>%s%s <fg=gray>%s</>',
                $name, $mapping, $facet, $shared, implode(', ', $term['schemes'])
            ));
        }
    }

    private function problems(IndexVocabulary $vocabulary): void
    {
        foreach ($vocabulary->conflicts() as $conflict) {
            $this->line("  <fg=red>✗</> {$conflict}");
        }

        foreach ($vocabulary->divergences() as $divergence) {
            $this->line("  <fg=yellow>~</> {$divergence}");
        }

        if (! $this->option('check') && ! $vocabulary->hasConflicts() && $vocabulary->divergences() === []) {
            $this->line('  <fg=green>✓</> no conflicts');
        }
    }

    private function summary(bool $check, bool $strict, int $conflicts, int $divergences): int
    {
        if ($conflicts === 0 && $divergences === 0) {
            $this->info('All index vocabularies coherent.');

            return self::SUCCESS;
        }

        if ($divergences > 0) {
            $this->line("{$divergences} divergence(s) (~) — same field, same type, different meaning.");
        }

        if ($conflicts > 0) {
            $this->error("{$conflicts} vocabulary conflict(s) — `search:setup-indices` will refuse these indexes.");
        }

        if (! $check) {
            return self::SUCCESS;
        }

        return $conflicts > 0 || ($strict && $divergences > 0) ? self::FAILURE : self::SUCCESS;
    }
}
