<?php

namespace App\Services;

use App\Models\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Collection Schema Service
 *
 * Validates resource metadata against the collection's scheme fields array.
 * The scheme's `fields` column is the single source of truth for validation rules.
 */
class CollectionSchemaService
{
    /**
     * Validate resource metadata against the collection's scheme.
     *
     * @param  array  $data  The resource metadata payload
     * @return array Validated and sanitized data
     *
     * @throws ValidationException
     */
    public function validateResourceData(Collection $collection, array $data): array
    {
        $fields = $collection->getEffectiveSchema();

        if (! $fields) {
            return $data;
        }

        $rules = $this->buildValidationRules($fields);
        $messages = $this->buildValidationMessages($fields);

        $validator = Validator::make($data, $rules, $messages);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        return $validator->validated();
    }

    /**
     * Validate a PARTIAL metadata payload — only the keys present in $data
     * are checked, and `required` degrades to `nullable` (a partial fill is
     * not obliged to complete the whole scheme). Used by AITY when applying
     * field suggestions: an AI value can never enter metadata unless the
     * scheme itself would accept it from a human.
     *
     * @return array Validated subset
     *
     * @throws ValidationException
     */
    public function validatePartialResourceData(Collection $collection, array $data): array
    {
        $fields = $collection->getEffectiveSchema();

        if (! $fields) {
            return $data;
        }

        $rules = $this->buildValidationRules($fields);

        $partialRules = [];
        foreach ($rules as $field => $rule) {
            if (array_key_exists($field, $data)) {
                $partialRules[$field] = str_replace('required', 'nullable', $rule);
            }
        }

        $validator = Validator::make($data, $partialRules, $this->buildValidationMessages($fields));

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        return $validator->validated();
    }

    /**
     * Build Laravel validation rules from the scheme fields array.
     *
     * Only validates `metadata`-storage fields; `column` and `index_only` fields
     * are validated elsewhere (StoreResourceRequest) or not stored in MySQL.
     *
     * @param  array<int, array>  $fields
     * @return array<string, string>
     */
    protected function buildValidationRules(array $fields): array
    {
        $rules = [];

        foreach ($fields as $field) {
            $storage = $field['storage'] ?? 'metadata';

            // column-storage → validated by StoreResourceRequest
            // index_only     → not in the request payload at all
            if ($storage !== 'metadata') {
                continue;
            }

            $fieldName = $field['name'];
            $type = $field['type'] ?? 'string';
            $validators = $field['validators'] ?? [];
            $rule = [];

            $rule[] = ($field['required'] ?? false) ? 'required' : 'nullable';

            match ($type) {
                'string', 'select', 'text' => $rule[] = 'string',
                'integer' => $rule[] = 'integer',
                'boolean' => $rule[] = 'boolean',
                'array' => $rule[] = 'array',
                'email' => $rule[] = 'email',
                'url' => $rule[] = 'url',
                'date' => $rule[] = 'date',
                default => null,
            };

            if (isset($validators['min_length'])) {
                $rule[] = 'min:'.$validators['min_length'];
            }
            if (isset($validators['max_length'])) {
                $rule[] = 'max:'.$validators['max_length'];
            }
            if (isset($validators['min_value'])) {
                $rule[] = 'min:'.$validators['min_value'];
            }
            if (isset($validators['max_value'])) {
                $rule[] = 'max:'.$validators['max_value'];
            }
            if (isset($validators['in']) && is_array($validators['in'])) {
                $rule[] = 'in:'.implode(',', $validators['in']);
            }

            if (! empty($rule)) {
                $rules[$fieldName] = implode('|', array_filter($rule));
            }
        }

        return $rules;
    }

    /**
     * Build custom validation messages from scheme fields.
     *
     * @param  array<int, array>  $fields
     * @return array<string, string>
     */
    protected function buildValidationMessages(array $fields): array
    {
        $messages = [];

        foreach ($fields as $field) {
            $fieldName = $field['name'];
            $displayName = $field['display_name'] ?? ucfirst(str_replace('_', ' ', $fieldName));
            $validators = $field['validators'] ?? [];

            if (isset($validators['min_length'])) {
                $messages["{$fieldName}.min"] = "{$displayName} must be at least {$validators['min_length']} characters.";
            }
            if (isset($validators['max_length'])) {
                $messages["{$fieldName}.max"] = "{$displayName} must not exceed {$validators['max_length']} characters.";
            }
            if (isset($validators['min_value'])) {
                $messages["{$fieldName}.min"] = "{$displayName} must be at least {$validators['min_value']}.";
            }
            if (isset($validators['max_value'])) {
                $messages["{$fieldName}.max"] = "{$displayName} must not exceed {$validators['max_value']}.";
            }
        }

        return $messages;
    }

    /**
     * Format ValidationException errors as a flat field → message map.
     *
     * @return array<string, string>
     */
    public function formatValidationErrors(ValidationException $exception): array
    {
        $errors = [];
        foreach ($exception->errors() as $field => $msgs) {
            $errors[$field] = implode(', ', $msgs);
        }

        return $errors;
    }
}
