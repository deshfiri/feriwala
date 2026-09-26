<?php

use App\Domain\Cms\Enums\SectionKind;
use App\Domain\Cms\Models\Media;
use App\Domain\Cms\Support\SectionContentValidator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * A real Media row with both alt texts already set, so a test that only
 * cares about the media *reference* being accepted does not also have to
 * think about the separate alt-text requirement.
 */
function sectionValidatorTestMedia(bool $withAltText = true): Media
{
    return Media::query()->create([
        'disk' => 'public',
        'path' => 'cms/test-'.Str::random(8).'.jpg',
        'original_filename' => 'test.jpg',
        'mime_type' => 'image/jpeg',
        'size_bytes' => 1000,
        'width' => 800,
        'height' => 600,
        'alt_text_en' => $withAltText ? 'A shopkeeper packing an order' : null,
        'alt_text_bn' => $withAltText ? 'একজন দোকানদার অর্ডার প্যাক করছেন' : null,
    ]);
}

/*
 * The one validated boundary every section's content passes through before
 * it is ever saved (§34's content-safety requirements).
 */

it('strips a script tag from rich text and keeps the safe surrounding text', function () {
    $validated = app(SectionContentValidator::class)->validate(SectionKind::Cta, [
        'heading' => ['en' => 'Heading', 'bn' => 'শিরোনাম'],
        'body' => ['en' => 'Safe text<script>alert(1)</script> more text', 'bn' => null],
        'primary_cta' => ['label' => ['en' => 'Go', 'bn' => 'যান'], 'href' => '/register'],
    ]);

    expect($validated['body']['en'])
        ->toContain('Safe text')
        ->toContain('more text')
        ->not->toContain('<script')
        ->not->toContain('alert(1)');
});

it('strips an event-handler attribute from an otherwise allowed tag', function () {
    $validated = app(SectionContentValidator::class)->validate(SectionKind::Cta, [
        'heading' => ['en' => 'Heading', 'bn' => 'শিরোনাম'],
        'body' => ['en' => '<a href="/register" onclick="steal()">link</a>', 'bn' => null],
        'primary_cta' => ['label' => ['en' => 'Go', 'bn' => 'যান'], 'href' => '/register'],
    ]);

    expect($validated['body']['en'])
        ->toContain('<a href="/register">link</a>')
        ->not->toContain('onclick');
});

it('rejects a javascript: URL on an anchor inside rich text', function () {
    $validated = app(SectionContentValidator::class)->validate(SectionKind::Cta, [
        'heading' => ['en' => 'Heading', 'bn' => 'শিরোনাম'],
        'body' => ['en' => '<a href="javascript:alert(1)">click</a>', 'bn' => null],
        'primary_cta' => ['label' => ['en' => 'Go', 'bn' => 'যান'], 'href' => '/register'],
    ]);

    expect($validated['body']['en'])
        ->not->toContain('javascript:')
        ->toContain('click');
});

it('rejects a CTA href that is not a registered route or a site-relative path', function () {
    expect(fn () => app(SectionContentValidator::class)->validate(SectionKind::Cta, [
        'heading' => ['en' => 'Heading', 'bn' => 'শিরোনাম'],
        'primary_cta' => ['label' => ['en' => 'Go', 'bn' => 'যান'], 'href' => 'javascript:alert(1)'],
    ]))->toThrow(ValidationException::class);
});

it('rejects a scheme-relative CTA href', function () {
    expect(fn () => app(SectionContentValidator::class)->validate(SectionKind::Cta, [
        'heading' => ['en' => 'Heading', 'bn' => 'শিরোনাম'],
        'primary_cta' => ['label' => ['en' => 'Go', 'bn' => 'যান'], 'href' => '//evil.example.com'],
    ]))->toThrow(ValidationException::class);
});

it('accepts a real registered route name as a CTA href', function () {
    $validated = app(SectionContentValidator::class)->validate(SectionKind::Cta, [
        'heading' => ['en' => 'Heading', 'bn' => 'শিরোনাম'],
        'primary_cta' => ['label' => ['en' => 'Go', 'bn' => 'যান'], 'href' => 'register'],
    ]);

    expect($validated['primary_cta']['href'])->toBe('register');
});

it('rejects an unregistered route name as a CTA href', function () {
    expect(fn () => app(SectionContentValidator::class)->validate(SectionKind::Cta, [
        'heading' => ['en' => 'Heading', 'bn' => 'শিরোনাম'],
        'primary_cta' => ['label' => ['en' => 'Go', 'bn' => 'যান'], 'href' => 'no-such-route-name'],
    ]))->toThrow(ValidationException::class);
});

it('rejects a benefits icon outside the closed allow-list', function () {
    expect(fn () => app(SectionContentValidator::class)->validate(SectionKind::Benefits, [
        'heading' => ['en' => 'Benefits', 'bn' => 'সুবিধা'],
        'items' => [[
            'icon' => 'DangerousComponent',
            'heading' => ['en' => 'X', 'bn' => 'এক্স'],
        ]],
    ]))->toThrow(ValidationException::class);
});

