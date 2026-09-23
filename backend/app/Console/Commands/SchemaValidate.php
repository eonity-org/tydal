<?php

namespace App\Console\Commands;

use App\Models\CollectionScheme;
use App\Services\VaultSchemaResolver;
use App\Support\SchemaContract;
use Illuminate\Console\Command;

/**
 * Validate every collection scheme's `fields` definition — Epic 1.2.
 *
 * `collection_schemes.fields` is the single source of truth for dynamic
 * forms, ES mappings, facets, validation, and MIME gating; an inconsistent
 * field definition breaks consumers silently. This command fails (exit 1)
 * on any inconsistency so CI can hold the line. See docs/SCHEMA_FIELDS.md
 * for the field contract.
 */
class SchemaValidate extends Command
{
    protected $signature = 'schema:validate {--scheme= : Only validate one scheme (id or name)}';

    protected $description = 'Validate collection scheme field definitions against the field contract';

    // The vocabulary lives in App\Support\SchemaContract so this command
    // and the write endpoint cannot disagree about what a valid field is.
    private const TYPES = SchemaContract::TYPES;

    private const ES_TYPES = SchemaContract::ES_TYPES;

    private const STORAGES = SchemaContract::STORAGES;

    private const COLUMN_FIELDS = SchemaContract::COLUMN_FIELDS;

    private const VALIDATOR_KEYS = SchemaContract::VALIDATOR_KEYS;

    private const FACETABLE_ES_TYPES = SchemaContract::FACETABLE_ES_TYPES;

    private const TYPE_ES_COMPAT = SchemaContract::TYPE_ES_COMPAT;

    public function handle(): int
    {
        $query = CollectionScheme::query();

        if ($this->option('scheme') !== null) {
            $needle = $this->option('scheme');
            $query->where(fn ($q) => $q->where('id', $needle)->orWhere('name', $needle));
        }

        $errorTotal = 0;

        foreach ($query->get() as $scheme) {
            $errors = $this->validateScheme($scheme);

            if ($errors === []) {
                $this->info("✓ {$scheme->name}");

                continue;
            }

            $this->error("✗ {$scheme->name}");
            foreach ($errors as $error) {
                $this->line("    - {$error}");
            }
            $errorTotal += count($errors);
        }

        if ($errorTotal > 0) {
            $this->newLine();
            $this->error("{$errorTotal} schema violation(s) found.");

            return self::FAILURE;
        }

        $this->info('All schemes valid.');

        return self::SUCCESS;
    }

