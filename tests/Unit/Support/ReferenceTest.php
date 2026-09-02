<?php

use App\Support\References\Reference;
use App\Support\References\ReferencePrefix;

it('builds a reference in the documented shape', function () {
    $reference = Reference::generate(ReferencePrefix::Order, new DateTimeImmutable('2026-09-01'));

    expect($reference)->toStartWith('ORD-260901-')
        ->and($reference)->toHaveLength(19);
});

it('recognises its own references', function () {
    foreach (ReferencePrefix::cases() as $prefix) {
        expect(Reference::matches(Reference::generate($prefix), $prefix))->toBeTrue();
    }
});

it('does not confuse one prefix for another', function () {
    $order = Reference::generate(ReferencePrefix::Order);

    expect(Reference::matches($order, ReferencePrefix::Payment))->toBeFalse();
});

it('omits characters that are misread when copied by hand', function () {
    $alphabet = Reference::ALPHABET;

    foreach (['I', 'L', 'O', 'U', '0', '1'] as $ambiguous) {
        expect($alphabet)->not->toContain($ambiguous);
    }
});

it('produces distinct references across many generations', function () {
    $references = collect(range(1, 2000))
        ->map(fn () => Reference::generate(ReferencePrefix::Transaction))
        ->unique();

    expect($references)->toHaveCount(2000);
});

it('rejects malformed references', function (string $candidate) {
    expect(Reference::matches($candidate, ReferencePrefix::Order))->toBeFalse();
})->with([
    'ORD-260901-SHORT',
    'ORD-26091-K7M3QX9P',
    'ORDER-260901-K7M3QX9P',
    'ORD-260901-K7M3QX9I',
    '',
]);
