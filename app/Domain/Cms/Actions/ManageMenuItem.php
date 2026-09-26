<?php

namespace App\Domain\Cms\Actions;

use App\Domain\Cms\Models\Menu;
use App\Domain\Cms\Models\MenuItem;
use App\Domain\Cms\Rules\SafeMenuUrl;
use App\Http\Controllers\Admin\CategoryController;
use Illuminate\Support\Facades\DB;

/**
 * Creates, edits, removes and reorders a public-site menu's items (§4, §34).
 * `route_name`/`external_url` exclusivity and URL safety are already
 * enforced by the migration's CHECK constraint and
 * {@see SafeMenuUrl} at the validation boundary — this
 * class holds no rule of its own, only the write.
 */
class ManageMenuItem
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(Menu $menu, array $attributes): MenuItem
    {
        return $menu->allItems()->create($attributes);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(MenuItem $item, array $attributes): MenuItem
    {
        $item->update($attributes);

        return $item;
    }

    public function delete(MenuItem $item): void
    {
        $item->delete();
    }

    /**
     * Moving sends a menu's whole sibling order, never a single swap, the
     * same shape
     * {@see CategoryController::reorder()}
     * already uses for the catalogue tree.
     *
     * @param  array<int, string>  $orderedPublicIds
     */
    public function reorder(Menu $menu, ?MenuItem $parent, array $orderedPublicIds): void
    {
        DB::transaction(function () use ($menu, $parent, $orderedPublicIds) {
            foreach ($orderedPublicIds as $index => $publicId) {
                $menu->allItems()
                    ->where('parent_id', $parent?->id)
                    ->wherePublicId($publicId)
                    ->update(['sort_order' => $index]);
            }
        });
    }
}
