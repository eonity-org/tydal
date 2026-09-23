<?php

namespace App\Rules;

use App\Enums\VaultAccessLevel;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A gated capability accepts either an access level (`denied` | `key` |
 * `inherit`) or the legacy boolean that predates levels — `true` meaning
 * inherit, `false` meaning denied (VAULT_SYSTEM.md §6.3).
 *
 * Both forms stay valid on the wire so policies written before levels existed
 * keep validating, and so a caller that only needs on/off never has to learn
 * the vocabulary.
 */
class AccessLevelRule implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_bool($value)) {
            return;
        }

        if (is_string($value) && VaultAccessLevel::tryFrom($value) !== null) {
            return;
        }

        $fail(sprintf(
            'The :attribute must be a boolean or one of: %s.',
            implode(', ', array_column(VaultAccessLevel::cases(), 'value')),
        ));
    }
}
