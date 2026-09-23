<?php

use App\Support\Money\Currency;
use App\Support\Money\DecimalAmount;
use App\Support\Money\Exceptions\InvalidDecimalAmount;

/*
 * The one boundary a human-entered Taka amount crosses (§36.1). Stricter
 * than Money::fromDecimal() on purpose: that parser rounds a third decimal
 * place because an internal caller is usually computing a fraction, but a
 * person who typed one by hand almost certainly made a mistake, so this
 * refuses it instead of silently rounding to an amount they did not enter.
 */
describe('exact conversion', function () {
    it('keeps a typed Taka amount exactly as typed, never scaled by 100', function (string $input, string $expected) {
        expect(DecimalAmount::parse($input)->amount)->toBe($expected);
    })->with([
        ['0', '0.00'],
        ['0.01', '0.01'],
        ['1', '1.00'],
        ['1.5', '1.50'],
        ['1.50', '1.50'],
        ['100', '100.00'],
        ['100.50', '100.50'],
        ['500', '500.00'],
        ['500.25', '500.25'],
        ['9999999.99', '9999999.99'],
    ]);

    it('accepts a large, legitimate amount', function () {
        expect(DecimalAmount::parse('1000000.00')->amount)->toBe('1000000.00');
    });
});

describe('refusals', function () {
    it('refuses more decimal places than the currency has, rather than rounding them away', function () {
        expect(fn () => DecimalAmount::parse('1.505'))->toThrow(InvalidDecimalAmount::class);
        expect(fn () => DecimalAmount::parse('500.999'))->toThrow(InvalidDecimalAmount::class);
    });

    it('refuses scientific notation', function () {
        expect(fn () => DecimalAmount::parse('1e10'))->toThrow(InvalidDecimalAmount::class);
        expect(fn () => DecimalAmount::parse('1.5E2'))->toThrow(InvalidDecimalAmount::class);
    });

    it('refuses malformed separators and non-numeric input', function (string $input) {
        expect(fn () => DecimalAmount::parse($input))->toThrow(InvalidDecimalAmount::class);
    })->with([
        ['1,000.50'],
        ['1.2.3'],
        ['abc'],
        ['NaN'],
        ['Infinity'],
        ['12 34'],
        [''],
        ['   '],
        ['৳500'],
    ]);

    it('refuses overflow', function () {
        expect(fn () => DecimalAmount::parse('99999999999999.00'))->toThrow(InvalidDecimalAmount::class);
    });

    it('refuses a negative amount unless the caller explicitly allows one', function () {
        expect(fn () => DecimalAmount::parse('-500.00'))->toThrow(InvalidDecimalAmount::class);
        expect(DecimalAmount::parse('-500.00', allowNegative: true)->amount)->toBe('-500.00');
    });
});

describe('parseOrNull', function () {
    it('returns null for a blank optional field, without throwing', function (?string $input) {
        expect(DecimalAmount::parseOrNull($input))->toBeNull();
    })->with([[null], ['']]);

    it('parses a present value the same way parse() does', function () {
        expect(DecimalAmount::parseOrNull('500.25')?->amount)->toBe('500.25');
    });
});

describe('currency', function () {
    it('honours a currency other than the platform base', function () {
        expect(DecimalAmount::parse('10.00', Currency::USD)->currency)->toBe(Currency::USD);
    });
});
