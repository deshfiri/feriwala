<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Cms\Actions\SaveSectionDraft;
use App\Domain\Cms\Enums\SectionKind;
use Database\Seeders\RolesAndPermissionsSeeder;

/*
 * A page's live, editable sections (§4, §34, Stage 7) — every write here is
 * a draft: it never reaches PublishedPageReaderTest's world until a publish
 * freezes it into a revision.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->manager = testPlatformStaff(PlatformRole::ContentManager);
});

it('adds a new section as a draft, appended after the existing ones', function () {
    $page = cmsTestPage();
    app(SaveSectionDraft::class)->handle($page, 'hero', SectionKind::Hero, cmsTestHeroContent());

    $this->actingAs($this->manager)
        ->post(route('admin.cms.pages.sections.store', $page->public_id), [
            'section_key' => 'cta-2',
            'kind' => 'cta',
            'content' => [
                'heading' => ['en' => 'Ready?', 'bn' => 'প্রস্তুত?'],
                'primary_cta' => ['label' => ['en' => 'Go', 'bn' => 'যান'], 'href' => '/register'],
            ],
        ])
        ->assertRedirect();

    $section = $page->sections()->where('section_key', 'cta-2')->firstOrFail();

    expect($section->sort_order)->toBe(1);
});

it('refuses a duplicate section key on the same page', function () {
    $page = cmsTestPage();
    app(SaveSectionDraft::class)->handle($page, 'hero', SectionKind::Hero, cmsTestHeroContent());

    $this->actingAs($this->manager)
        ->post(route('admin.cms.pages.sections.store', $page->public_id), [
            'section_key' => 'hero',
            'kind' => 'hero',
            'content' => cmsTestHeroContent(),
        ])
        ->assertSessionHasErrors('section_key');
});

it('saves an edit to an existing section as a draft', function () {
    $page = cmsTestPage();
    app(SaveSectionDraft::class)->handle($page, 'hero', SectionKind::Hero, cmsTestHeroContent('Old heading'));
    $section = $page->sections()->firstOrFail();

    $this->actingAs($this->manager)
        ->patch(route('admin.cms.pages.sections.update', [$page->public_id, $section->public_id]), [
            'kind' => 'hero',
            'is_enabled' => false,
            'content' => cmsTestHeroContent('New heading'),
        ])
        ->assertRedirect();

    $section->refresh();

    expect($section->content->getArrayCopy()['heading']['en'])->toBe('New heading')
        ->and($section->is_enabled)->toBeFalse();
});

it('rejects unsafe content through the same validator every save uses', function () {
    $page = cmsTestPage();
    app(SaveSectionDraft::class)->handle($page, 'hero', SectionKind::Hero, cmsTestHeroContent());
    $section = $page->sections()->firstOrFail();

    $this->actingAs($this->manager)
        ->patch(route('admin.cms.pages.sections.update', [$page->public_id, $section->public_id]), [
            'kind' => 'hero',
            'content' => [
                'heading' => ['en' => 'Hi', 'bn' => null],
                'primary_cta' => ['label' => ['en' => 'Go', 'bn' => null], 'href' => 'javascript:alert(1)'],
            ],
        ])
        ->assertSessionHasErrors();
});

it('cannot reach a section belonging to a different page', function () {
    $pageA = cmsTestPage('page-a');
    $pageB = cmsTestPage('page-b');
    app(SaveSectionDraft::class)->handle($pageA, 'hero', SectionKind::Hero, cmsTestHeroContent());
    $section = $pageA->sections()->firstOrFail();

    $this->actingAs($this->manager)
        ->patch(route('admin.cms.pages.sections.update', [$pageB->public_id, $section->public_id]), [
            'kind' => 'hero',
            'content' => cmsTestHeroContent(),
        ])
        ->assertNotFound();
});

it('reorders sections in one request', function () {
    $page = cmsTestPage();
    $save = app(SaveSectionDraft::class);
    $save->handle($page, 'hero', SectionKind::Hero, cmsTestHeroContent(), sortOrder: 0);
    $save->handle($page, 'faq', SectionKind::Faq, [
        'heading' => ['en' => 'FAQ', 'bn' => 'প্রশ্নোত্তর'],
        'items' => [['question' => ['en' => 'Q', 'bn' => 'প্র'], 'answer' => ['en' => 'A', 'bn' => 'উ']]],
    ], sortOrder: 1);

    $this->actingAs($this->manager)
        ->post(route('admin.cms.pages.sections.reorder', $page->public_id), [
            'order' => ['faq', 'hero'],
        ])
        ->assertRedirect();

    expect($page->sections()->orderBy('sort_order')->pluck('section_key')->all())
        ->toBe(['faq', 'hero']);
});

it('removes a section from the draft', function () {
    $page = cmsTestPage();
    app(SaveSectionDraft::class)->handle($page, 'hero', SectionKind::Hero, cmsTestHeroContent());
    $section = $page->sections()->firstOrFail();

    $this->actingAs($this->manager)
        ->delete(route('admin.cms.pages.sections.destroy', [$page->public_id, $section->public_id]))
        ->assertRedirect();

    expect($page->sections()->count())->toBe(0);
});
