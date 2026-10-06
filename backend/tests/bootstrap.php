<?php

/*
 * PHPUnit bootstrap: Composer's autoloader, plus a per-installation test
 * namespace for installations that share one MySQL / Elasticsearch (#23).
 *
 * phpunit.xml pins DB_DATABASE=tydal_test and ELASTICSEARCH_INDEX_PREFIX=test_.
 * Two installations on shared infrastructure would then share a test database
 * and test indices, so tools/deploy/test.sh passes this installation's own
 * names in TYDAL_TEST_DB_DATABASE / TYDAL_TEST_INDEX_PREFIX. Dedicated names
 * rather than DB_DATABASE / ELASTICSEARCH_INDEX_PREFIX themselves: the override
 * is then explicit and only ever comes from test.sh, instead of depending on
 * how PHPUnit's <env> (forced or not) interacts with whatever the shell or
 * container happens to export. PHPUnit applies its <php> section before
 * loading this file, so what is set here wins; with neither variable set
 * nothing changes.
 */

require __DIR__.'/../vendor/autoload.php';

foreach ([
    'TYDAL_TEST_DB_DATABASE' => 'DB_DATABASE',
    'TYDAL_TEST_INDEX_PREFIX' => 'ELASTICSEARCH_INDEX_PREFIX',
] as $override => $name) {
    $value = getenv($override);
    if ($value === false || $value === '') {
        continue;
    }
    putenv("{$name}={$value}");
    $_ENV[$name] = $value;
    $_SERVER[$name] = $value;
}
