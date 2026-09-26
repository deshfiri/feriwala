<?php

namespace App\Domain\Cms\Actions;

use App\Domain\Cms\Enums\SectionKind;
use App\Domain\Cms\Models\Page;
use App\Domain\Cms\Models\PageSection;
use App\Domain\Cms\Support\SectionContentValidator;

/**
 * Creates or updates a page's live, editable section (§34). The one place
 * `content` is written — every write goes through
 * {@see SectionContentValidator} first, so a section can never hold JSON
 * that skipped the allow-list/sanitizer, whatever future admin endpoint
 * calls this.
 */
class SaveSectionDraft
{
    public function __construct(protected SectionContentValidator $validator) {}

    /**
     * @param  array<string, mixed>  $content
     */
    public function handle(
        Page $page,
        string $sectionKey,
        SectionKind $kind,
        array $content,
        int $sortOrder = 0,
        bool $isEnabled = true,
        bool $visibleOnDesktop = true,
        bool $visibleOnMobile = true,
        ?string $variant = null,
    ): PageSection {
        $validated = $this->validator->validate($kind, $content);

        return PageSection::query()->updateOrCreate(
            ['cms_page_id' => $page->id, 'section_key' => $sectionKey],
            [
                'kind' => $kind,
                'sort_order' => $sortOrder,
                'is_enabled' => $isEnabled,
                'visible_on_desktop' => $visibleOnDesktop,
                'visible_on_mobile' => $visibleOnMobile,
                'variant' => $variant,
                'content' => $validated,
            ],
        );
    }
}
