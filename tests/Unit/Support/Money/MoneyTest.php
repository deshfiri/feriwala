<?php

use App\Support\Money\Currency;
use App\Support\Money\Exceptions\CurrencyMismatch;
use App\Support\Money\Money;

describe('construction', function () {
    it('defaults to the platform base currency', function () {
        expect(Money::of(100)->currency)->toBe(Currency::BDT);
    });

    it('holds minor units exactly', function () {
        expect(Money::of(123456)->minorUnits)->toBe(123456)
            ->and(Money::of(123456)->toDecimal())->toBe('1234.56');
    });

    it('parses decimal strings', function (string $input, int $expected) {
        expect(Money::fromDecimal($input)->minorUnits)->toBe($expected);
    })->with([
        ['0', 0],
        ['1', 100],
        ['1.5', 150],
        ['1.50', 150],
        ['1234.56', 123456],
        ['-1234.56', -123456],
        ['0.01', 1],
        ['-0.01', -1],
    ]);

    it('rounds half up when given more precision than the currency has', function () {
        expect(Money::fromDecimal('1.005')->minorUnits)->toBe(101)
            ->and(Money::fromDecimal('1.004')->minorUnits)->toBe(100)
            ->and(Money::fromDecimal('-1.005')->minorUnits)->toBe(-101);
    });

    it('rejects values that are not decimal numbers', function () {
        expect(fn () => Money::fromDecimal('twelve'))->toThrow(InvalidArgumentException::class);
    });

    it('never lets a float smuggle in extra precision', function () {
        // 0.1 + 0.2 is 0.30000000000000004 as a float; as money it must be exactly 30 poisha.
        expect(Money::fromDecimal(0.1 + 0.2)->minorUnits)->toBe(30);
    });
});

describe('arithmetic', function () {
    it('adds and subtracts', function () {
        $a = Money::of(1000);
        $b = Money::of(250);

        expect($a->plus($b)->minorUnits)->toBe(1250)
            ->and($a->minus($b)->minorUnits)->toBe(750);
    });

    it('is immutable', function () {
        $original = Money::of(1000);
        $original->plus(Money::of(500));

        expect($original->minorUnits)->toBe(1000);
    });

    it('takes percentages, rounding half up', function () {
        expect(Money::of(10000)->percentage(15)->minorUnits)->toBe(1500)
            ->and(Money::of(333)->percentage(10)->minorUnits)->toBe(33)
            ->and(Money::of(335)->percentage(10)->minorUnits)->toBe(34);
    });

    it('negates and absolutes', function () {
        expect(Money::of(-500)->negated()->minorUnits)->toBe(500)
            ->and(Money::of(-500)->absolute()->minorUnits)->toBe(500)
            ->and(Money::of(500)->absolute()->minorUnits)->toBe(500);
    });

    it('refuses to mix currencies', function () {
        $bdt = Money::of(100, Currency::BDT);
        $usd = Money::of(100, Currency::USD);

        expect(fn () => $bdt->plus($usd))->toThrow(CurrencyMismatch::class)
            ->and(fn () => $bdt->minus($usd))->toThrow(CurrencyMismatch::class)
            ->and(fn () => $bdt->greaterThan($usd))->toThrow(CurrencyMismatch::class);
    });
});

describe('allocation', function () {
    it('splits without losing a minor unit', function () {
        $shares = Money::of(100)->allocate([1, 1, 1]);

        expect(array_map(fn (Money $m) => $m->minorUnits, $shares))->toBe([34, 33, 33])
            ->and(array_sum(array_map(fn (Money $m) => $m->minorUnits, $shares)))->toBe(100);
    });

    it('gives the remainder to the largest ratios first', function () {
        $shares = Money::of(101)->allocate(['fee' => 1, 'package' => 9]);

        expect($shares['package']->minorUnits)->toBe(91)
            ->and($shares['fee']->minorUnits)->toBe(10);
    });

    it('preserves the total for awkward splits', function (int $amount, array $ratios) {
        $shares = Money::of($amount)->allocate($ratios);
        $total = array_sum(array_map(fn (Money $m) => $m->minorUnits, $shares));

        expect($total)->toBe($amount);
    })->with([
        [100, [1, 1, 1]],
        [1, [1, 1, 1]],
        [9999, [7, 11, 13]],
        [12345, [1]],
        [-100, [1, 1, 1]],
    ]);

    it('rejects empty or non-positive ratios', function () {
        expect(fn () => Money::of(100)->allocate([]))->toThrow(InvalidArgumentException::class)
            ->and(fn () => Money::of(100)->allocate([0, 0]))->toThrow(InvalidArgumentException::class);
    });
});

describe('comparison', function () {
    it('reports sign', function () {
        expect(Money::zero()->isZero())->toBeTrue()
            ->and(Money::of(1)->isPositive())->toBeTrue()
            ->and(Money::of(-1)->isNegative())->toBeTrue();
    });

    it('compares equal amounts of the same currency', function () {
        expect(Money::of(100)->equals(Money::of(100)))->toBeTrue()
            ->and(Money::of(100)->equals(Money::of(100, Currency::USD)))->toBeFalse();
    });

    it('orders amounts', function () {
        $small = Money::of(100);
        $large = Money::of(200);

        expect($large->greaterThan($small))->toBeTrue()
            ->and($small->lessThan($large))->toBeTrue()
            ->and($small->lessThanOrEqualTo(Money::of(100)))->toBeTrue()
            ->and($small->greaterThanOrEqualTo(Money::of(100)))->toBeTrue();
    });
});

describe('presentation', function () {
    it('formats with symbol and separators', function () {
        expect(Money::of(123456789)->format())->toBe('৳1,234,567.89')
            ->and(Money::of(123456789)->format(withSymbol: false))->toBe('1,234,567.89');
    });

    it('renders decimals with the currency scale', function () {
        expect(Money::of(5)->toDecimal())->toBe('0.05')
            ->and(Money::of(50)->toDecimal())->toBe('0.50')
            ->and(Money::of(-5)->toDecimal())->toBe('-0.05');
    });

    it('serialises for the front end without exposing raw arithmetic', function () {
        expect(Money::of(123456)->jsonSerialize())->toBe([
            'minor_units' => 123456,
            'currency' => 'BDT',
            'decimal' => '1234.56',
            'formatted' => '৳1,234.56',
        ]);
    });
});
