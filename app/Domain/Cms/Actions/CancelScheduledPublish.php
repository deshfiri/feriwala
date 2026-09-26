<?php

namespace App\Domain\Cms\Actions;

use App\Domain\Access\Enums\PermissionAction;
use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Cms\Enums\PagePublicationState;
use App\Domain\Cms\Enums\RevisionPublicationState;
use App\Domain\Cms\Exceptions\CmsSchedulingRefused;
use App\Domain\Cms\Models\Page;
use App\Domain\Cms\Models\PageRevision;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Cancels a page's pending scheduled publish (§34.1, Stage 7 addendum). The
 * currently *published* revision, if any, is left exactly as it is — this
 * only ever touches a revision that was still waiting for its moment,
 * never one already live, so cancelling can never accidentally take a live
 * page down (that is {@see UnpublishPage}'s job, and a different act
 * entirely). The page itself lands back wherever it stood before the
 * schedule was made: `Published` if a revision was already live (this was
 * a scheduled *update*), or `Draft` if this page had never gone live at
 * all (this was its first scheduled publish).
 *
 * Row-locked and re-checked against the page's live state before doing
 * anything, so calling this twice — or racing
 * {@see PromoteScheduledRevision} — is safe: the second caller finds
 * nothing left to cancel and is refused the same way a page that was
 * never scheduled would be.
 */
class CancelScheduledPublish
{
    public function __construct(protected RecordAuditLog $audit) {}

    public function handle(Page $page, ?User $actor = null, ?string $reason = null): Page
    {
        return DB::transaction(function () use ($page, $actor, $reason) {
            $page = Page::query()->whereKey($page->id)->lockForUpdate()->firstOrFail();

            if ($page->publication_state !== PagePublicationState::Scheduled) {
                throw CmsSchedulingRefused::notCurrentlyScheduled($page);
            }

            $previousScheduledFor = $page->scheduled_publish_at?->toIso8601String();

            PageRevision::query()
                ->where('cms_page_id', $page->id)
                ->where('publication_state', RevisionPublicationState::Scheduled)
                ->get()
                ->each(fn (PageRevision $pending) => $pending
                    ->transitionTo(RevisionPublicationState::Superseded)
                    ->save());

            $target = $page->current_published_revision_id !== null
                ? PagePublicationState::Published
                : PagePublicationState::Draft;

            $page->transitionTo($target);
            $page->forceFill([
                'scheduled_publish_at' => null,
                'updated_by' => $actor?->id,
            ])->save();

            $this->audit->handle(new AuditEntry(
                action: PermissionModule::Cms->value.'.'.PermissionAction::Unpublish->value,
                actorId: $actor?->id,
                actorType: $actor === null ? 'system' : 'user',
                auditableType: Page::class,
                auditableId: $page->id,
                before: ['publication_state' => 'scheduled', 'scheduled_publish_at' => $previousScheduledFor],
                after: ['publication_state' => $target->value, 'scheduled_publish_at' => null],
                reason: $reason,
                module: PermissionModule::Cms->value,
            ));

            return $page;
        });
    }
}
