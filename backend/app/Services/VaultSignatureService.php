<?php

namespace App\Services;

use App\Models\Vault;
use Carbon\CarbonInterface;

/**
 * Signed grants — the publish system's third access tier (Epic 5.4), beside
 * the vault's `public` state and vault keys (standing credential): a time-limited
 * URL that opens a private vault (or exactly one of its links) to whoever
 * holds it, with nothing to store server-side.
 *
 * The HMAC is keyed by the VAULT SALT and stamped with the vault's current
 * `grant_epoch`. Two independent revocation levers:
 *   - bump `grant_epoch` → every outstanding grant dies, links untouched
 *     (the light "un-share the grants" button);
 *   - rotate the salt → the full address-domain reset (also purges every link).
 * Scope is in the message: `*` = the whole vault surface, a link hash = that
 * address only (resource shares also cover the resource's file addresses —
 * widened at verification, not here).
 */
class VaultSignatureService
{
    // v2 folds grant_epoch into the message — v1 signatures no longer verify.
    private const VERSION = 'v2';

    /**
     * @return array{sig: string, exp: int}
     */
    public function sign(Vault $vault, ?string $linkHash, CarbonInterface $expiresAt): array
    {
        $exp = $expiresAt->getTimestamp();

        return ['sig' => $this->hmac($vault, $linkHash, $exp), 'exp' => $exp];
    }

    /** Verify a grant against ONE exact scope (null = vault-wide). */
    public function verify(Vault $vault, ?string $linkHash, string $sig, int $exp): bool
    {
        if ($exp < now()->getTimestamp()) {
            return false;
        }

        return hash_equals($this->hmac($vault, $linkHash, $exp), $sig);
    }

    private function hmac(Vault $vault, ?string $linkHash, int $exp): string
    {
        $message = implode(':', [
            'tydal-grant', self::VERSION, $vault->hash, $linkHash ?? '*', $exp, (int) $vault->grant_epoch,
        ]);

        return hash_hmac('sha256', $message, $vault->salt);
    }
}
