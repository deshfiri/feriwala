<?php

namespace App\Domain\Cms\Actions;

use App\Domain\Cms\Enums\PagePublicationState;
use App\Domain\Cms\Enums\RevisionPublicationState;
use App\Domain\Cms\Models\Page;
use App\Domain\Cms\Models\PageRevision;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Freezes a page's current live sections into a new, immutable revision and
 * makes it the one the public reader serves (§34.1). The revision this
 * replaces is marked `superseded`, never edited or deleted — its row stays
 * exactly as it was published, so a restore can always point back at it.
 *
 * Everything happens in one transaction: superseding the old revision,
 * writing the new one, and repointing the page's
 * `current_published_revision_id` all succeed together or not at all, so a
 * reader can never observe a page with no live revision for even a moment.
 */
class PublishPage
{
    public function handle(Page $page, ?User $actor = null, ?string $reason = null, ?CarbonInterface $publishAt = null): PageRevision
    {
        return DB::transaction(function () use ($page, $actor, $reason, $publishAt) {
            $page = Page::query()->whereKey($page->id)->lockForUpdate()->firstOrFail();

            $publishAt ??= now();
            $isImmediate = $publishAt->lessThanOrEqualTo(now());

            $nextVersion = 1 + (int) PageRevision::query()->where('cms_page_id', $page->id)->max('version');

            $previousRevisionId = $page->current_published_revision_id;

            $revision = PageRevision::query()->create([
                'cms_page_id' => $page->id,
                'version' => $nextVersion,
                'content' => $this->snapshot($page),
                'publication_state' => $isImmediate ? RevisionPublicationState::Published : RevisionPublicationState::Scheduled,
                'published_at' => $isImmediate ? $publishAt : null,
                'created_by' => $actor?->id,
                'reason' => $reason,
            ]);

            if ($isImmediate) {
                if ($previousRevisionId !== null) {
                    PageRevision::query()->whereKey($previousRevisionId)
                        ->update(['publication_state' => RevisionPublicationState::Superseded]);
                }

                $page->forceFill([
                    'current_published_revision_id' => $revision->id,
                    'publication_state' => PagePublicationState::Published,
                    'updated_by' => $actor?->id,
                ])->save();
            } else {
                $page->forceFill([
                    'publication_state' => PagePublicationState::Scheduled,
                    'scheduled_publish_at' => $publishAt,
                    'updated_by' => $actor?->id,
                ])->save();
            }

            return $revision;
        });
    }

    /**
     * The full export a publish takes: every section in order (enabled or
     * not — the reader decides visibility, not this snapshot), the page's
     * own SEO fields, and nothing from any other page's data.
     *
     * @return array<string, mixed>
     */
    protected function snapshot(Page $page): array
    {
        $sections = $page->sections()->get()->map(fn ($section) => [
            'section_key' => $section->section_key,
            'kind' => $section->kind->value,
            'sort_order' => $section->sort_order,
            'is_enabled' => $section->is_enabled,
            'visible_on_desktop' => $section->visible_on_desktop,
            'visible_on_mobile' => $section->visible_on_mobile,
            'variant' => $section->variant,
            'content' => $section->content->getArrayCopy(),
        ])->all();

        return [
            'sections' => $sections,
            // No per-page title/description yet: null here, deliberately,
            // so the reader's fallback chain reaches the real
            // cms_seo_settings default rather than a placeholder value that
            // would outrank it. Stage 7's editing UI is what gives a page
            // its own override, once it exists.
            'seo' => [
                'title' => null,
                'description' => null,
                'canonical_url' => null,
                'og_image_url' => null,
                'robots' => 'index, follow',
            ],
        ];
    }
}
