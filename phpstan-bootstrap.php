<?php

/*
 * Makes LARAVEL_VERSION exist no matter how the process was started.
 *
 * Larastan's own bootstrap defines it, but only after locating the application
 * with `getcwd() . '/bootstrap/app.php'` and booting it. When that lookup fails
 * Larastan skips the define silently, and the constant is then read much later
 * by LarastanStubFilesExtension — so the run dies with a message that points
 * nowhere near the cause:
 *
 *     Undefined constant "Larastan\Larastan\LARAVEL_VERSION"
 *
 * It looks intermittent because `--debug` is single-process. It is not: the
 * trigger is a stale result cache putting the run on a path where bootstrap
 * files never execute at all, which no bootstrap file can defend against —
 * see scripts/phpstan.sh, which recovers from that. This file covers the other
 * half, where the bootstrap does run but Larastan's own lookup fails.
 *
 * The version is read straight out of Composer's installed metadata: a plain
 * array in a plain file. PHPStan runs from a PHAR with its own isolated
 * autoloader, so anything that depends on `vendor/` classes resolving — or on
 * the project autoloader having been registered first — is exactly the
 * assumption that keeps breaking. This has no such dependency.
 *
 * `chdir()` stays because Larastan's own lookup still wants it, and Larastan's
 * `if (! defined())` makes the define below safe in either bootstrap-file order.
 */

chdir(__DIR__);

if (! defined('LARAVEL_VERSION')) {
    $installed = __DIR__.'/vendor/composer/installed.php';

    if (is_file($installed)) {
        /** @var array{versions?: array<string, array{version?: string}>} $metadata */
        $metadata = require $installed;
        $version = $metadata['versions']['laravel/framework']['version'] ?? null;

        if (is_string($version)) {
            // "13.29.0.0" — Composer's four-part form. version_compare handles
            // it against the stub directories' "11", "12" without help.
            define('LARAVEL_VERSION', $version);
        }
    }
}
