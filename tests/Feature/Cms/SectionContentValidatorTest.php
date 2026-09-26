<?php

use App\Domain\Cms\Enums\SectionKind;
use App\Domain\Cms\Support\SectionContentValidator;
use Illuminate\Validation\ValidationException;

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
    expect(fn () => app(SectionContentValidator::class)->validate(SectionKind::Testimonials, [
        'heading' => ['en' => 'Testimonials', 'bn' => 'প্রশংসাপত্র'],
    ]))->toThrow(ValidationException::class);
});
