<?php

use App\Support\Money\Currency;
use App\Support\Money\Exceptions\CurrencyMismatch;
use App\Support\Money\Money;

describe('construction', function () {
    it('defaults to the platform base currency', function () {
        expect(Money::fromDecimal('100')->currency)->toBe(Currency::BDT);
    });

    it('treats a bare number as Taka, not as a minor unit', function () {
        // D26: the single most important property of this object. Under the old
        // architecture 100 meant one Taka; it now means a hundred of them.
        expect(Money::fromDecimal(100)->amount)->toBe('100.00')
            ->and(Money::fromDecimal('100')->format())->toBe('৳100.00');
    });

    it('holds a decimal amount exactly', function (string|int $input, string $expected) {
        expect(Money::fromDecimal($input)->amount)->toBe($expected);
    })->with([
        ['0', '0.00'],
        ['1', '1.00'],
        ['1.5', '1.50'],
        ['1.50', '1.50'],
        ['100.50', '100.50'],
        ['1234.56', '1234.56'],
        ['-1234.56', '-1234.56'],
        ['0.01', '0.01'],
        ['-0.01', '-0.01'],
        [100, '100.00'],
    ]);

    it('normalises negative zero to zero', function () {
        expect(Money::fromDecimal('-0.00')->amount)->toBe('0.00')
            ->and(Money::fromDecimal('-0.00')->isZero())->toBeTrue();
    });

    it('rounds half away from zero when given more precision than the currency has', function () {
        // round(1.005, 2) is 1.0 in binary floating point. Exact decimals do not
        // have that problem, which is the whole reason for bcmath here.
        expect(Money::fromDecimal('1.005')->amount)->toBe('1.01')
            ->and(Money::fromDecimal('1.004')->amount)->toBe('1.00')
            ->and(Money::fromDecimal('-1.005')->amount)->toBe('-1.01');
    });

    it('rejects values that are not decimal numbers', function () {
        expect(fn () => Money::fromDecimal('twelve'))->toThrow(InvalidArgumentException::class)
            ->and(fn () => Money::fromDecimal('1,234.56'))->toThrow(InvalidArgumentException::class)
            ->and(fn () => Money::fromDecimal('1e3'))->toThrow(InvalidArgumentException::class);
    });

    it('refuses an amount too large for its column', function () {
        expect(fn () => Money::fromDecimal(str_repeat('9', 18)))->toThrow(InvalidArgumentException::class);
    });

    it('holds the largest amount a money column supports', function () {
        expect(Money::fromDecimal('99999999999999999.99')->amount)->toBe('99999999999999999.99');
    });
});

describe('arithmetic', function () {
    it('adds and subtracts exactly', function () {
        expect(Money::fromDecimal('1.00')->plus(Money::fromDecimal('2.50'))->amount)->toBe('3.50')
            ->and(Money::fromDecimal('100')->minus(Money::fromDecimal('25.50'))->amount)->toBe('74.50');
    });

    it('does not drift where a float would', function () {
        // 0.1 + 0.2 is 0.30000000000000004 as a float.
        expect(Money::fromDecimal('0.10')->plus(Money::fromDecimal('0.20'))->amount)->toBe('0.30');
    });

    it('is immutable', function () {
        $original = Money::fromDecimal('10.00');
        $original->plus(Money::fromDecimal('5.00'));

        expect($original->amount)->toBe('10.00');
    });

    it('takes percentages exactly', function () {
        expect(Money::fromDecimal('100')->percentage(5)->amount)->toBe('5.00')
            ->and(Money::fromDecimal('100')->percentage(15)->amount)->toBe('15.00')
            ->and(Money::fromDecimal('3.33')->percentage(10)->amount)->toBe('0.33')
            ->and(Money::fromDecimal('3.35')->percentage(10)->amount)->toBe('0.34');
    });

    it('takes a fractional percentage without a float', function () {
        // 7.5% as a float is 0.07500000000000001.
        expect(Money::fromDecimal('200')->percentage('7.5')->amount)->toBe('15.00');
    });

    it('honours the rounding mode it is given', function () {
        expect(Money::fromDecimal('1.00')->percentage('0.5', RoundingMode::HalfAwayFromZero)->amount)->toBe('0.01')
            ->and(Money::fromDecimal('1.00')->percentage('0.5', RoundingMode::TowardsZero)->amount)->toBe('0.00');
    });

    it('multiplies by an exact scalar', function () {
        expect(Money::fromDecimal('2490.00')->multipliedBy(2)->amount)->toBe('4980.00')
            ->and(Money::fromDecimal('10.00')->multipliedBy('0.333')->amount)->toBe('3.33');
    });

    it('refuses a factor that is not an exact decimal', function () {
        expect(fn () => Money::fromDecimal('100')->multipliedBy('half'))->toThrow(InvalidArgumentException::class);
    });

    it('negates and absolutes', function () {
        expect(Money::fromDecimal('-5.00')->negated()->amount)->toBe('5.00')
            ->and(Money::fromDecimal('-5.00')->absolute()->amount)->toBe('5.00')
            ->and(Money::fromDecimal('5.00')->absolute()->amount)->toBe('5.00');
    });

    it('refuses to mix currencies', function () {
        $bdt = Money::fromDecimal('1.00', Currency::BDT);
        $usd = Money::fromDecimal('1.00', Currency::USD);

        expect(fn () => $bdt->plus($usd))->toThrow(CurrencyMismatch::class)
            ->and(fn () => $bdt->minus($usd))->toThrow(CurrencyMismatch::class)
            ->and(fn () => $bdt->greaterThan($usd))->toThrow(CurrencyMismatch::class);
    });
});

