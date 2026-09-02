<?php

use App\Domain\Settings\Enums\SettingType;
use App\Support\Money\Money;

describe('casting stored strings', function () {
    it('returns strings unchanged', function () {
        expect(SettingType::String->cast('SSLCommerz'))->toBe('SSLCommerz');
    });

    it('casts integers', function () {
        expect(SettingType::Integer->cast('7'))->toBe(7)
            ->and(SettingType::Integer->cast('0'))->toBe(0);
    });

    it('casts booleans from the values a form actually sends', function (string $stored, bool $expected) {
        expect(SettingType::Boolean->cast($stored))->toBe($expected);
    })->with([
        ['1', true],
        ['true', true],
        ['on', true],
        ['yes', true],
        ['0', false],
        ['false', false],
        ['off', false],
        ['', false],
    ]);

    it('keeps decimals as strings so a rate never becomes a float', function () {
        // 0.1 + 0.2 must never enter a commission calculation as a float.
        expect(SettingType::Decimal->cast('2.5'))->toBe('2.5')
            ->and(SettingType::Decimal->cast('2.5'))->toBeString();
    });

    it('casts money to a Money object in minor units', function () {
        $fee = SettingType::Money->cast('50000');

        expect($fee)->toBeInstanceOf(Money::class)
            ->and($fee->minorUnits)->toBe(50000)
            ->and($fee->format())->toBe('৳500.00');
    });

    it('casts json to an array', function () {
        expect(SettingType::Json->cast('{"bkash":true,"nagad":false}'))
            ->toBe(['bkash' => true, 'nagad' => false]);
    });

    it('returns an empty array for malformed json rather than null', function () {
        // A corrupted setting should degrade to "nothing configured",
        // not blow up a page.
        expect(SettingType::Json->cast('{not json'))->toBe([]);
    });

    it('passes null through for every type', function (SettingType $type) {
        expect($type->cast(null))->toBeNull();
    })->with(SettingType::cases());
});

describe('serialising typed values', function () {
    it('round-trips every type', function () {
        expect(SettingType::Integer->cast(SettingType::Integer->serialise(7)))->toBe(7)
            ->and(SettingType::Boolean->cast(SettingType::Boolean->serialise(true)))->toBeTrue()
            ->and(SettingType::Boolean->cast(SettingType::Boolean->serialise(false)))->toBeFalse()
            ->and(SettingType::String->cast(SettingType::String->serialise('bkash')))->toBe('bkash')
            ->and(SettingType::Json->cast(SettingType::Json->serialise(['a' => 1])))->toBe(['a' => 1]);
    });

    it('round-trips money without losing a poisha', function () {
        $original = Money::of(123456);

        $restored = SettingType::Money->cast(SettingType::Money->serialise($original));

        expect($restored->minorUnits)->toBe(123456)
            ->and($restored->equals($original))->toBeTrue();
    });

    it('accepts raw minor units for money as well as a Money object', function () {
        expect(SettingType::Money->serialise(50000))->toBe('50000');
    });

    it('stores booleans as 1 and 0', function () {
        expect(SettingType::Boolean->serialise(true))->toBe('1')
            ->and(SettingType::Boolean->serialise(false))->toBe('0');
    });

    it('serialises null as null for every type', function (SettingType $type) {
        expect($type->serialise(null))->toBeNull();
    })->with(SettingType::cases());
});
