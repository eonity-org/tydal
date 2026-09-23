<?php

namespace Tests\Unit;

use App\Models\CollectionScheme;
use App\Support\IndexVocabulary;
use PHPUnit\Framework\TestCase;

/**
 * The field vocabulary of a shared index (docs/CLI.md § search:indexes).
 *
 * An ES index holds one mapping, so several schemes sharing it must agree on
 * what a field name means. These tests pin the two kinds of disagreement apart:
 * a conflict cannot be represented in ES at all, a divergence can — it just
 * means something different to the humans and the consumers reading it.
 */
class IndexVocabularyTest extends TestCase
{
    private function scheme(string $name, array $fields): CollectionScheme
    {
        return new CollectionScheme(['name' => $name, 'fields' => $fields]);
    }

    private function field(string $name, array $overrides = []): array
    {
        return array_merge([
            'name' => $name,
            'type' => 'string',
            'storage' => 'metadata',
            'es_type' => 'keyword',
        ], $overrides);
    }

    public function test_it_unions_fields_across_schemes(): void
    {
        $vocabulary = IndexVocabulary::of([
            $this->scheme('a', [$this->field('kind'), $this->field('license')]),
            $this->scheme('b', [$this->field('license'), $this->field('format')]),
        ]);

        // The whole point: the second scheme's fields are mapped too, instead
        // of being left to ES's dynamic inference.
        $this->assertSame(['kind', 'license', 'format'], array_keys($vocabulary->properties()));
        $this->assertSame([], $vocabulary->conflicts());
    }

    public function test_a_field_declared_identically_by_two_schemes_is_one_term(): void
    {
        $vocabulary = IndexVocabulary::of([
            $this->scheme('a', [$this->field('license')]),
            $this->scheme('b', [$this->field('license')]),
        ]);

        $this->assertCount(1, $vocabulary->terms());
        $this->assertSame(['a', 'b'], $vocabulary->terms()['license']['schemes']);
        $this->assertSame([], $vocabulary->divergences());
    }

    public function test_incompatible_es_types_conflict(): void
    {
        $vocabulary = IndexVocabulary::of([
            $this->scheme('a', [$this->field('year', ['type' => 'integer', 'es_type' => 'integer'])]),
            $this->scheme('b', [$this->field('year')]),
        ]);

        $this->assertTrue($vocabulary->hasConflicts());
        $this->assertStringContainsString('a declares integer', $vocabulary->conflicts()[0]);
        $this->assertStringContainsString('b declares keyword', $vocabulary->conflicts()[0]);
    }

    public function test_subfield_order_is_not_a_conflict(): void
    {
        $subfields = fn (array $order) => $this->field('author', [
            'es_type' => 'text',
            'es_fields' => $order,
        ]);

        $vocabulary = IndexVocabulary::of([
            $this->scheme('a', [$subfields(['keyword' => ['type' => 'keyword'], 'raw' => ['type' => 'keyword']])]),
            $this->scheme('b', [$subfields(['raw' => ['type' => 'keyword'], 'keyword' => ['type' => 'keyword']])]),
        ]);

        $this->assertSame([], $vocabulary->conflicts());
    }

    public function test_column_and_index_only_fields_are_not_part_of_the_vocabulary(): void
    {
        $vocabulary = IndexVocabulary::of([
            $this->scheme('a', [
                $this->field('name', ['storage' => 'column']),
                $this->field('ghost', ['storage' => 'index_only']),
                $this->field('license'),
            ]),
        ]);

        // `column` fields are already in the base mapping; only metadata.<name>
        // is scheme-derived.
        $this->assertSame(['license'], array_keys($vocabulary->properties()));
    }

    public function test_facet_disagreement_is_a_divergence_not_a_conflict(): void
    {
        $vocabulary = IndexVocabulary::of([
            $this->scheme('a', [$this->field('license', ['is_facet' => true])]),
            $this->scheme('b', [$this->field('license', ['is_facet' => false])]),
        ]);

        $this->assertSame([], $vocabulary->conflicts());
        $this->assertStringContainsString('a facet in a but not in b', $vocabulary->divergences()[0]);
    }

    public function test_label_and_option_list_disagreements_are_divergences(): void
    {
        $vocabulary = IndexVocabulary::of([
            $this->scheme('a', [$this->field('license', [
                'facet_label' => 'Licence',
                'validators' => ['in' => ['cc0', 'cc-by']],
            ])]),
            $this->scheme('b', [$this->field('license', [
                'facet_label' => 'License',
                'validators' => ['in' => ['cc0']],
            ])]),
        ]);

        $this->assertSame([], $vocabulary->conflicts());
        $this->assertCount(2, $vocabulary->divergences());
        $this->assertStringContainsString('labelled', $vocabulary->divergences()[0]);
        $this->assertStringContainsString('option lists differ', $vocabulary->divergences()[1]);
    }

    public function test_a_text_facet_gains_the_keyword_subfield_it_needs(): void
    {
        // A terms aggregation cannot run on analysed text — this is what stops
        // a declared facet silently returning nothing.
        $property = IndexVocabulary::propertyFor(
            $this->field('author', ['es_type' => 'text', 'is_facet' => true])
        );

        $this->assertSame('keyword', $property['fields']['keyword']['type']);
        $this->assertSame('text+keyword', IndexVocabulary::describe($property));
    }

    public function test_no_schemes_yields_an_empty_vocabulary(): void
    {
        $vocabulary = IndexVocabulary::of([]);

        $this->assertSame([], $vocabulary->properties());
        $this->assertFalse($vocabulary->hasConflicts());
    }
}
