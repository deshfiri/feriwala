<?php

namespace App\Domain\Cms\Actions;

use App\Domain\Cms\Models\Page;
use App\Http\Controllers\Admin\CategoryController;
use Illuminate\Support\Facades\DB;

/**
 * Applies a page's whole new section order in one decision, the same shape
 * {@see CategoryController::reorder()} already
 * uses for the catalogue tree — the server receives the complete ordered
 * list of section keys rather than a single move, so a slow client can never
 * apply an order half a step behind another editor's.
 */
class ReorderPageSections
{
    /**
     * @param  array<int, string>  $sectionKeysInOrder
     */
    public function handle(Page $page, array $sectionKeysInOrder): void
    {
        DB::transaction(function () use ($page, $sectionKeysInOrder) {
            foreach ($sectionKeysInOrder as $index => $sectionKey) {
                $page->sections()
                    ->where('section_key', $sectionKey)
                    ->update(['sort_order' => $index]);
            }
        });
    }
}
