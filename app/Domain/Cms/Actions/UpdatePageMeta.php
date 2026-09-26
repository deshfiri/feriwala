<?php

namespace App\Domain\Cms\Actions;

use App\Domain\Cms\Models\Page;
use App\Models\User;

/**
 * Saves a page's draft-side SEO override (§34, Stage 7). Draft-only, like a
 * section's content: it has no effect on what a visitor sees until the next
 * {@see PublishPage} call snapshots it into a new revision.
 */
class UpdatePageMeta
{
    /**
     * @param  array<string, mixed>  $seoOverrides
     */
    public function handle(Page $page, array $seoOverrides, ?User $actor = null): Page
    {
        $page->forceFill([
            'seo_overrides' => $seoOverrides,
            'updated_by' => $actor?->id,
        ])->save();

        return $page;
    }
}
