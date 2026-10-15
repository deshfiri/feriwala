<?php

declare(strict_types=1);

/*
 * php tests/run.php [filter]
 *
 * Database tests run against the database named by TEST_DB_* (never DB_*, so a
 * production .env cannot be wiped by accident):
 *
 *     TEST_DB_SOCKET=/path/mysql.sock TEST_DB_NAME=storefront_test TEST_DB_USER=root php tests/run.php
 *     TEST_DB_HOST=127.0.0.1 TEST_DB_PORT=3306 TEST_DB_NAME=... TEST_DB_USER=... TEST_DB_PASS=... php tests/run.php
 */

require dirname(__DIR__).'/src/bootstrap.php';
require __DIR__.'/harness.php';
require __DIR__.'/support.php';

use Storefront\Support\Env;
use Storefront\Support\Migrator;

$database = getenv('TEST_DB_NAME') ?: '';

if ($database === '' || ! str_contains($database, 'test')) {
    fwrite(STDERR, "Set TEST_DB_NAME to a database whose name contains \"test\" — these tests empty its tables.\n");
    exit(2);
}

// The tests speak to a fake ERP and a throwaway database, whatever .env says.
Env::set('APP_ENV', 'local');
Env::set('DB_NAME', $database);
Env::set('DB_USER', getenv('TEST_DB_USER') ?: 'root');
Env::set('DB_PASS', getenv('TEST_DB_PASS') ?: '');
Env::set('DB_HOST', getenv('TEST_DB_HOST') ?: '127.0.0.1');
Env::set('DB_PORT', getenv('TEST_DB_PORT') ?: '3306');
Env::set('DB_SOCKET', getenv('TEST_DB_SOCKET') ?: '');
Env::set('WEBHOOK_SECRET', 'whsec_current_secret_value');
Env::set('WEBHOOK_SECRET_PREVIOUS', '');
Env::set('APP_URL', 'https://shop.test');
Env::set('APP_NAME', 'Test Shop');

Migrator::run(dirname(__DIR__).'/database/migrations');

// Output is held until the end so a session can be started after the dots.
ob_start();

$filter = $argv[1] ?? null;

foreach (glob(__DIR__.'/*Test.php') ?: [] as $file) {
    if ($filter !== null && ! str_contains(basename($file), $filter)) {
        continue;
    }

    require $file;
}

$status = run($filter);

while (ob_get_level() > 0) {
    ob_end_flush();
}

exit($status);
