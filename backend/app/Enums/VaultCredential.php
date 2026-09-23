<?php

namespace App\Enums;

/**
 * How a request got through the vault boundary — recorded on the resolved Vault
 * instance so the tier gates can ask "may *this* caller?" rather than only
 * "does this vault expose it?".
 *
 * Set by VaultLinkService when a vault resolves; never persisted. A Vault
 * loaded outside the boundary (admin API, jobs, tests) carries no credential
 * and is treated as OPEN — those paths are already authorized by org
 * membership, and the capability levels exist to gate the *public* boundary.
 */
enum VaultCredential: string
{
    /** No credential presented — the vault's own `state` let the request in. */
    case OPEN = 'open';

    /** A valid `VaultKey` was presented (`X-Vault-Key`). */
    case KEY = 'key';

    /** A signed grant (`?sig=&exp=`) — the time-limited publish form. */
    case GRANT = 'grant';
}
