#!/usr/bin/env php
<?php

declare(strict_types=1);

/*
 * Check that this storefront can do its job: the database is reachable, the
 * ERP answers and accepts the credentials, and the webhook secret is set.
 *
 *     php bin/doctor.php
 *
 * Exit code 1 when anything needed is wrong.
 */

require dirname(__DIR__).'/src/bootstrap.php';

use Storefront\App;
use Storefront\Erp\ErpException;
use Storefront\Support\Db;
use Storefront\Support\Env;

$failed = false;
$report = static function (bool $ok, string $label, string $detail = '') use (&$failed): void {
    $failed = $failed || ! $ok;
    echo ($ok ? '  ok   ' : '  FAIL ').$label.($detail !== '' ? ' — '.$detail : '')."\n";
};

echo "Storefront doctor\n\n";

$report(extension_loaded('pdo_mysql'), 'PHP pdo_mysql');
$report(extension_loaded('curl'), 'PHP curl');
$report(extension_loaded('mbstring'), 'PHP mbstring');
$report(PHP_VERSION_ID >= 80100, 'PHP 8.1+', PHP_VERSION);

try {
    Db::value('SELECT 1');
    $tables = (int) Db::value("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('carts','checkouts','orders','sessions')");
    $report(true, 'MySQL connection');
    $report($tables === 4, 'Tables migrated', $tables === 4 ? '' : 'run: php bin/migrate.php');
} catch (Throwable $throwable) {
    $report(false, 'MySQL connection', $throwable->getMessage());
}

$report(str_starts_with((string) Env::get('APP_URL', ''), 'https://') || Env::isLocal(), 'APP_URL is https', (string) Env::get('APP_URL'));
// Optional: without it the catalogue refreshes as its cache expires instead of
// the moment the ERP says something changed.
$webhook = (string) Env::get('WEBHOOK_SECRET', '');
$report($webhook === '' || strlen($webhook) >= 16, 'Webhook secret', $webhook === '' ? 'not set (optional — the cache refreshes the catalogue)' : 'set');

try {
    $erp = App::erp();
    $site = $erp->get('/site')['data'] ?? [];
    $report(true, 'ERP accepts the credentials');
    $report(($site['status'] ?? '') === 'open', 'Website is open', (string) ($site['status'] ?? 'unknown'));
    $report(($site['accepting_orders'] ?? false) === true, 'Website is accepting orders');
    $methods = (array) ($site['checkout']['payment_methods'] ?? []);
    $report($methods !== [], 'Payment methods available', implode(', ', $methods));

    $connection = $erp->get('/connection')['data'] ?? [];
    if ($connection !== []) {
        echo '       scopes: '.implode(', ', (array) ($connection['scopes'] ?? []))."\n";
    }
} catch (ErpException $exception) {
    $report(false, 'ERP', $exception->errorCode.' — '.$exception->getMessage());
} catch (Throwable $throwable) {
    $report(false, 'ERP', $throwable->getMessage());
}

echo "\n".($failed ? "Something needs attention.\n" : "All good.\n");
exit($failed ? 1 : 0);
