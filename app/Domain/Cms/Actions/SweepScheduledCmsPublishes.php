<?php

namespace App\Domain\Cms\Actions;

use App\Domain\Cms\Enums\PagePublicationState;
use App\Domain\Cms\Models\Page;

/**
 * The scheduler's own entry point (§34.1, Stage 7 addendum): finds every
 * page whose scheduled publish is due and puts it live via
 * {@see PromoteScheduledRevision}, which does the actual, row-locked work
 * for one page at a time — so one page's failure (or a schedule an admin
 * cancels mid-sweep) never stops the rest from going out.
 *
 * Every minute, beside the app's other tight-window sweeps
 * (routes/console.php) — a landing page scheduled for a specific minute
 * should not wait up to an hour to actually appear.
 */
class SweepScheduledCmsPublishes
{
    public function __construct(protected PromoteScheduledRevision $promote) {}

    public function handle(): void
    {
        Page::query()
            ->where('publication_state', PagePublicationState::Scheduled)
            ->where('scheduled_publish_at', '<=', now())
            ->get()
            ->each(fn (Page $page) => $this->promote->handle($page));
    }
}
