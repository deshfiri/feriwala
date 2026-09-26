<?php

namespace App\Domain\Cms\Actions;

use App\Domain\Cms\Enums\PagePublicationState;
use App\Domain\Cms\Enums\RevisionPublicationState;
use App\Domain\Cms\Models\Media;
use App\Domain\Cms\Models\Page;
use App\Domain\Cms\Models\PageRevision;
use App\Domain\Cms\Support\MediaSnapshotResolver;
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
    public function __construct(protected MediaSnapshotResolver $mediaResolver) {}

    public function handle(
        Page $page,
        ?User $actor = null,
        ?string $reason = null,
        ?CarbonInterface $publishAt = null,
        ?int $restoredFromId = null,
    ): PageRevision {
        return DB::transaction(function () use ($page, $actor, $reason, $publishAt, $restoredFromId) {
            $page = Page::query()->whereKey($page->id)->lockForUpdate()->firstOrFail();

            $publishAt ??= now();
            $isImmediate = $publishAt->lessThanOrEqualTo(now());

            // Whatever this page was waiting for is superseded before the
            // new revision is written — publishing again (immediately, or
            // on a new schedule) always replaces a still-pending schedule
            // rather than leaving it to accumulate, and this must happen
            // first: the new revision below is itself briefly `scheduled`
            // in the non-immediate branch, and would supersede itself if
            // this ran after it existed.
            $this->supersedePendingScheduled($page);

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
                'restored_from_id' => $restoredFromId,
            ]);

            if ($isImmediate) {
                if ($previousRevisionId !== null) {
                    $this->supersede(PageRevision::query()->whereKey($previousRevisionId)->firstOrFail());
                }

                // Publishing again while already published is a no-op move,
                // not a transition — transitionTo() refuses a state to
                // itself, so it is only asked when the state is actually
                // changing (§ statuses move through transitionTo()).
                if ($page->publication_state !== PagePublicationState::Published) {
                    $page->transitionTo(PagePublicationState::Published);
                }

                $page->forceFill([
                    'current_published_revision_id' => $revision->id,
                    'scheduled_publish_at' => null,
                    'updated_by' => $actor?->id,
                ])->save();
            } else {
                if ($page->publication_state !== PagePublicationState::Scheduled) {
                    $page->transitionTo(PagePublicationState::Scheduled);
                }

                $page->forceFill([
                    'scheduled_publish_at' => $publishAt,
                    'updated_by' => $actor?->id,
                ])->save();
            }

            return $revision;
        });
    }

    /**
     * Any revision still waiting for its moment never gets a second chance
     * to reach it once this page moves on — publishing again (immediately
     * or on a new schedule) always supersedes whatever was pending, so at
     * most one `scheduled` revision ever exists per page at a time.
     */
    protected function supersedePendingScheduled(Page $page): void
    {
        PageRevision::query()
            ->where('cms_page_id', $page->id)
            ->where('publication_state', RevisionPublicationState::Scheduled)
            ->get()
            ->each(fn (PageRevision $pending) => $this->supersede($pending));
    }

    protected function supersede(PageRevision $revision): void
    {
        $revision->transitionTo(RevisionPublicationState::Superseded)->save();
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
        // Every media reference a section's content holds is frozen into
        // immutable metadata here — url, dimensions, alt text — so the
        // revision keeps rendering identically even if the Media row is
        // later edited or its underlying file replaced (Stage 7 addendum).
        $sections = $page->sections()->get()->map(fn ($section) => [
            'section_key' => $section->section_key,
            'kind' => $section->kind->value,
            'sort_order' => $section->sort_order,
            'is_enabled' => $section->is_enabled,
            'visible_on_desktop' => $section->visible_on_desktop,
            'visible_on_mobile' => $section->visible_on_mobile,
            'variant' => $section->variant,
            'content' => $this->mediaResolver->resolve($section->content->getArrayCopy()),
        ])->all();

        $overrides = $page->seo_overrides?->getArrayCopy() ?? [];
        $ogImageId = $overrides['og_image_id'] ?? null;

        return [
            'sections' => $sections,
            // Null fields fall through to PublishedPageReader::seo()'s
            // global cms_seo_settings default rather than a placeholder
            // value that would outrank it.
            'seo' => [
                'title' => $overrides['title'] ?? null,
                'description' => $overrides['description'] ?? null,
                'canonical_url' => $overrides['canonical_url'] ?? null,
                // og_image_id survives alongside the resolved URL so
                // DeleteCmsMedia can still find the reference by id even
                // though the reader only ever needs the URL.
                'og_image_id' => $ogImageId,
                'og_image_url' => $ogImageId === null
                    ? null
                    : Media::query()->wherePublicId($ogImageId)->first()?->url(),
                'robots' => $overrides['robots'] ?? 'index, follow',
            ],
        ];
    }
}