it('refuses to validate content for a section kind with no schema yet', function () {
    expect(fn () => app(SectionContentValidator::class)->validate(SectionKind::Statistics, [
        'heading' => ['en' => 'Statistics', 'bn' => 'পরিসংখ্যান'],
    ]))->toThrow(ValidationException::class);
});

/*
 * Media fields (§34, Stage 7 addendum) — always the CMS media library's own
 * public_id, never a storage path, and never a reference to an asset that
 * has not yet been given alt text in both locales.
 */

it('accepts a hero with a real media reference', function () {
    $media = sectionValidatorTestMedia();

    $validated = app(SectionContentValidator::class)->validate(SectionKind::Hero, [
        'heading' => ['en' => 'Heading', 'bn' => 'শিরোনাম'],
        'primary_cta' => ['label' => ['en' => 'Go', 'bn' => 'যান'], 'href' => '/register'],
        'media_id' => $media->public_id,
        'media_position' => 'background',
        'media_fit' => 'cover',
    ]);

    expect($validated['media_id'])->toBe($media->public_id);
});

it('rejects a media_id that does not exist in the library', function () {
    expect(fn () => app(SectionContentValidator::class)->validate(SectionKind::Hero, [
        'heading' => ['en' => 'Heading', 'bn' => 'শিরোনাম'],
        'primary_cta' => ['label' => ['en' => 'Go', 'bn' => 'যান'], 'href' => '/register'],
        'media_id' => '01FAKE00000000000000000000',
    ]))->toThrow(ValidationException::class);
});

it('rejects a media reference that has not been given alt text yet', function () {
    $media = sectionValidatorTestMedia(withAltText: false);

    expect(fn () => app(SectionContentValidator::class)->validate(SectionKind::Hero, [
        'heading' => ['en' => 'Heading', 'bn' => 'শিরোনাম'],
        'primary_cta' => ['label' => ['en' => 'Go', 'bn' => 'যান'], 'href' => '/register'],
        'media_id' => $media->public_id,
    ]))->toThrow(ValidationException::class);
});

it('rejects an unknown media_position value', function () {
    $media = sectionValidatorTestMedia();

    expect(fn () => app(SectionContentValidator::class)->validate(SectionKind::Hero, [
        'heading' => ['en' => 'Heading', 'bn' => 'শিরোনাম'],
        'primary_cta' => ['label' => ['en' => 'Go', 'bn' => 'যান'], 'href' => '/register'],
        'media_id' => $media->public_id,
        'media_position' => 'diagonal',
    ]))->toThrow(ValidationException::class);
});

it('accepts a video section with an approved provider and a captioned poster', function () {
    $poster = sectionValidatorTestMedia();

    $validated = app(SectionContentValidator::class)->validate(SectionKind::Video, [
        'video_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
        'poster_media_id' => $poster->public_id,
    ]);

    expect($validated['video_url'])->toContain('youtube.com');
});

it('rejects a video url from a host outside the approved provider list', function () {
    $poster = sectionValidatorTestMedia();

    expect(fn () => app(SectionContentValidator::class)->validate(SectionKind::Video, [
        'video_url' => 'https://attacker.example.com/embed.html',
        'poster_media_id' => $poster->public_id,
    ]))->toThrow(ValidationException::class);
});

it('requires a poster image for a video section', function () {
    expect(fn () => app(SectionContentValidator::class)->validate(SectionKind::Video, [
        'video_url' => 'https://vimeo.com/76979871',
    ]))->toThrow(ValidationException::class);
});

it('accepts a testimonials section with a captioned avatar', function () {
    $avatar = sectionValidatorTestMedia();

    $validated = app(SectionContentValidator::class)->validate(SectionKind::Testimonials, [
        'items' => [[
            'quote' => ['en' => 'Feriwala changed how we run our shop.', 'bn' => 'ফেরিওয়ালা আমাদের দোকান চালানোর ধরন বদলে দিয়েছে।'],
            'author_name' => 'Karim Traders',
            'media_id' => $avatar->public_id,
        ]],
    ]);

    expect($validated['items'][0]['author_name'])->toBe('Karim Traders');
});

it('requires a logo for every clients-and-partners item', function () {
    expect(fn () => app(SectionContentValidator::class)->validate(SectionKind::ClientsPartners, [
        'items' => [[
            'name' => 'Acme Distributors',
        ]],
    ]))->toThrow(ValidationException::class);
});

it('accepts an about section with an image and body copy', function () {
    $media = sectionValidatorTestMedia();

    $validated = app(SectionContentValidator::class)->validate(SectionKind::About, [
        'heading' => ['en' => 'About Feriwala', 'bn' => 'ফেরিওয়ালা সম্পর্কে'],
        'body' => ['en' => 'An ERP for wholesale and dropshipping.', 'bn' => 'পাইকারি ও ড্রপশিপিং-এর জন্য একটি ইআরপি।'],
        'media_id' => $media->public_id,
        'media_position' => 'right',
    ]);

    expect($validated['heading']['en'])->toBe('About Feriwala');
});
