<?php

namespace App\Http\Controllers\Admin\Cms;

use App\Domain\Cms\Actions\ReorderPageSections;
use App\Domain\Cms\Actions\SaveSectionDraft;
use App\Domain\Cms\Enums\SectionKind;
use App\Domain\Cms\Models\Page;
use App\Domain\Cms\Models\PageSection;
use App\Http\Controllers\Controller;
use App\Http\Requests\Cms\SaveSectionRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * A page's live, editable sections (§4, §34). Every write goes through
 * {@see SaveSectionDraft}, which is the one place content is validated and
 * sanitized against its kind's schema — nothing here touches `content`
 * directly.
 */
class PageSectionController extends Controller
{
    public function store(SaveSectionRequest $request, Page $page, SaveSectionDraft $save): RedirectResponse
    {
        Gate::authorize('update', $page);

        $validated = $request->validated();

        $save->handle(
            $page,
            $validated['section_key'],
            SectionKind::from($validated['kind']),
            $validated['content'],
            sortOrder: $page->sections()->max('sort_order') + 1,
            isEnabled: $validated['is_enabled'] ?? true,
            visibleOnDesktop: $validated['visible_on_desktop'] ?? true,
            visibleOnMobile: $validated['visible_on_mobile'] ?? true,
            variant: $validated['variant'] ?? null,
        );

        return back()->with('success', __('Section added as a draft. Publish the page to make it live.'));
    }

    public function update(
        SaveSectionRequest $request,
        Page $page,
        string $section,
        SaveSectionDraft $save,
    ): RedirectResponse {
        Gate::authorize('update', $page);

        $section = $this->sectionFor($page, $section);
        $validated = $request->validated();

        $save->handle(
            $page,
            $section->section_key,
            SectionKind::from($validated['kind']),
            $validated['content'],
            sortOrder: $section->sort_order,
            isEnabled: $validated['is_enabled'] ?? true,
            visibleOnDesktop: $validated['visible_on_desktop'] ?? true,
            visibleOnMobile: $validated['visible_on_mobile'] ?? true,
            variant: $validated['variant'] ?? null,
        );

        return back()->with('success', __('Section saved as a draft. Publish the page to make it live.'));
    }

    public function reorder(Request $request, Page $page, ReorderPageSections $reorder): RedirectResponse
    {
        Gate::authorize('update', $page);

        $validated = $request->validate([
            'order' => ['required', 'array', 'min:1'],
            'order.*' => ['required', 'string', 'distinct'],
        ]);

        $reorder->handle($page, $validated['order']);

        return back()->with('success', __('Section order saved as a draft. Publish the page to make it live.'));
    }

    public function destroy(Page $page, string $section): RedirectResponse
    {
        Gate::authorize('update', $page);

        $this->sectionFor($page, $section)->delete();

        return back()->with('success', __('Section removed from the draft. Publish the page to make it live.'));
    }

    /**
     * Resolved through the page's own relation rather than a bare implicit
     * binding, so a section public id can never be read against a page it
     * does not belong to.
     */
    protected function sectionFor(Page $page, string $section): PageSection
    {
        return $page->sections()->wherePublicId($section)->firstOrFail();
    }
}
