<?php

use App\Domain\Cms\Actions\PublishPage;
use App\Domain\Cms\Actions\SaveSectionDraft;
use App\Domain\Cms\Enums\SectionKind;
use App\Domain\Cms\Models\Page;
use App\Domain\Cms\Models\SeoSetting;
use App\Domain\Cms\Support\PublishedPageReader;
use Illuminate\Support\Facades\Cache;

/*
 * The published-page reader (§34) — the one place that decides what a
 * visitor may see. Every test here proves a specific safety guarantee: a
 * draft edit, a not-yet-due schedule, or an expired page must never reach
 * the public props, whatever the reader's cache is doing underneath.
 */

function cmsTestPage(string $slug = 'home'): Page
{
    return Page::query()->create(['slug' => $slug, 'page_type' => 'landing']);
}

function cmsTestHeroContent(string $enHeading = 'Welcome'): array
{
    return [
        'heading' => ['en' => $enHeading, 'bn' => 'স্বাগতম'],
        'primary_cta' => ['label' => ['en' => 'Register', 'bn' => 'নিবন্ধন'], 'href' => '/register'],
    ];
}

it('renders the published revision with locale-resolved content', function () {
    $page = cmsTestPage();

    app(SaveSectionDraft::class)->handle($page, 'hero', SectionKind::Hero, cmsTestHeroContent('Run your business'));
    app(PublishPage::class)->handle($page);

    $result = app(PublishedPageReader::class)->render('home', 'en');

    expect($result)->not->toBeNull()
        ->and($result['sections'])->toHaveCount(1)
        ->and($result['sections'][0]['content']['heading'])->toBe('Run your business');

    $bn = app(PublishedPageReader::class)->render('home', 'bn');
    expect($bn['sections'][0]['content']['heading'])->toBe('স্বাগতম');
});

it('never renders a page with no published revision', function () {
    $page = cmsTestPage();

    // A draft edit exists, but nothing has been published yet.
    app(SaveSectionDraft::class)->handle($page, 'hero', SectionKind::Hero, cmsTestHeroContent());

    expect(app(PublishedPageReader::class)->render('home', 'en'))->toBeNull();
});

it('never renders a page that does not exist', function () {
    expect(app(PublishedPageReader::class)->render('no-such-page', 'en'))->toBeNull();
});

it('does not render a revision scheduled for the future', function () {
    $page = cmsTestPage();
    app(SaveSectionDraft::class)->handle($page, 'hero', SectionKind::Hero, cmsTestHeroContent());

    app(PublishPage::class)->handle($page, publishAt: now()->addDay());

    expect(app(PublishedPageReader::class)->render('home', 'en'))->toBeNull();

    $page->refresh();
    expect($page->publication_state->value)->toBe('scheduled');
});

it('stops rendering a page past its scheduled unpublish time', function () {
    $page = cmsTestPage();
    app(SaveSectionDraft::class)->handle($page, 'hero', SectionKind::Hero, cmsTestHeroContent());
    app(PublishPage::class)->handle($page);

    expect(app(PublishedPageReader::class)->render('home', 'en'))->not->toBeNull();

    $page->update(['scheduled_unpublish_at' => now()->subMinute()]);

    expect(app(PublishedPageReader::class)->render('home', 'en'))->toBeNull();
});

it('never renders an explicitly unpublished page', function () {
    $page = cmsTestPage();
    app(SaveSectionDraft::class)->handle($page, 'hero', SectionKind::Hero, cmsTestHeroContent());
    app(PublishPage::class)->handle($page);

    $page->update(['publication_state' => 'unpublished']);

    expect(app(PublishedPageReader::class)->render('home', 'en'))->toBeNull();
});

it('excludes a disabled section and keeps sort order', function () {
    $page = cmsTestPage();
    $save = app(SaveSectionDraft::class);

    $save->handle($page, 'hero', SectionKind::Hero, cmsTestHeroContent(), sortOrder: 2);
    $save->handle($page, 'cta', SectionKind::Cta, [
        'heading' => ['en' => 'Get started', 'bn' => 'শুরু করুন'],
        'primary_cta' => ['label' => ['en' => 'Register', 'bn' => 'নিবন্ধন'], 'href' => '/register'],
    ], sortOrder: 1, isEnabled: false);
    $save->handle($page, 'faq', SectionKind::Faq, [
        'heading' => ['en' => 'FAQ', 'bn' => 'প্রশ্নোত্তর'],
        'items' => [['question' => ['en' => 'Q', 'bn' => 'প্র'], 'answer' => ['en' => 'A', 'bn' => 'উ']]],
    ], sortOrder: 0);

    app(PublishPage::class)->handle($page);

    $result = app(PublishedPageReader::class)->render('home', 'en');

    expect($result['sections'])->toHaveCount(2)
        ->and($result['sections'][0]['kind'])->toBe('faq')
        ->and($result['sections'][1]['kind'])->toBe('hero');
});

it('carries desktop/mobile visibility flags through to the public props', function () {
    $page = cmsTestPage();
    app(SaveSectionDraft::class)->handle($page, 'hero', SectionKind::Hero, cmsTestHeroContent());

    $section = $page->sections()->first();
    $section->update(['visible_on_mobile' => false]);

    app(PublishPage::class)->handle($page);

    $result = app(PublishedPageReader::class)->render('home', 'en');

    expect($result['sections'][0]['visible_on_desktop'])->toBeTrue()
        ->and($result['sections'][0]['visible_on_mobile'])->toBeFalse();
});

it('falls back to the global SEO default when a page has no title of its own', function () {
    SeoSetting::query()->create([
        'locale' => 'en',
        'default_title' => 'Feriwala — the real default',
        'organization_name' => 'Feriwala',
    ]);

    $page = cmsTestPage();
    app(SaveSectionDraft::class)->handle($page, 'hero', SectionKind::Hero, cmsTestHeroContent());
    app(PublishPage::class)->handle($page);

    $result = app(PublishedPageReader::class)->render('home', 'en');

    expect($result['seo']['title'])->toBe('Feriwala — the real default');
});

it('serves a freshly published revision immediately, never a stale cached one', function () {
    $page = cmsTestPage();
    app(SaveSectionDraft::class)->handle($page, 'hero', SectionKind::Hero, cmsTestHeroContent('First version'));
    app(PublishPage::class)->handle($page);

    $first = app(PublishedPageReader::class)->render('home', 'en');
    expect($first['sections'][0]['content']['heading'])->toBe('First version');

    // A second publish is a brand new revision id -- a brand new cache key --
    // so nothing needs to be invalidated by hand for the new content to show.
    app(SaveSectionDraft::class)->handle($page, 'hero', SectionKind::Hero, cmsTestHeroContent('Second version'));
    app(PublishPage::class)->handle($page);

    $second = app(PublishedPageReader::class)->render('home', 'en');
    expect($second['sections'][0]['content']['heading'])->toBe('Second version');
});

it('never writes a scheduled-but-not-yet-live revision into the public cache', function () {
    $page = cmsTestPage();
    app(SaveSectionDraft::class)->handle($page, 'hero', SectionKind::Hero, cmsTestHeroContent());
    $revision = app(PublishPage::class)->handle($page, publishAt: now()->addDay());

    app(PublishedPageReader::class)->render('home', 'en');

    expect(Cache::has("cms:page:{$revision->id}:en"))->toBeFalse();
});