describe('allocation', function () {
    it('splits without losing a paisa', function () {
        $shares = Money::fromDecimal('1.00')->allocate([1, 1, 1]);

        expect(array_map(fn (Money $m) => $m->amount, $shares))->toBe(['0.34', '0.33', '0.33']);
    });

    it('gives the remainder to the largest ratios first', function () {
        $shares = Money::fromDecimal('1.01')->allocate(['fee' => 1, 'package' => 9]);

        expect($shares['package']->amount)->toBe('0.91')
            ->and($shares['fee']->amount)->toBe('0.10');
    });

    it('preserves the exact total for awkward splits', function (string $amount, array $ratios) {
        $shares = Money::fromDecimal($amount)->allocate($ratios);

        $total = array_reduce(
            $shares,
            fn (Money $carry, Money $share) => $carry->plus($share),
            Money::zero(),
        );

        expect($total->amount)->toBe(Money::fromDecimal($amount)->amount);
    })->with([
        ['1.00', [1, 1, 1]],
        ['0.01', [1, 1, 1]],
        ['99.99', [7, 11, 13]],
        ['123.45', [1]],
        ['-1.00', [1, 1, 1]],
        ['0.00', [1, 1, 1]],
    ]);

    it('rejects empty or non-positive ratios', function () {
        expect(fn () => Money::fromDecimal('1.00')->allocate([]))->toThrow(InvalidArgumentException::class)
            ->and(fn () => Money::fromDecimal('1.00')->allocate([0, 0]))->toThrow(InvalidArgumentException::class);
    });
});

describe('comparison', function () {
    it('reports sign', function () {
        expect(Money::zero()->isZero())->toBeTrue()
            ->and(Money::fromDecimal('0.01')->isPositive())->toBeTrue()
            ->and(Money::fromDecimal('-0.01')->isNegative())->toBeTrue();
    });

    it('compares amounts written differently but worth the same', function () {
        expect(Money::fromDecimal('100')->equals(Money::fromDecimal('100.00')))->toBeTrue()
            ->and(Money::fromDecimal('1.5')->equals(Money::fromDecimal('1.50')))->toBeTrue()
            ->and(Money::fromDecimal('100')->equals(Money::fromDecimal('100', Currency::USD)))->toBeFalse();
    });

    it('orders amounts', function () {
        $small = Money::fromDecimal('1.00');
        $large = Money::fromDecimal('2.00');

        expect($large->greaterThan($small))->toBeTrue()
            ->and($small->lessThan($large))->toBeTrue()
            ->and($small->lessThanOrEqualTo(Money::fromDecimal('1.00')))->toBeTrue()
            ->and($small->greaterThanOrEqualTo(Money::fromDecimal('1.00')))->toBeTrue();
    });
});

describe('presentation', function () {
    it('formats with symbol and separators', function () {
        expect(Money::fromDecimal('1234567.89')->format())->toBe('৳1,234,567.89')
            ->and(Money::fromDecimal('1234567.89')->format(withSymbol: false))->toBe('1,234,567.89');
    });

    it('groups without routing through a float', function () {
        // A float loses the last digits of this figure entirely.
        expect(Money::fromDecimal('99999999999999999.99')->format(withSymbol: false))
            ->toBe('99,999,999,999,999,999.99');
    });

    it('formats negatives and small amounts', function () {
        expect(Money::fromDecimal('-1234.56')->format())->toBe('৳-1,234.56')
            ->and(Money::fromDecimal('0.05')->format())->toBe('৳0.05')
            ->and(Money::fromDecimal('100')->format())->toBe('৳100.00');
    });

    it('renders decimals at the currency scale', function () {
        expect(Money::fromDecimal('0.05')->toDecimal())->toBe('0.05')
            ->and(Money::fromDecimal('0.5')->toDecimal())->toBe('0.50')
            ->and(Money::fromDecimal('-0.05')->toDecimal())->toBe('-0.05');
    });

    it('serialises flat Taka, with no minor units on the wire', function () {
        expect(Money::fromDecimal('1234.56')->jsonSerialize())->toBe([
            'amount' => '1234.56',
            'currency' => 'BDT',
            'formatted' => '৳1,234.56',
        ]);
    });
});
