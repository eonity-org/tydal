<?php

namespace App\Enums;

/**
 * How open one capability is, independently of the vault's own `state`
 * (VAULT_SYSTEM.md §6.3).
 *
 * Before this existed a capability was a boolean and openness was vault-wide,
 * so "list resources publicly but keep the binaries behind a key" could not be
 * expressed on a single vault — the whole boundary was public or nothing was.
 *
 * The three levels are ordered: `denied` < `key` < `inherit`. Effective access
 * is the *stricter* of the vault's state and the capability's level, so a level
 * can only ever narrow what `state` already permits — never widen it.
 */
enum VaultAccessLevel: string
{
    /** The operation 403s regardless of credential. Legacy `false`. */
    case DENIED = 'denied';

    /**
     * Requires a real vault key even when the vault is `public`. A signed
     * grant does *not* satisfy this: a grant is the time-limited publish form,
     * so treating it as a key would let a share link reopen what this level
     * exists to close.
     */
    case KEY = 'key';

    /** Follows `vaults.state` — open when public, key when private. Legacy `true`. */
    case INHERIT = 'inherit';

    public function label(): string
    {
        return match ($this) {
            self::DENIED => 'Denied',
            self::KEY => 'Key required',
            self::INHERIT => 'Follow vault state',
        };
    }

    /**
     * Accept the legacy boolean form as well as a level name, so policies
     * written before levels existed keep resolving exactly as they did:
     * `true` → inherit, `false` → denied.
     */
    public static function coerce(mixed $value, self $default = self::INHERIT): self
    {
        return match (true) {
            $value === null => $default,
            $value === true => self::INHERIT,
            $value === false => self::DENIED,
            is_string($value) => self::tryFrom($value) ?? $default,
            default => $default,
        };
    }

    /**
     * Does this level answer for a request that authenticated with the given
     * credential? `denied` never answers; `key` needs a key; `inherit` answers
     * whenever the boundary let the request through at all — reachability was
     * already settled by the vault's state.
     */
    public function answersFor(VaultCredential $credential): bool
    {
        return match ($this) {
            self::DENIED => false,
            self::KEY => $credential === VaultCredential::KEY,
            self::INHERIT => true,
        };
    }
}
