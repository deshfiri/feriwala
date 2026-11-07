<?php

use Illuminate\Support\Arr;

/**
 * Every word in the sidebar is in the language the reader chose. The navigation
 * is built in `use-navigation.ts`, and an English literal there is invisible to
 * the translation files, so a Bangla reader meets it untranslated. These guard
 * the two ways that happens: a title typed straight into the hook, and a line
 * the hook asks for that a language file does not have.
 */
function navigationTestSource(): string
{
    return (string) file_get_contents(base_path('resources/js/hooks/use-navigation.ts'));
}

/**
 * The `nav.*` lines the navigation code asks for, anywhere in the front end.
 *
 * @return list<string>
 */
function navigationTestKeysInUse(): array
{
    $keys = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('resources/js'), FilesystemIterator::SKIP_DOTS)) as $file) {
        if (! preg_match('/\.(ts|tsx)$/', $file->getFilename()) || str_contains($file->getFilename(), '.test.')) {
            continue;
        }

        if (preg_match_all("/\bt\(\s*'nav\.([a-z_.0-9]+)'/", (string) file_get_contents($file->getPathname()), $found)) {
            array_push($keys, ...$found[1]);
        }
    }

    return array_values(array_unique($keys));
}

it('has no navigation title typed in English straight into the hook', function () {
    preg_match_all("/^\s*(?:title|label):\s*'([^']+)'/m", navigationTestSource(), $literals);

    expect($literals[1])->toBe([]);
});

it('has an English and a Bangla line for every nav key the front end uses', function (string $locale) {
    $lines = Arr::dot(require lang_path("{$locale}/nav.php"));

    $missing = array_values(array_filter(
        navigationTestKeysInUse(),
        fn (string $key): bool => ! isset($lines[$key]) || trim((string) $lines[$key]) === '',
    ));

    expect($missing)->toBe([]);
})->with(['en', 'bn']);

it('gives every English nav line a Bangla one that is not just the English again', function () {
    $en = Arr::dot(require lang_path('en/nav.php'));
    $bn = Arr::dot(require lang_path('bn/nav.php'));

    $untranslated = array_values(array_filter(
        array_keys($en),
        fn (string $key): bool => ! isset($bn[$key]) || ($bn[$key] === $en[$key] && preg_match('/[A-Za-z]{3}/', (string) $en[$key]) === 1),
    ));

    expect($untranslated)->toBe([]);
});
