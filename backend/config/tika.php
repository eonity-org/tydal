<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Apache Tika Server
    |--------------------------------------------------------------------------
    |
    | TIKA_HOST: base URL of the running Tika server (docker service: tika)
    | TIKA_TIMEOUT: HTTP timeout in seconds for extraction requests
    |               Large PDFs can take 30–120 s; default 120 s is safe.
    |
    */

    'url' => env('TIKA_HOST', 'http://localhost:9998'),
    'timeout' => (int) env('TIKA_TIMEOUT', 120),
];
