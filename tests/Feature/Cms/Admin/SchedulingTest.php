<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Cms\Actions\CancelScheduledPublish;
use App\Domain\Cms\Actions\PromoteScheduledRevision;
use App\Domain\Cms\Actions\PublishPage;
use App\Domain\Cms\Actions\SaveSectionDraft;
use App\Domain\Cms\Actions\SweepScheduledCmsPublishes;
use App\Domain\Cms\Enums\SectionKind;
use App\Domain\Cms\Exceptions\CmsSchedulingRefused;
use App\Domain\Cms\Models\Page;
use App\Domain\Cms\Models\PageRevision;
use Database\Seeders\RolesAndPermissionsSeeder;

/*
 * Scheduled publication: change a future date, cancel back to Draft, and
 * the scheduler sweep that puts a due page live (§34.1, Stage 7 addendum).
 * Every path here is proven never to touch the currently *live* revision
 * except the moment it is legitimately superseded by a newer one going
 * live.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->manager = testPlatformStaff(PlatformRole::ContentManager);
});

/**
 * Schedules a page for a moment just past, the way PromoteScheduledRevision
 * actually finds one to promote. PublishPage::handle() treats any
 * non-future date as an immediate publish by design, so a due-but-still-
 * scheduled revision can only be produced by scheduling for the near
 * future and then moving the page's own clock back, exactly as a real
 * schedule would look the instant after its moment passed.
 */
function schedulingTestMakeDue(Page $page): void
{
    app(PublishPage::class)->handle($page, publishAt: now()->addMinute());
    $page->update(['scheduled_publish_at' => now()->subMinute()]);
}

it('supersedes the previous pending schedule when publish is called again with a new date', function () {
    $page = cmsTestPage();
    app(SaveSectionDraft::class)->handle($page, 'hero', SectionKind::Hero, cmsTestHeroContent());

    $first = app(PublishPage::class)->handle($page, publishAt: now()->addDay());
    $second = app(PublishPage::class)->handle($page, publishAt: now()->addDays(2));

    expect($first->fresh()->publication_state->value)->toBe('superseded')
        ->and($second->fresh()->publication_state->value)->toBe('scheduled')
        ->and($page->fresh()->scheduled_publish_at->isSameDay(now()->addDays(2)))->toBeTrue();
});

it('cancels a scheduled publish back to draft, leaving no pending revision', function () {
    $page = cmsTestPage();
    app(SaveSectionDraft::class)->handle($page, 'hero', SectionKind::Hero, cmsTestHeroContent());
    $revision = app(PublishPage::class)->handle($page, publishAt: now()->addDay());

    app(CancelScheduledPublish::class)->handle($page);

    expect($page->fresh()->publication_state->value)->toBe('draft')
        ->and($page->fresh()->scheduled_publish_at)->toBeNull()
        ->and($revision->fresh()->publication_state->value)->toBe('superseded');
});

it('never touches the currently published revision when cancelling a later schedule', function () {
    $page = cmsTestPage();
    $save = app(SaveSectionDraft::class);

    $save->handle($page, 'hero', SectionKind::Hero, cmsTestHeroContent('Live version'));
    $liveRevision = app(PublishPage::class)->handle($page);

    $save->handle($page, 'hero', SectionKind::Hero, cmsTestHeroContent('Upcoming version'));
    app(PublishPage::class)->handle($page, publishAt: now()->addDay());

    app(CancelScheduledPublish::class)->handle($page);

    expect($page->fresh()->publication_state->value)->toBe('published')
        ->and($page->fresh()->current_published_revision_id)->toBe($liveRevision->id)
        ->and($liveRevision->fresh()->publication_state->value)->toBe('published');
});

it('refuses to cancel a page that has nothing scheduled', function () {
    $page = cmsTestPage();

    expect(fn () => app(CancelScheduledPublish::class)->handle($page))
        ->toThrow(CmsSchedulingRefused::class);
});

it('promotes a due scheduled revision to published', function () {
    $page = cmsTestPage();
    app(SaveSectionDraft::class)->handle($page, 'hero', SectionKind::Hero, cmsTestHeroContent());
    schedulingTestMakeDue($page);
    $revision = PageRevision::query()->where('cms_page_id', $page->id)->where('publication_state', 'scheduled')->sole();

    app(PromoteScheduledRevision::class)->handle($page);

    expect($page->fresh()->publication_state->value)->toBe('published')
        ->and($page->fresh()->current_published_revision_id)->toBe($revision->id)
        ->and($page->fresh()->scheduled_publish_at)->toBeNull()
        ->and($revision->fresh()->publication_state->value)->toBe('published')
        ->and($revision->fresh()->published_at)->not->toBeNull();
});

