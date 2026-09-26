<?php

namespace App\Domain\Cms\Actions;

use App\Domain\Cms\Enums\SectionKind;
use App\Domain\Cms\Models\Page;
use App\Domain\Cms\Models\PageRevision;
use App\Domain\Cms\Models\PageSection;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Rolls a page back to an earlier published revision (§34.1's "revision
 * history and rollback"). A restore never revives the old row in place —
 * {@see PageRevision} is append-only — it replaces the page's live,
 * editable sections with that revision's frozen content and immediately
 * publishes them as a brand new revision, linked back via
 * `restored_from_id` so the history stays honest about what happened.
 */
class RestoreRevision
{
    public function __construct(protected PublishPage $publish) {}

    public function handle(PageRevision $revision, ?User $actor = null): PageRevision
    {
        return DB::transaction(function () use ($revision, $actor) {
            $page = Page::query()->whereKey($revision->cms_page_id)->lockForUpdate()->firstOrFail();

            /** @var array<int, array<string, mixed>> $sections */
            $sections = $revision->content->getArrayCopy()['sections'] ?? [];
            $keys = array_column($sections, 'section_key');

            $page->sections()->whereNotIn('section_key', $keys)->delete();

            foreach ($sections as $section) {
                PageSection::query()->updateOrCreate(
                    ['cms_page_id' => $page->id, 'section_key' => $section['section_key']],
                    [
                        'kind' => SectionKind::from($section['kind']),
                        'sort_order' => $section['sort_order'] ?? 0,
                        'is_enabled' => $section['is_enabled'] ?? true,
                        'visible_on_desktop' => $section['visible_on_desktop'] ?? true,
                        'visible_on_mobile' => $section['visible_on_mobile'] ?? true,
                        'variant' => $section['variant'] ?? null,
                        'content' => $section['content'] ?? [],
                    ],
                );
            }

            return $this->publish->handle(
                $page,
                $actor,
                "Restored from version {$revision->version}",
                null,
                $revision->id,
            );
        });
    }
}
