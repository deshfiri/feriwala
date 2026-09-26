<?php

namespace App\Domain\Cms\Actions;

use App\Console\Commands\PublishScheduledCmsPages;
use App\Domain\Cms\Enums\PagePublicationState;
use App\Domain\Cms\Enums\RevisionPublicationState;
use App\Domain\Cms\Models\Page;
use App\Domain\Cms\Models\PageRevision;
use Illuminate\Support\Facades\DB;

/**
 * Puts a scheduled revision live the moment its time arrives (§34.1, Stage
 * 7 addendum). {@see PublishScheduledCmsPages} calls
 * this for every page whose `scheduled_publish_at` has passed; nothing
 * about the revision's own content is re-snapshotted here — it was already
 * frozen, exactly as it should go live, the moment it was scheduled.
 *
 * Row-locked the same way {@see PublishPage} and
 * {@see CancelScheduledPublish} are, so a scheduler tick and an admin
 * cancelling the same schedule at the same instant can never both
 * succeed: whichever acquires the lock first decides the page's fate, and
 * the loser's own guard (page no longer `scheduled`, or no pending
 * revision left) makes it a safe no-op rather than a race.
 */
class PromoteScheduledRevision
{
    /**
     * Null when there was nothing to promote (already handled, cancelled,
     * or not yet due) — never an exception, since the scheduler calls this
     * unconditionally for every page it finds and a page slipping out of
     * eligibility between the query and the lock is an ordinary race, not
     * a failure.
     */
    public function handle(Page $page): ?PageRevision
    {
        return DB::transaction(function () use ($page) {
            $page = Page::query()->whereKey($page->id)->lockForUpdate()->firstOrFail();

            if ($page->publication_state !== PagePublicationState::Scheduled) {
                return null;
            }

            if ($page->scheduled_publish_at === null || $page->scheduled_publish_at->isFuture()) {
                return null;
            }

            $revision = PageRevision::query()
                ->where('cms_page_id', $page->id)
                ->where('publication_state', RevisionPublicationState::Scheduled)
                ->orderByDesc('version')
                ->first();

            if ($revision === null) {
                return null;
            }

            $previousRevisionId = $page->current_published_revision_id;

            if ($previousRevisionId !== null) {
                PageRevision::query()->whereKey($previousRevisionId)->firstOrFail()
                    ->transitionTo(RevisionPublicationState::Superseded)
                    ->save();
            }

            $revision->transitionTo(RevisionPublicationState::Published);
            $revision->forceFill(['published_at' => now()])->save();

            $page->transitionTo(PagePublicationState::Published);
            $page->forceFill([
                'current_published_revision_id' => $revision->id,
                'scheduled_publish_at' => null,
            ])->save();

            return $revision;
        });
    }
}