it('does not promote a revision whose scheduled time has not arrived yet', function () {
    $page = cmsTestPage();
    app(SaveSectionDraft::class)->handle($page, 'hero', SectionKind::Hero, cmsTestHeroContent());
    app(PublishPage::class)->handle($page, publishAt: now()->addDay());

    $result = app(PromoteScheduledRevision::class)->handle($page);

    expect($result)->toBeNull()
        ->and($page->fresh()->publication_state->value)->toBe('scheduled');
});

it('supersedes the previously live revision when the scheduler promotes a new one', function () {
    $page = cmsTestPage();
    $save = app(SaveSectionDraft::class);

    $save->handle($page, 'hero', SectionKind::Hero, cmsTestHeroContent('Live version'));
    $liveRevision = app(PublishPage::class)->handle($page);

    $save->handle($page, 'hero', SectionKind::Hero, cmsTestHeroContent('Due version'));
    schedulingTestMakeDue($page);

    app(PromoteScheduledRevision::class)->handle($page);

    expect($liveRevision->fresh()->publication_state->value)->toBe('superseded');
});

it('sweeps every due page across the whole site in one pass', function () {
    $pageA = cmsTestPage('page-a');
    $pageB = cmsTestPage('page-b');
    $save = app(SaveSectionDraft::class);

    $save->handle($pageA, 'hero', SectionKind::Hero, cmsTestHeroContent());
    $save->handle($pageB, 'hero', SectionKind::Hero, cmsTestHeroContent());

    schedulingTestMakeDue($pageA);
    app(PublishPage::class)->handle($pageB, publishAt: now()->addDay());

    app(SweepScheduledCmsPublishes::class)->handle();

    expect($pageA->fresh()->publication_state->value)->toBe('published')
        ->and($pageB->fresh()->publication_state->value)->toBe('scheduled');
});

it('is a safe no-op for the scheduler to run twice on the same due page', function () {
    $page = cmsTestPage();
    app(SaveSectionDraft::class)->handle($page, 'hero', SectionKind::Hero, cmsTestHeroContent());
    schedulingTestMakeDue($page);

    $first = app(PromoteScheduledRevision::class)->handle($page);
    $second = app(PromoteScheduledRevision::class)->handle($page->fresh());

    expect($first)->not->toBeNull()
        ->and($second)->toBeNull()
        ->and(PageRevision::query()->where('cms_page_id', $page->id)->where('publication_state', 'published')->count())->toBe(1);
});

it('refuses to cancel a schedule the scheduler already promoted', function () {
    $page = cmsTestPage();
    app(SaveSectionDraft::class)->handle($page, 'hero', SectionKind::Hero, cmsTestHeroContent());
    schedulingTestMakeDue($page);

    app(PromoteScheduledRevision::class)->handle($page);

    expect(fn () => app(CancelScheduledPublish::class)->handle($page->fresh()))
        ->toThrow(CmsSchedulingRefused::class);
});

it('leaves a page cancelled rather than promoting it once the scheduler catches up', function () {
    $page = cmsTestPage();
    app(SaveSectionDraft::class)->handle($page, 'hero', SectionKind::Hero, cmsTestHeroContent());
    schedulingTestMakeDue($page);

    app(CancelScheduledPublish::class)->handle($page->fresh());

    // The scheduler tick that would have promoted it runs after the
    // cancellation -- it must find nothing left to do.
    $result = app(PromoteScheduledRevision::class)->handle($page->fresh());

    expect($result)->toBeNull()
        ->and($page->fresh()->publication_state->value)->toBe('draft');
});

it('records an audit entry when a scheduled publish is cancelled', function () {
    $page = cmsTestPage();
    app(SaveSectionDraft::class)->handle($page, 'hero', SectionKind::Hero, cmsTestHeroContent());
    app(PublishPage::class)->handle($page, publishAt: now()->addDay());

    app(CancelScheduledPublish::class)->handle($page, reason: 'Marketing asked to hold the launch.');

    $entry = AuditLog::query()->latest('id')->first();

    expect($entry)->not->toBeNull()
        ->and($entry->auditable_type)->toBe(Page::class)
        ->and($entry->reason)->toBe('Marketing asked to hold the launch.');
});
