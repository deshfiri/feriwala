<?php

declare(strict_types=1);
use Storefront\Support\Env;

/*
 * Everything a request or a CLI script needs before it starts: the autoloader,
 * the environment and the error policy. There is no Composer on purpose — this
 * is meant to be uploaded and run on ordinary PHP + MySQL hosting.
 */

if (PHP_VERSION_ID < 80100) {
    http_response_code(500);
    exit('The storefront needs PHP 8.1 or newer.');
}

$root = dirname(__DIR__);

spl_autoload_register(static function (string $class) use ($root): void {
    if (! str_starts_with($class, 'Storefront\\')) {
        return;
    }

    $file = $root.'/src/'.str_replace('\\', '/', substr($class, strlen('Storefront\\'))).'.php';

    if (is_file($file)) {
        require $file;
    }
});

require_once $root.'/src/Support/helpers.php';

Env::load($root.'/.env');

date_default_timezone_set('UTC');
mb_internal_encoding('UTF-8');

$debug = Env::bool('APP_DEBUG', false);

ini_set('display_errors', $debug ? '1' : '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);
