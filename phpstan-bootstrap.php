<?php

/*
 * Runs before Larastan's own bootstrap.
 *
 * Larastan locates the application with `getcwd() . '/bootstrap/app.php'`. That
 * holds in the main PHPStan process but not always in its parallel worker
 * processes, and when the lookup fails Larastan silently skips defining
 * LARAVEL_VERSION — which then surfaces, much later and very confusingly, as:
 *
 *     Undefined constant "Larastan\Larastan\LARAVEL_VERSION"
 *
 * Pinning the working directory to the project root makes the lookup
 * deterministic regardless of how the process was started.
 */

chdir(__DIR__);
