<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Cms\Actions\PublishPage;
use App\Domain\Cms\Actions\SaveSectionDraft;
use App\Domain\Cms\Enums\SectionKind;
use Database\Seeders\RolesAndPermissionsSeeder;

/*
 * Rolling a page back to an earlier published revision (§34.1, Stage 7). A
 * restore is never an edit of the old row — PageRevision stays append-only
 * throughout — it is a brand new revision, linked back by restored_from_id.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->manager = testPlatformStaff(PlatformRole::ContentManager);
});

it('restores an earlier revision as a new one and publishes it', function () {
    $page = cmsTestPage();
    $save = app(SaveSectionDraft::class);

    $save->handle($page, 'hero', SectionKind::Hero, cmsTestHeroContent('First version'));
    $firstRevision = app(PublishPage::class)->handle($page);

    $save->handle($page, 'hero', SectionKind::Hero, cmsTestHeroContent('Second version'));
    app(PublishPage::class)->handle($page);

    $this->actingAs($this->manager)
        ->post(route('admin.cms.pages.revisions.restore', [$page->public_id, $firstRevision->public_id]))
        ->assertRedirect();

    $page->refresh();
    $latest = $page->revisions()->orderByDesc('version')->firstOrFail();

    expect($page->sections()->firstOrFail()->content->getArrayCopy()['heading']['en'])->toBe('First version')
        ->and($latest->restored_from_id)->toBe($firstRevision->id)
        ->and($latest->version)->toBe(3)
        ->and($firstRevision->fresh()->content->getArrayCopy()['sections'][0]['content']['heading']['en'])
        ->toBe('First version');
});

it('cannot restore a revision belonging to a different page', function () {
    $pageA = cmsTestPage('page-a');
    $pageB = cmsTestPage('page-b');
    app(SaveSectionDraft::class)->handle($pageA, 'hero', SectionKind::Hero, cmsTestHeroContent());
    $revision = app(PublishPage::class)->handle($pageA);

    $this->actingAs($this->manager)
        ->post(route('admin.cms.pages.revisions.restore', [$pageB->public_id, $revision->public_id]))
        ->assertNotFound();
});
