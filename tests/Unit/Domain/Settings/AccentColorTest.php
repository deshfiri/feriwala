<?php

use App\Domain\Settings\AccentColor;

/*
 * The accent colour value: what it accepts, and which label colour it lays
 * over itself. Every page writes these values into a style attribute, so the
 * format check is the whole of the injection defence.
 */

it('accepts a six-digit hex and normalises it to lower case', function () {
    expect(AccentColor::fromHex('#1D4ED8')->hex())->toBe('#1d4ed8');
});

it('refuses anything that is not a six-digit hex', function (string $value) {
    expect(AccentColor::isValidHex($value))->toBeFalse()
        ->and(fn () => AccentColor::fromHex($value))->toThrow(InvalidArgumentException::class);
})->with([
    'empty' => '',
    'three digits' => '#fff',
    'eight digits' => '#e11d48ff',
    'no hash' => 'e11d48',
    'a name' => 'red',
    'not hex' => '#gggggg',
    'a trailing declaration' => '#e11d48; color: red',
    'a newline' => "#e11d48\n",
]);

it('ships the default the stylesheet uses and treats it as the default', function () {
    $css = (string) file_get_contents(dirname(__DIR__, 4).'/resources/css/app.css');

    expect($css)->toContain('--brand-base: '.AccentColor::DEFAULT_HEX.';')
        ->and(AccentColor::default()->isDefault())->toBeTrue()
        ->and(AccentColor::fromHex('#E11D48')->isDefault())->toBeTrue()
        ->and(AccentColor::fromHex('#059669')->isDefault())->toBeFalse();
});

it('lays whichever label colour contrasts more on the accent', function (string $hex, string $on) {
    expect(AccentColor::fromHex($hex)->onColor())->toBe($on);
})->with([
    'the default crimson' => ['#e11d48', AccentColor::LIGHT_TEXT],
    'a deep blue' => ['#1d4ed8', AccentColor::LIGHT_TEXT],
    'slate' => ['#1d2630', AccentColor::LIGHT_TEXT],
    'a pale yellow' => ['#fde047', AccentColor::DARK_TEXT],
    'white' => ['#ffffff', AccentColor::DARK_TEXT],
]);

it('decides the dark-mode label on the lifted accent, not the base one', function () {
    // Crimson carries white text, but lifted with 25% white it is light enough
    // that the slate text reads better — which is what dark mode shows.
    $crimson = AccentColor::default();

    expect($crimson->onColor())->toBe(AccentColor::LIGHT_TEXT)
        ->and($crimson->onLiftedColor())->toBe(AccentColor::DARK_TEXT)
        ->and(AccentColor::fromHex('#1d2630')->onLiftedColor())->toBe(AccentColor::LIGHT_TEXT);
});

it('flags an accent too light to read as text on a white card', function () {
    expect(AccentColor::fromHex('#e11d48')->isReadableAsText())->toBeTrue()
        ->and(AccentColor::fromHex('#1d4ed8')->isReadableAsText())->toBeTrue()
        ->and(AccentColor::fromHex('#fde047')->isReadableAsText())->toBeFalse()
        ->and(AccentColor::fromHex('#ea580c')->isReadableAsText())->toBeFalse()
        ->and(round(AccentColor::fromHex('#000000')->contrastOnWhite(), 2))->toBe(21.0);
});

it('shares exactly the three values the shell writes into the page', function () {
    expect(AccentColor::fromHex('#1D4ED8')->toArray())->toBe([
        'color' => '#1d4ed8',
        'on' => AccentColor::LIGHT_TEXT,
        'lifted_on' => AccentColor::fromHex('#1d4ed8')->onLiftedColor(),
    ]);
});