    /** @return list<string> */
    private function validateScheme(CollectionScheme $scheme): array
    {
        $errors = [];

        foreach ($scheme->accepted_mimetypes ?? [] as $mime) {
            if (! is_string($mime) || ! preg_match('#^[\w.+-]+/(\*|[\w.+-]+)$#', $mime)) {
                $errors[] = 'accepted_mimetypes: "'.json_encode($mime).'" is not a valid type/subtype pattern';
            }
        }

        $fields = $scheme->fields ?? [];
        $seen = [];

        foreach ($fields as $i => $field) {
            $label = $field['name'] ?? "fields[{$i}]";

            // name — required, machine-safe, unique within the scheme
            $name = $field['name'] ?? null;
            if (! is_string($name) || ! preg_match('/^[a-z][a-z0-9_]*$/', $name)) {
                $errors[] = "{$label}: name must be snake_case starting with a letter";
            } elseif (isset($seen[$name])) {
                $errors[] = "{$label}: duplicate field name";
            } else {
                $seen[$name] = true;
            }

            // type
            $type = $field['type'] ?? 'string';
            if (! in_array($type, self::TYPES, true)) {
                $errors[] = "{$label}: unknown type \"{$type}\" (expected one of: ".implode(', ', self::TYPES).')';
            }

            // storage
            $storage = $field['storage'] ?? 'metadata';
            if (! in_array($storage, self::STORAGES, true)) {
                $errors[] = "{$label}: unknown storage \"{$storage}\"";
            } elseif ($storage === 'column' && is_string($name) && ! in_array($name, self::COLUMN_FIELDS, true)) {
                $errors[] = "{$label}: storage \"column\" only fits ".implode(', ', self::COLUMN_FIELDS).' — use "metadata" instead';
            }

            // es_type + type compatibility (only metadata-storage fields are mapped)
            $esType = $field['es_type'] ?? 'text';
            if ($storage === 'metadata') {
                if (! in_array($esType, self::ES_TYPES, true)) {
                    $errors[] = "{$label}: unknown es_type \"{$esType}\"";
                } elseif (isset(self::TYPE_ES_COMPAT[$type]) && ! in_array($esType, self::TYPE_ES_COMPAT[$type], true)) {
                    $errors[] = "{$label}: es_type \"{$esType}\" is incompatible with type \"{$type}\" (allowed: ".implode(', ', self::TYPE_ES_COMPAT[$type]).')';
                }
            }

            // facets need an aggregatable mapping
            if (($field['is_facet'] ?? false) === true) {
                $hasKeywordSubfield = isset($field['es_fields']['keyword']);
                if (! in_array($esType, self::FACETABLE_ES_TYPES, true) && ! $hasKeywordSubfield) {
                    $errors[] = "{$label}: is_facet requires an aggregatable es_type (".implode(', ', self::FACETABLE_ES_TYPES).') or an es_fields.keyword subfield';
                }
            }

            // validators — known keys, coherent values
            $validators = $field['validators'] ?? [];
            if (! is_array($validators)) {
                $errors[] = "{$label}: validators must be an object";
                $validators = [];
            }
            foreach (array_keys($validators) as $key) {
                if (! in_array($key, self::VALIDATOR_KEYS, true)) {
                    $errors[] = "{$label}: unknown validator \"{$key}\"";
                }
            }
            if (isset($validators['in']) && ! is_array($validators['in'])) {
                $errors[] = "{$label}: validators.in must be an array of allowed values";
            }
            if ($type === 'select' && empty($validators['in'])) {
                $errors[] = "{$label}: type \"select\" requires validators.in (the option list)";
            }
            foreach ([['min_length', 'max_length'], ['min_value', 'max_value']] as [$minKey, $maxKey]) {
                if (isset($validators[$minKey], $validators[$maxKey]) && $validators[$minKey] > $validators[$maxKey]) {
                    $errors[] = "{$label}: {$minKey} exceeds {$maxKey}";
                }
            }

            // vault_roles — semantic mapping onto the fixed role vocabulary
            $vaultRoles = $field['vault_roles'] ?? [];
            if (! is_array($vaultRoles)) {
                $errors[] = "{$label}: vault_roles must be an object (purpose → slot)";
                $vaultRoles = [];
            }
            foreach ($vaultRoles as $purpose => $slot) {
                if (! in_array($purpose, VaultSchemaResolver::mappablePurposes(), true)) {
                    $errors[] = "{$label}: vault_roles has unknown purpose \"{$purpose}\" (expected: ".implode(', ', VaultSchemaResolver::mappablePurposes()).')';
                } elseif (! in_array($slot, VaultSchemaResolver::slotsFor($purpose), true)) {
                    $errors[] = "{$label}: vault_roles.{$purpose} has unknown slot \"{$slot}\" (expected: ".implode(', ', VaultSchemaResolver::slotsFor($purpose)).')';
                }
            }

            // ai_fill — AI extraction contract for this field
            $aiFill = $field['ai_fill'] ?? null;
            if ($aiFill !== null) {
                if (! is_array($aiFill)) {
                    $errors[] = "{$label}: ai_fill must be an object";
                } else {
                    foreach (array_keys($aiFill) as $key) {
                        if (! in_array($key, ['enabled', 'hint'], true)) {
                            $errors[] = "{$label}: unknown ai_fill key \"{$key}\"";
                        }
                    }
                    if (isset($aiFill['enabled']) && ! is_bool($aiFill['enabled'])) {
                        $errors[] = "{$label}: ai_fill.enabled must be boolean";
                    }
                    if (isset($aiFill['hint']) && ! is_string($aiFill['hint'])) {
                        $errors[] = "{$label}: ai_fill.hint must be a string";
                    }
                }
            }

            // flag types
            foreach (['required', 'is_facet', 'display_in_form'] as $flag) {
                if (isset($field[$flag]) && ! is_bool($field[$flag])) {
                    $errors[] = "{$label}: {$flag} must be boolean";
                }
            }
            if (isset($field['order']) && ! is_int($field['order'])) {
                $errors[] = "{$label}: order must be an integer";
            }
        }

        return $errors;
    }
}
