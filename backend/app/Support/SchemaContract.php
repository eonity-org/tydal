<?php

namespace App\Support;

/**
 * The vocabulary of a collection scheme's `fields`.
 *
 * These lists were private to the `schema:validate` command, which meant the
 * write endpoint accepted field definitions the validator would later reject —
 * fine while schemes were hand-seeded, not fine now that they are edited
 * through a form. One source, checked at the boundary and by the command.
 *
 * A field definition drives five things at once: the dynamic form
 * (`display_in_form`, `order`, `type`), the Elasticsearch mapping (`es_type`,
 * `es_fields`), the facet list (`is_facet`), validation (`validators`,
 * `required`) and where the value is stored (`storage`). That is why changing
 * them under existing resources is refused — see CollectionSchemeController.
 */
final class SchemaContract
{
    /** Field types the dynamic form knows how to render. */
    public const TYPES = ['string', 'text', 'select', 'integer', 'boolean', 'array', 'email', 'url', 'date'];

    /** Elasticsearch mapping types the index builder can emit. */
    public const ES_TYPES = ['text', 'keyword', 'integer', 'long', 'float', 'double', 'boolean', 'date', 'object'];

    /** Where the value lives: a resource column, the metadata blob, or index-only. */
    public const STORAGES = ['metadata', 'column', 'index_only'];

    /**
     * The only field names `storage: column` may target — the resources
     * columns Resource::$fillable exposes and the wizard has a dedicated
     * input for. Anything else has no column to write to (mass assignment
     * silently drops it) and no form input to render it, so it must be
     * `metadata` instead.
     */
    public const COLUMN_FIELDS = ['name', 'description', 'type'];

    public const VALIDATOR_KEYS = ['min_length', 'max_length', 'min_value', 'max_value', 'in'];

    /** ES types that can be aggregated, and so used as a facet. */
    public const FACETABLE_ES_TYPES = ['keyword', 'integer', 'long', 'float', 'double', 'boolean', 'date'];

    /** type → the es_type values that make sense for it. */
    public const TYPE_ES_COMPAT = [
        'integer' => ['integer', 'long'],
        'boolean' => ['boolean'],
        'date' => ['date', 'keyword'],
        'array' => ['keyword', 'text'],
    ];

    /**
     * Validation rules for a `fields` array, for use in a FormRequest.
     *
     * @return array<string, mixed>
     */
    public static function fieldRules(string $prefix = 'fields'): array
    {
        return [
            // Field names reach raw SQL in the catalogue's DB-facet path, so the
            // charset is constrained at the boundary — never accept SQL
            // metacharacters here.
            "{$prefix}.*.name" => ['required', 'string', 'regex:/^[a-z][a-z0-9_]*$/'],
            "{$prefix}.*.type" => ['required', 'string', 'in:'.implode(',', self::TYPES)],
            "{$prefix}.*.es_type" => ['nullable', 'string', 'in:'.implode(',', self::ES_TYPES)],
            "{$prefix}.*.storage" => ['nullable', 'string', 'in:'.implode(',', self::STORAGES)],
            "{$prefix}.*.display_name" => ['nullable', 'string', 'max:255'],
            "{$prefix}.*.order" => ['nullable', 'integer', 'min:0'],
            "{$prefix}.*.required" => ['nullable', 'boolean'],
            "{$prefix}.*.is_facet" => ['nullable', 'boolean'],
            "{$prefix}.*.display_in_form" => ['nullable', 'boolean'],
            "{$prefix}.*.facet_label" => ['nullable', 'string', 'max:255'],
            "{$prefix}.*.facet_order" => ['nullable', 'integer', 'min:0'],
            "{$prefix}.*.validators" => ['nullable', 'array'],
            "{$prefix}.*.es_fields" => ['nullable', 'array'],
        ];
    }

    /**
     * Contract checks that a per-field rule set cannot express.
     *
     * @param  array<int, array<string, mixed>>  $fields
     * @return array<int, string> Human-readable problems, empty when valid.
     */
    public static function problems(array $fields): array
    {
        $problems = [];
        $seen = [];

        foreach ($fields as $field) {
            $name = $field['name'] ?? '(unnamed)';

            if (isset($seen[$name])) {
                $problems[] = "Duplicate field '{$name}'.";
            }
            $seen[$name] = true;

            $type = $field['type'] ?? null;
            $esType = $field['es_type'] ?? null;
            $storage = $field['storage'] ?? 'metadata';

            if ($storage === 'column' && ! in_array($name, self::COLUMN_FIELDS, true)) {
                $allowed = implode(', ', self::COLUMN_FIELDS);
                $problems[] = "'{$name}' can't use storage \"column\" — only {$allowed} map onto a real resources column. Use \"metadata\" instead.";
            }

            if ($type && $esType && isset(self::TYPE_ES_COMPAT[$type])
                && ! in_array($esType, self::TYPE_ES_COMPAT[$type], true)) {
                $allowed = implode(' or ', self::TYPE_ES_COMPAT[$type]);
                $problems[] = "'{$name}' is a {$type}, so its search type must be {$allowed} — not {$esType}.";
            }

            // A facet is an aggregation; `text` is analysed and cannot be one.
            if (! empty($field['is_facet']) && $esType && ! in_array($esType, self::FACETABLE_ES_TYPES, true)) {
                $problems[] = "'{$name}' cannot be a facet with search type {$esType}; use keyword.";
            }
        }

        return $problems;
    }
}
