<?php

/*
|--------------------------------------------------------------------------
| Trusted proxies
|--------------------------------------------------------------------------
|
| Read by Laravel's TrustProxies middleware. Behind a reverse proxy that
| terminates TLS (a host nginx in front of the app container), the request
| reaches PHP as plain http — without trusting the proxy's X-Forwarded-*
| headers, route() and url() build http:// links (vault serve links, file
| URLs). Empty (the default) trusts nothing: correct for `artisan serve`
| and the dev docker tier, where nothing sits in front.
|
| TRUSTED_PROXIES: a comma-separated list of proxy IPs/CIDRs, or `*` to
| trust the direct peer — safe only when the app port is reachable solely
| through the proxy (e.g. published on 127.0.0.1).
|
*/

return [
    // TrustProxies splits the list itself and treats `*` as "the direct peer".
    'proxies' => env('TRUSTED_PROXIES') ?: null,
];
