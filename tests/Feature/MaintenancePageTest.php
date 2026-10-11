<?php

/*
 * The maintenance page is shown when the rest of the application may not be
 * there, so it has to stand on its own: no assets, no scripts, both languages.
 */

it('renders self-contained, in English and Bangla, with nothing to fetch', function () {
    $html = view('errors.503')->render();

    expect($html)->toContain("We'll be right back")
        ->and($html)->toContain('আমরা শিগগিরই ফিরে আসছি')
        ->and($html)->not->toContain('<img')
        ->and($html)->not->toContain('<script')
        ->and($html)->not->toContain('@vite')
        ->and($html)->not->toMatch('/(href|src)=["\']https?:/');
});
