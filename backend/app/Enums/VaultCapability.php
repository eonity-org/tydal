<?php

namespace App\Enums;

use App\Rules\AccessLevelRule;
use Illuminate\Validation\Rule;

/**
 * The closed set of `vaults.exposure_policy` keys (VAULT_SYSTEM.md §6.3).
 *
 * Purpose is a *preset* over these knobs — "Admin can override any of them" —
 * but until now the override document was an untyped JSON bag: only
 * `rag_min_score` was validated, so a misspelt `allow_binaries` was accepted
 * and then silently ignored, leaving the vault on its preset with no signal.
 * This enum makes the vocabulary explicit so the form requests can reject
 * unknown keys and the admin UI can render the matrix from one source.
 *
 * Each case owns its own validation rules; nothing re-declares the shape.
 */
enum VaultCapability: string
{
    // Read tiers (VAULT_SYSTEM.md §6.2) — booleans today.
    case ALLOW_CHUNKS = 'allow_chunks';
    case ALLOW_BINARY = 'allow_binary';
    case ALLOW_ASK = 'allow_ask';

    // Projection role filters (§6.1) — which file roles reach a tier.
    case ADDRESS_ROLES = 'address_roles';
    case CHUNK_ROLES = 'chunk_roles';

    // Write boundary (VAULT_WRITE_METHODS.md §3).
    case WRITE_METHODS = 'write_methods';
    case INGEST = 'ingest';

    // Retrieval tuning.
    case RAG_MIN_SCORE = 'rag_min_score';

    public function label(): string
    {
        return match ($this) {
            self::ALLOW_CHUNKS => 'Expose chunk text (Tier 1)',
            self::ALLOW_BINARY => 'Expose binaries (Tier 2)',
            self::ALLOW_ASK => 'Answer at the boundary (/ask)',
            self::ADDRESS_ROLES => 'File roles that get addresses',
            self::CHUNK_ROLES => 'File roles that feed chunks',
            self::WRITE_METHODS => 'Write methods accepted',
            self::INGEST => 'Ingest landing target',
            self::RAG_MIN_SCORE => 'Ask relevance floor',
        };
    }

    /**
     * UI grouping for the capability matrix — the axes an operator reasons
     * about, which are not the same as the tier numbering.
     */
    public function group(): string
    {
        return match ($this) {
            self::ALLOW_CHUNKS, self::ALLOW_BINARY, self::ALLOW_ASK => 'read_tiers',
            self::ADDRESS_ROLES, self::CHUNK_ROLES => 'projection',
            self::WRITE_METHODS, self::INGEST => 'write',
            self::RAG_MIN_SCORE => 'retrieval',
        };
    }

    /**
     * Validation rules for this key, keyed by the dotted request path. Form
     * requests merge every case's rules, so adding a capability here is the
     * only edit needed to make it settable over the API.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $path = 'exposure_policy.'.$this->value;

        return match ($this) {
            // Boolean or access level: `true`/`false` are the legacy forms of
            // `inherit`/`denied`, and `key` is the level that has no boolean
            // equivalent (require a key even on a public vault).
            self::ALLOW_CHUNKS, self::ALLOW_BINARY, self::ALLOW_ASK => [
                $path => ['sometimes', 'nullable', new AccessLevelRule],
            ],
            self::ADDRESS_ROLES, self::CHUNK_ROLES => [
                $path => 'sometimes|nullable|array',
                $path.'.*' => ['string', Rule::enum(FileRole::class)],
            ],
            self::WRITE_METHODS => [
                $path => 'sometimes|nullable|array',
                $path.'.*' => 'string',
            ],
            // The landing spot for the `ai` purpose's ingest op — the consumer
            // declares what, the vault decides where, so both ids are required
            // together when the key is present at all.
            self::INGEST => [
                $path => 'sometimes|nullable|array',
                $path.'.workspace_id' => 'required_with:'.$path.'|integer|exists:workspaces,id',
                $path.'.collection_id' => 'required_with:'.$path.'|integer|exists:collections,id',
            ],
            self::RAG_MIN_SCORE => [
                $path => 'sometimes|nullable|numeric|min:0|max:1',
            ],
        };
    }

    /**
     * Every capability's rules merged — what a form request spreads into its
     * own rule set.
     *
     * @return array<string, mixed>
     */
    public static function rulesForAll(): array
    {
        $rules = [];

        foreach (self::cases() as $capability) {
            $rules = array_merge($rules, $capability->rules());
        }

        return $rules;
    }

    /**
     * Rules for the `exposure_policy` document itself — an array whose keys
     * must all be known capabilities. Without this an unrecognised key is
     * accepted and then ignored at read time, which is how a vault ends up
     * silently running on its preset despite an operator having "set" a knob.
     *
     * @return list<mixed>
     */
    public static function documentRule(): array
    {
        return [
            'nullable',
            'array',
            function (string $attribute, mixed $value, \Closure $fail): void {
                $unknown = self::unknownKeys(is_array($value) ? $value : null);

                if ($unknown !== []) {
                    $fail(sprintf(
                        'Unknown exposure policy %s: %s. Known keys: %s.',
                        count($unknown) === 1 ? 'key' : 'keys',
                        implode(', ', $unknown),
                        implode(', ', self::values()),
                    ));
                }
            },
        ];
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }

    /**
     * Keys present in a policy document that this vocabulary does not define.
     * Used both by the form requests (reject on write) and by
     * `vault:validate-policy` (report on rows written before validation existed).
     *
     * @param  array<array-key, mixed>|null  $policy
     * @return list<string>
     */
    public static function unknownKeys(?array $policy): array
    {
        if (empty($policy)) {
            return [];
        }

        return array_values(array_diff(array_keys($policy), self::values()));
    }
}
