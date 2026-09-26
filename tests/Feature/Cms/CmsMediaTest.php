<?php

use App\Domain\Cms\Actions\UploadCmsMedia;
use App\Domain\Cms\Exceptions\CmsMediaRefused;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
 * CMS media uploads (§4, §34) — the type is read from the file's own bytes,
 * never the browser's claim, and every meaningful image needs alt text in
 * both locales before it may be placed on a published page.
 */

beforeEach(function () {
    Storage::fake('public');
});

it('accepts a real JPEG and stores it at a random path, not the original filename', function () {
    $file = UploadedFile::fake()->image('hero.jpg', 800, 600);

    $media = app(UploadCmsMedia::class)->handle($file);

    expect($media->mime_type)->toBe('image/jpeg')
        ->and($media->original_filename)->toBe('hero.jpg')
        ->and($media->path)->not->toContain('hero')
        ->and($media->width)->toBe(800)
        ->and($media->height)->toBe(600);

    Storage::disk('public')->assertExists($media->path);
});

it('rejects a file whose real bytes are not an accepted image type, whatever its extension claims', function () {
    // UploadedFile::fake() derives its MIME type from the given filename's
    // extension, not the content -- it cannot exercise byte-sniffing. A real
    // UploadedFile constructed from an actual temp file does, matching how
    // ProductMediaTest proves the same guarantee for catalogue media.
    $path = tempnam(sys_get_temp_dir(), 'cms-media');
    file_put_contents($path, '<!doctype html><html><body><script>alert(1)</script></body></html>');

    $file = new UploadedFile($path, 'disguised.jpg', 'image/jpeg', null, true);

    expect(fn () => app(UploadCmsMedia::class)->handle($file))
        ->toThrow(CmsMediaRefused::class);
});

it('rejects an oversized image', function () {
    $file = UploadedFile::fake()->image('huge.jpg')->size(6 * 1024); // 6 MB, over the 5 MB cap

    expect(fn () => app(UploadCmsMedia::class)->handle($file))
        ->toThrow(CmsMediaRefused::class);
});

it('has no required alt text until both locales are written', function () {
    $file = UploadedFile::fake()->image('hero.jpg');
    $media = app(UploadCmsMedia::class)->handle($file);

    expect($media->hasRequiredAltText())->toBeFalse();

    $media->update(['alt_text_en' => 'A shopkeeper packing an order']);
    expect($media->hasRequiredAltText())->toBeFalse();

    $media->update(['alt_text_bn' => 'একজন দোকানদার অর্ডার প্যাক করছেন']);
    expect($media->hasRequiredAltText())->toBeTrue();
});
