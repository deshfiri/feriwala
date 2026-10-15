<?php

declare(strict_types=1);
use Storefront\Erp\ErpException;

/*
 * A tiny test runner, because the storefront has no Composer and needs none.
 *
 *     php tests/run.php            # everything
 *     php tests/run.php Checkout   # files whose name contains "Checkout"
 *
 * Database tests need a MySQL database reachable through DB_* (see tests/run.php).
 */

$GLOBALS['__tests'] = [];
$GLOBALS['__current'] = '';

function test(string $name, callable $body): void
{
    $GLOBALS['__tests'][] = [$GLOBALS['__current'].' › '.$name, $body];
}

function suite(string $name): void
{
    $GLOBALS['__current'] = $name;
}

final class AssertionFailed extends RuntimeException {}

function fail(string $message): never
{
    throw new AssertionFailed($message);
}

function ok(bool $condition, string $message = 'Expected true'): void
{
    if (! $condition) {
        fail($message);
    }
}

function same(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        fail(($message !== '' ? $message.': ' : '').'expected '.var_export($expected, true).', got '.var_export($actual, true));
    }
}

function contains(string $needle, string $haystack, string $message = ''): void
{
    if (! str_contains($haystack, $needle)) {
        fail(($message !== '' ? $message.': ' : '').'expected to find '.var_export($needle, true).' in '.substr($haystack, 0, 300));
    }
}

function lacks(string $needle, string $haystack, string $message = ''): void
{
    if (str_contains($haystack, $needle)) {
        fail(($message !== '' ? $message.': ' : '').'did not expect '.var_export($needle, true));
    }
}

/**
 * @param  class-string<Throwable>  $class
 */
function throws(string $class, callable $body, ?string $code = null): Throwable
{
    try {
        $body();
    } catch (Throwable $throwable) {
        if (! $throwable instanceof $class) {
            fail('expected '.$class.', got '.get_class($throwable).': '.$throwable->getMessage());
        }

        if ($code !== null && $throwable instanceof ErpException && $throwable->errorCode !== $code) {
            fail('expected error code '.$code.', got '.$throwable->errorCode);
        }

        return $throwable;
    }

    fail('expected '.$class.' to be thrown');
}

function run(?string $filter): int
{
    $passed = 0;
    $failed = [];

    foreach ($GLOBALS['__tests'] as [$name, $body]) {
        try {
            $body();
            $passed++;
            echo '.';
        } catch (Throwable $throwable) {
            $failed[] = [$name, $throwable];
            echo 'F';
        }
    }

    echo "\n\n";

    foreach ($failed as [$name, $throwable]) {
        echo "FAILED  {$name}\n        ".get_class($throwable).': '.$throwable->getMessage()."\n";

        if (! $throwable instanceof AssertionFailed) {
            echo '        '.$throwable->getFile().':'.$throwable->getLine()."\n";
        }
    }

    echo sprintf("\n%d passed, %d failed%s\n", $passed, count($failed), $filter !== null ? " (filter: {$filter})" : '');

    return $failed === [] ? 0 : 1;
}
