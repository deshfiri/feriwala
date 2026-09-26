<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Cms\Actions\SaveSectionDraft;
use App\Domain\Cms\Enums\SectionKind;
use App\Domain\Cms\Support\PublishedPageReader;
use Database\Seeders\RolesAndPermissionsSeeder;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * The admin page workspace (§4, §34, Stage 7) — publishing, scheduling and
 * unpublishing a page, and saving its draft-side SEO override. Every write
 * here only ever touches the live, editable side of a page; nothing is
 * visible to a guest until PublishPage freezes it into a new revision, which
 * PublishedPageReaderTest already covers in full.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->manager = testPlatformStaff(PlatformRole::ContentManager);
});

it('refuses the workspace to staff without a CMS permission', function () {
    $outsider = testPlatformStaff(PlatformRole::PackageManager);
    $page = cmsTestPage();

    $this->actingAs($outsider)->get(route('admin.cms.pages.edit', $page->public_id))
        ->assertForbidden();
});

it('shows a page draft with its sections and revision history', function () {
    $page = cmsTestPage();
    app(SaveSectionDraft::class)->handle($page, 'hero', SectionKind::Hero, cmsTestHeroContent());

    $this->actingAs($this->manager)->get(route('admin.cms.pages.edit', $page->public_id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/cms/pages/edit')
            ->where('page.publication_state.value', 'draft')
            ->has('sections', 1)
            ->where('sections.0.section_key', 'hero')
            ->has('revisions', 0));
});

it('publishes a page immediately and freezes its sections into a new revision', function () {
    $page = cmsTestPage();
    app(SaveSectionDraft::class)->handle($page, 'hero', SectionKind::Hero, cmsTestHeroContent());

    $this->actingAs($this->manager)
        ->post(route('admin.cms.pages.publish', $page->public_id))
        ->assertRedirect();

    $page->refresh();

    expect($page->publication_state->value)->toBe('published')
        ->and($page->current_published_revision_id)->not->toBeNull();
});

it('schedules a future publish without going live yet', function () {
    $page = cmsTestPage();
    app(SaveSectionDraft::class)->handle($page, 'hero', SectionKind::Hero, cmsTestHeroContent());

    $this->actingAs($this->manager)
        ->post(route('admin.cms.pages.publish', $page->public_id), [
            'publish_at' => now()->addDay()->toIso8601String(),
        ])
        ->assertRedirect();

    $page->refresh();

    expect($page->publication_state->value)->toBe('scheduled')
        ->and($page->current_published_revision_id)->toBeNull();
});

it('unpublishes a live page without touching its revision history', function () {
    $page = cmsTestPage();
    app(SaveSectionDraft::class)->handle($page, 'hero', SectionKind::Hero, cmsTestHeroContent());
    $this->actingAs($this->manager)->post(route('admin.cms.pages.publish', $page->public_id));

    $revisionId = $page->refresh()->current_published_revision_id;

    $this->actingAs($this->manager)
        ->post(route('admin.cms.pages.unpublish', $page->public_id))
        ->assertRedirect();

    $page->refresh();

    expect($page->publication_state->value)->toBe('unpublished')
        ->and($page->current_published_revision_id)->toBe($revisionId);
});

it('refuses to unpublish a page that was never published', function () {
    $page = cmsTestPage();

    $this->actingAs($this->manager)
        ->post(route('admin.cms.pages.unpublish', $page->public_id))
        ->assertSessionHasErrors('page');
});

it('saves an SEO override as a draft without publishing it', function () {
    $page = cmsTestPage();

    $this->actingAs($this->manager)
        ->patch(route('admin.cms.pages.meta.update', $page->public_id), [
            'title' => ['en' => 'Custom title', 'bn' => null],
            'description' => ['en' => null, 'bn' => null],
        ])
        ->assertRedirect();

    $page->refresh();

    expect($page->seo_overrides->getArrayCopy()['title']['en'])->toBe('Custom title')
        ->and(app(PublishedPageReader::class)->render($page->slug, 'en'))->toBeNull();
});
