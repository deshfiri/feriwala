<?php

namespace App\Domain\Cms\Actions;

use App\Domain\Cms\Enums\PagePublicationState;
use App\Domain\Cms\Models\Page;
use App\Domain\Cms\Support\PublishedPageReader;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Takes a live page down without touching its revision history (§34.1). The
 * current revision's row is untouched and stays `published` — nothing here
 * supersedes it — so a later republish or restore still has it to point at.
 * {@see PublishedPageReader::isLiveNow()} refuses to
 * serve a page in this state regardless of what its revision says.
 */
class UnpublishPage
{
    public function handle(Page $page, ?User $actor = null): Page
    {
        return DB::transaction(function () use ($page, $actor) {
            $page = Page::query()->whereKey($page->id)->lockForUpdate()->firstOrFail();

            $page->transitionTo(PagePublicationState::Unpublished);

            $page->forceFill([
                'scheduled_publish_at' => null,
                'updated_by' => $actor?->id,
            ])->save();

            return $page;
        });
    }
}
