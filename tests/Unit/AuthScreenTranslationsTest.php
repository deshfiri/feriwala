<?php

use Illuminate\Support\Arr;

/**
 * Every line of one language file, keyed by its dotted path.
 *
 * @return array<string, string>
 */
function authScreenTranslationLines(string $locale, string $file): array
{
    return Arr::dot([$file => require dirname(__DIR__, 2)."/lang/{$locale}/{$file}.php"]);
}

/**
 * The literal keys the sign-in, registration and recovery pages pass to `t()`,
 * mapped to the pages that use them.
 *
 * @return array<string, list<string>>
 */
function authScreenTranslationKeysUsed(): array
{
    $pages = array_merge(
        glob(dirname(__DIR__, 2).'/resources/js/pages/auth/*.tsx') ?: [],
        glob(dirname(__DIR__, 2).'/resources/js/pages/supplier/auth/*.tsx') ?: [],
    );

    $used = [];

    foreach ($pages as $page) {
        preg_match_all(
            "/\bt\(\s*'((?:auth|supplier)\.[a-z_.]+)'/",
            (string) file_get_contents($page),
            $matches,
        );

        foreach ($matches[1] as $key) {
            $used[$key][] = basename($page);
        }
    }

    return $used;
}

it('keeps a language file identical in shape between English and Bangla', function (string $file) {
    $english = authScreenTranslationLines('en', $file);
    $bangla = authScreenTranslationLines('bn', $file);

    expect(array_keys(array_diff_key($english, $bangla)))->toBe([])
        ->and(array_keys(array_diff_key($bangla, $english)))->toBe([]);
})->with(['auth', 'supplier']);

it('has a line for every key the sign-in, registration and recovery pages ask for', function () {
    $lines = array_merge(
        authScreenTranslationLines('en', 'auth'),
        authScreenTranslationLines('en', 'supplier'),
    );

    expect(array_diff_key(authScreenTranslationKeysUsed(), $lines))->toBe([]);
});
