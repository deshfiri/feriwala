<?php

use App\Domain\Catalog\ProductBarcode;

it('computes the check digit of a known EAN-13', function () {
    // A widely used GS1 example: 400638133393 checks to 1.
    expect(ProductBarcode::checkDigit('400638133393'))->toBe(1);
});

it('builds the documented 95-module pattern', function () {
    $modules = ProductBarcode::modules('4006381333931');

    expect($modules)->toHaveLength(95)
        ->and($modules)->toStartWith('101') // start guard
        ->and($modules)->toEndWith('101') // end guard
        ->and(substr($modules, 45, 5))->toBe('01010'); // middle guard
});

it('renders an SVG that carries the 13 digits and a bar for every ink module', function () {
    $svg = ProductBarcode::toSvg('4006381333931');

    expect($svg)->toContain('<svg')
        ->and($svg)->toContain('4006381333931')
        ->and(substr_count($svg, '<rect'))->toBe(substr_count(ProductBarcode::modules('4006381333931'), '1') + 1);
});
