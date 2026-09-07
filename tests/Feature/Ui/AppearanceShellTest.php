<?php

use Illuminate\Support\Facades\File;

/*
 * The shared appearance surface (§33.1).
 *
 * Two things here are easy to break and impossible to notice in a code review:
 * the colour painted before the stylesheet arrives, and a translation key that
 * exists in one language and not the other.
 */

/**
 * @return array{light: string, dark: string}
 */
function appearanceCanvasTokens(): array
{
    $css = File::get(resource_path('css/app.css'));

    // `:root` first, then the `.dark` block that follows it.
    preg_match_all('/--background:\s*(oklch\([^)]+\));/', $css, $matches);

    expect($matches[1])->toHaveCount(2, 'app.css should define --background once for each theme.');

    return ['light' => $matches[1][0], 'dark' => $matches[1][1]];
}

describe('the pre-hydration canvas', function () {
    it('paints the colour the page is about to be, in both themes', function () {
        /*
         * The failure this guards: the document is painted before app.css loads,
         * so if these two values drift from `--background` every load opens with
         * a flash of a colour the page then stops being. They had drifted —
         * white against a warm off-white canvas, and a near-black against a
         * warmer dark one.
         */
        $tokens = appearanceCanvasTokens();
        $blade = File::get(resource_path('views/app.blade.php'));

        expect($blade)->toContain($tokens['light'])
            ->and($blade)->toContain($tokens['dark']);
    });

    it('reaches the browser on a page nobody has signed in to', function () {
        $tokens = appearanceCanvasTokens();

        $this->get(route('login'))
            ->assertOk()
            ->assertSee($tokens['light'], escape: false)
            ->assertSee($tokens['dark'], escape: false);
    });
});

describe('the appearance and settings vocabulary', function () {
    it('says the same things in both languages', function (string $key) {
        // A key present in one language and missing in the other renders the
        // raw dotted path to the reader — visible only to someone using that
        // language, which is exactly who is least likely to be testing.
        $english = trans($key, locale: 'en');
        $bangla = trans($key, locale: 'bn');

        expect($english)->not->toBe($key, "Missing English string for [{$key}].")
            ->and($bangla)->not->toBe($key, "Missing Bangla string for [{$key}].")
            ->and($bangla)->not->toBe($english, "Bangla for [{$key}] is still the English string.");
    })->with([
        'common.appearance.label',
        'common.appearance.description',
        'common.appearance.light',
        'common.appearance.dark',
        'common.appearance.system',
        'common.appearance.system_hint',
        'common.settings.title',
        'common.settings.description',
        'common.settings.nav.label',
        'common.settings.nav.profile',
        'common.settings.nav.security',
        'common.settings.nav.appearance',
        'common.settings.nav.package',
        'common.settings.nav.staff',
    ]);
});
