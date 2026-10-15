<?php

declare(strict_types=1);

use Storefront\Domain\Catalog;
use Storefront\Http\Router;
use Storefront\Support\I18n;
use Storefront\Support\Migrator;
use Storefront\Support\Money;

suite('Support');

test('groups a decimal string without a float', function () {
    same('1,234.50', Money::group('1234.5'));
    same('0.00', Money::group('0'));
    same('1,000,000.00', Money::group('1000000'));
    same('-1,234.00', Money::group('-1234'));
    same('12abc', Money::group('12abc'), 'a non-number is left alone');
});

test('shows the ERP’s own formatted figure, or builds one', function () {
    same('৳2,490.00', Money::display(['amount' => '2490.00', 'currency' => 'BDT', 'formatted' => '৳2,490.00']));
    same('৳2,490.00', Money::display(['amount' => '2490', 'currency' => 'BDT']));
    same('', Money::display(null));
});

test('knows a zero amount exactly', function () {
    ok(Money::isZero(['amount' => '0.00']));
    ok(Money::isZero(['amount' => '0']));
    ok(! Money::isZero(['amount' => '0.01']));
    ok(Money::isZero(null));
});

test('compares decimal strings exactly, past what a float can hold', function () {
    ok(Catalog::decimalLess('99.99', '100.00'));
    ok(! Catalog::decimalLess('100.00', '99.99'));
    ok(Catalog::decimalLess('0.1', '0.10001'));
    ok(! Catalog::decimalLess('2490.00', '2490'));
    ok(Catalog::decimalLess('9007199254740992.01', '9007199254740992.02'));
});

test('routes by method and path, and tells 404 from 405', function () {
    $router = new Router;
    $router->get('/product/{slug}', fn () => 'show');
    $router->post('/cart/add', fn () => 'add');

    $hit = $router->match('GET', '/product/blue-shirt');
    same('blue-shirt', $hit['params']['slug']);
    same(404, $router->match('GET', '/nope'));
    same(405, $router->match('GET', '/cart/add'));
    ok(is_array($router->match('HEAD', '/product/x')), 'HEAD follows GET');
    same('a b', $router->match('GET', '/product/a%20b')['params']['slug']);
});

test('splits a migration into statements and ignores comments', function () {
    $statements = Migrator::statements("-- a comment\nCREATE TABLE a (id INT);\n\n-- another\nCREATE TABLE b (\n  id INT\n);\n");

    same(2, count($statements));
    same('CREATE TABLE a (id INT)', $statements[0]);
});

test('a whole number in a Bangla sentence takes Bangla digits, and a name is left alone', function () {
    I18n::boot('bn');

    try {
        contains('৫', I18n::t('cart.max_limited', ['max' => '5']));
        contains('Cotton 100', I18n::t('cart.added', ['name' => 'Cotton 100']));
    } finally {
        I18n::boot('en');
    }

    contains('5', I18n::t('cart.max_limited', ['max' => '5']));
});

test('every language has every key the English one has', function () {
    $en = require dirname(__DIR__).'/lang/en.php';
    $bn = require dirname(__DIR__).'/lang/bn.php';

    $missing = array_diff(array_keys($en), array_keys($bn));
    $extra = array_diff(array_keys($bn), array_keys($en));

    same([], array_values($missing), 'keys missing from Bangla');
    same([], array_values($extra), 'Bangla keys English lacks');
});

test('placeholders match between English and Bangla', function () {
    $en = require dirname(__DIR__).'/lang/en.php';
    $bn = require dirname(__DIR__).'/lang/bn.php';
    $bad = [];

    foreach ($en as $key => $line) {
        preg_match_all('/:[a-z_]+/', $line, $a);
        preg_match_all('/:[a-z_]+/', (string) ($bn[$key] ?? ''), $b);
        sort($a[0]);
        sort($b[0]);

        if ($a[0] !== $b[0]) {
            $bad[] = $key;
        }
    }

    same([], $bad, 'placeholders differ');
});

test('every translation key used in a template or controller exists', function () {
    $en = require dirname(__DIR__).'/lang/en.php';
    $used = [];
    $files = array_merge(
        glob(dirname(__DIR__).'/views/*.php') ?: [],
        glob(dirname(__DIR__).'/views/*/*.php') ?: [],
        glob(dirname(__DIR__).'/src/Controllers/*.php') ?: [],
    );

    foreach ($files as $file) {
        preg_match_all("/\bt\(\s*'([a-z_]+\.[a-z_.]+)'/", (string) file_get_contents($file), $m);
        array_push($used, ...$m[1]);
        // 'payment.'.$method style keys are checked by their known suffixes below.
    }

    // A key ending in a dot is the start of a family built at run time
    // ('return.status.'.$value): those are checked by listing the family below.
    $used = array_filter($used, static fn (string $key) => ! str_ends_with($key, '.'));
    $missing = array_values(array_diff(array_unique($used), array_keys($en)));

    same([], $missing, 'keys used but not defined');

    $families = [
        'payment.' => ['online', 'online_help', 'cod', 'cod_help'],
        'return.reason.' => ['damaged', 'wrong_item', 'not_as_described', 'missing_parts', 'changed_mind', 'other'],
        'return.status.' => ['requested', 'approved', 'rejected', 'received', 'refunded', 'cancelled'],
        'status.' => ['payment_pending', 'customer_verification_pending', 'confirmed', 'processing', 'shipped', 'delivered', 'cancelled', 'refunded'],
        'closed.' => ['maintenance_title', 'maintenance_body', 'preparing_title', 'preparing_body', 'unreachable_title', 'unreachable_body', 'unavailable_title', 'unavailable_body'],
    ];

    foreach ($families as $prefix => $names) {
        foreach ($names as $name) {
            ok(isset($en[$prefix.$name]), 'missing '.$prefix.$name);
        }
    }
});
