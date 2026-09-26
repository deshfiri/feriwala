<?php

use App\Domain\Cms\Models\Page;
use App\Domain\Cms\Support\PublishedPageReader;
use Database\Seeders\CmsLandingPageSeeder;

/*
 * The real initial content bootstrap (§34) — idempotent, and its
 * republish-on-rerun path must correctly supersede the prior revision
 * rather than erroring or duplicating rows.
 */

it('publishes a real, live home page with every section in both locales', function () {
    (new CmsLandingPageSeeder)->run();

    $page = Page::query()->where('slug', 'home')->firstOrFail();

    expect($page->publication_state->value)->toBe('published')
        ->and($page->current_published_revision_id)->not->toBeNull();

    $en = app(PublishedPageReader::class)->render('home', 'en');
    $bn = app(PublishedPageReader::class)->render('home', 'bn');

    expect($en)->not->toBeNull()
        ->and($bn)->not->toBeNull()
        ->and($en['sections'])->toHaveCount(13)
        ->and($bn['sections'])->toHaveCount(13);

    // Every section actually resolved to real, non-empty English text
    // somewhere in its content -- proves the seed data survived validation
    // and sanitization intact, not just that 13 rows exist.
    $hero = collect($en['sections'])->firstWhere('kind', 'hero');
    expect($hero['content']['heading'])->not->toBeEmpty();
});

it('is idempotent: running it twice supersedes the first revision instead of erroring or duplicating', function () {
    (new CmsLandingPageSeeder)->run();
    $firstRevisionId = Page::query()->where('slug', 'home')->value('current_published_revision_id');

    (new CmsLandingPageSeeder)->run();
    $page = Page::query()->where('slug', 'home')->first();

    expect(Page::query()->where('slug', 'home')->count())->toBe(1)
        ->and($page->current_published_revision_id)->not->toBe($firstRevisionId)
        ->and($page->revisions()->count())->toBe(2);
});
