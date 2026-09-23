<?php

namespace App\Support;

use App\Models\CollectionScheme;

/**
 * The field vocabulary of one Elasticsearch index.
 *
 * An ES index has exactly **one** mapping: a field path binds to one type, for
 * every document in it. Several collections — and so several collection
 * schemes — routinely share an index, which makes the index a *contract*: a
 * field name means one thing there, and every scheme on it either agrees or
 * does not belong.
 *
 * `ElasticsearchService::setupIndex` used to derive the mapping from the
 * **first** collection linked to the index and discard the rest. The other
 * schemes' fields were not lost — `metadata` is `dynamic: true`, so ES accepted
 * them and inferred types — but a field declared `keyword` came back as `text`
 * with a `.keyword` subfield, and the facet layer addresses the bare path
 * (`ElasticsearchService::vaultFacetFields`). The facet then aggregates on an
 * analysed text field and silently returns nothing. Which scheme "won" depended
 * on row order.
 *
 * So this class merges every scheme on an index into one vocabulary and
 * separates two kinds of disagreement:
 *
 * - **conflicts** — the same field with incompatible ES mappings. ES cannot
 *   represent both; there is no resolution to pick, so `search:setup-indices`
 *   refuses rather than choosing by row order. Fix by renaming one field, or by
 *   giving one scheme its own index (`restricted` visibility — see
 *   docs/SCHEMA_FIELDS.md).
 * - **divergences** — same field, same type, different *meaning*: facet or not,
 *   different label, different option list. ES is fine with these; humans are
 *   not, because the consumers that dedupe by field name resolve them by
 *   scheme order. Reported, not fatal (unless `--strict`).
 *
 * The vault index has its own builder (`buildVaultMappings`), which already
 * unions across schemes and types fields from the slot vocabulary rather than
 * from `es_type` — that side is out of scope here.
 */
final class IndexVocabulary
{
    /**
     * @param  array<string, array{es: array<string, mixed>, schemes: list<string>, is_facet: bool, label: string|null, options: list<mixed>|null}>  $terms
     * @param  list<string>  $conflicts
     * @param  list<string>  $divergences
     */
    private function __construct(
        private readonly array $terms,
        private readonly array $conflicts,
        private readonly array $divergences,
    ) {}

    /**
     * Merge a set of schemes into one vocabulary. Order only decides which
     * declaration is *reported first* in a conflict — never which one wins,
     * because a conflict is refused rather than resolved.
     *
     * @param  iterable<CollectionScheme>  $schemes
     */
    public static function of(iterable $schemes): self
    {
        $terms = [];
        $conflicts = [];
        $divergences = [];

        foreach ($schemes as $scheme) {
            foreach ($scheme->fields ?? [] as $field) {
                // Only `metadata` storage reaches metadata.<name>; `column`
                // fields are already in the base mapping and `index_only`
                // fields carry no scheme-declared type here.
                if (($field['storage'] ?? 'metadata') !== 'metadata') {
                    continue;
                }

                $name = $field['name'] ?? null;

                if (! is_string($name) || $name === '') {
                    continue;
                }

                $property = self::propertyFor($field);
                $isFacet = ($field['is_facet'] ?? false) === true;
                $label = $field['facet_label'] ?? $field['display_name'] ?? null;
                $options = $field['validators']['in'] ?? null;

                if (! isset($terms[$name])) {
                    $terms[$name] = [
                        'es' => $property,
                        'schemes' => [$scheme->name],
                        'is_facet' => $isFacet,
                        'label' => $label,
                        'options' => $options,
                    ];

                    continue;
                }

                $term = $terms[$name];
                $first = $term['schemes'][0];

                if (self::normalize($property) !== self::normalize($term['es'])) {
                    $conflicts[] = sprintf(
                        '%s: %s declares %s, %s declares %s — one index holds one type per field. '
                        .'Rename one field, or give one scheme its own index.',
                        $name,
                        $first,
                        self::describe($term['es']),
                        $scheme->name,
                        self::describe($property),
                    );

                    continue;
                }

                $terms[$name]['schemes'][] = $scheme->name;

                if ($term['is_facet'] !== $isFacet) {
                    [$yes, $no] = $isFacet ? [$scheme->name, $first] : [$first, $scheme->name];
                    $divergences[] = "{$name}: a facet in {$yes} but not in {$no} — consumers that dedupe "
                        .'by field name pick whichever scheme they see first';
                }

                if ($term['label'] !== null && $label !== null && $term['label'] !== $label) {
                    $divergences[] = "{$name}: labelled \"{$term['label']}\" in {$first} and \"{$label}\" "
                        ."in {$scheme->name} — one label wins, arbitrarily";
                }

                if (is_array($term['options']) && is_array($options) && $term['options'] !== $options) {
                    $divergences[] = sprintf(
                        '%s: option lists differ (%s offers %d, %s offers %d) — the forms disagree '
                        .'about what may land in one shared facet',
                        $name, $first, count($term['options']), $scheme->name, count($options)
                    );
                }
            }
        }

        return new self($terms, $conflicts, $divergences);
    }

    /**
     * The ES mapping one field declaration produces. Extracted so the mapping
     * builder and the vocabulary check cannot drift apart about what a field
     * means.
     *
     * @param  array<string, mixed>  $field
     * @return array<string, mixed>
     */
    public static function propertyFor(array $field): array
    {
        $esType = $field['es_type'] ?? 'text';
        $property = ['type' => $esType];

        if (isset($field['es_fields'])) {
            $property['fields'] = $field['es_fields'];
        }

        // A facet is an aggregation and analysed `text` cannot be one, so a
        // text facet gets the keyword subfield it needs to work at all.
        if ($esType === 'text' && ($field['is_facet'] ?? false)) {
            $property['fields'] = array_merge($property['fields'] ?? [], [
                'keyword' => ['type' => 'keyword', 'ignore_above' => 256],
            ]);
        }

        return $property;
    }

    /**
     * The merged `metadata.properties` block — the union, not the first
     * scheme's.
     *
     * @return array<string, array<string, mixed>>
     */
    public function properties(): array
    {
        return array_map(fn (array $term): array => $term['es'], $this->terms);
    }

    /**
     * @return array<string, array{es: array<string, mixed>, schemes: list<string>, is_facet: bool, label: string|null, options: list<mixed>|null}>
     */
    public function terms(): array
    {
        return $this->terms;
    }

    /** @return list<string> */
    public function conflicts(): array
    {
        return $this->conflicts;
    }

    /** @return list<string> */
    public function divergences(): array
    {
        return $this->divergences;
    }

    public function hasConflicts(): bool
    {
        return $this->conflicts !== [];
    }

    /** A field's mapping in one readable token: `keyword`, `text+keyword`. */
    public static function describe(array $property): string
    {
        $type = $property['type'] ?? 'text';
        $subfields = array_keys($property['fields'] ?? []);

        return $subfields === [] ? $type : $type.'+'.implode('+', $subfields);
    }

    /**
     * Compare mappings by value, not by key order — two schemes writing the
     * same subfields in a different order are not in conflict.
     *
     * @param  array<string, mixed>  $property
     * @return array<string, mixed>
     */
    private static function normalize(array $property): array
    {
        ksort($property);

        foreach ($property as $key => $value) {
            if (is_array($value)) {
                $property[$key] = self::normalize($value);
            }
        }

        return $property;
    }
}
