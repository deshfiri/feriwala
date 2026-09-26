<?php

namespace App\Http\Controllers\Admin\Cms;

use App\Domain\Cms\Actions\ManageMenuItem;
use App\Domain\Cms\Enums\MenuLocation;
use App\Domain\Cms\Models\Menu;
use App\Domain\Cms\Models\MenuItem;
use App\Http\Controllers\Controller;
use App\Http\Requests\Cms\SaveMenuItemRequest;
use Database\Seeders\CmsLandingPageSeeder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Items within one of the three public-site menus (§4, §34). Addressed
 * through the menu's own location — `header`, `footer` or `legal` — rather
 * than an id, so the URL matches how {@see MenuController::index()} already
 * groups them.
 */
class MenuItemController extends Controller
{
    public function store(SaveMenuItemRequest $request, string $location, ManageMenuItem $manage): RedirectResponse
    {
        Gate::authorize('create', MenuItem::class);

        $menu = $this->menuFor($location);
        $validated = $request->validated();

        $manage->create($menu, [
            ...$validated,
            'parent_id' => $this->parentIdFor($menu, $validated['parent_id'] ?? null),
            'sort_order' => $menu->allItems()->count(),
        ]);

        return back()->with('success', __('Menu item added.'));
    }

    public function update(
        SaveMenuItemRequest $request,
        string $location,
        string $item,
        ManageMenuItem $manage,
    ): RedirectResponse {
        Gate::authorize('update', MenuItem::class);

        $menu = $this->menuFor($location);
        $validated = $request->validated();

        $manage->update($this->itemFor($menu, $item), [
            ...$validated,
            'parent_id' => $this->parentIdFor($menu, $validated['parent_id'] ?? null),
        ]);

        return back()->with('success', __('Menu item saved.'));
    }

    public function destroy(string $location, string $item, ManageMenuItem $manage): RedirectResponse
    {
        Gate::authorize('delete', MenuItem::class);

        $manage->delete($this->itemFor($this->menuFor($location), $item));

        return back()->with('success', __('Menu item removed.'));
    }

    public function reorder(Request $request, string $location, ManageMenuItem $manage): RedirectResponse
    {
        Gate::authorize('update', MenuItem::class);

        $menu = $this->menuFor($location);

        $validated = $request->validate([
            'parent_id' => ['nullable', 'string'],
            'order' => ['required', 'array', 'min:1'],
            'order.*' => ['required', 'string', 'distinct'],
        ]);

        $parent = filled($validated['parent_id'] ?? null) ? $this->itemFor($menu, $validated['parent_id']) : null;

        $manage->reorder($menu, $parent, $validated['order']);

        return back()->with('success', __('Menu order saved.'));
    }

    /**
     * There are only ever three menus, one per {@see MenuLocation} — created
     * on first use rather than requiring a separate seed step, the same way
     * {@see CmsLandingPageSeeder::seedMenus()} does.
     */
    protected function menuFor(string $location): Menu
    {
        if (MenuLocation::tryFrom($location) === null) {
            throw ValidationException::withMessages(['location' => __('Unknown menu location.')]);
        }

        return Menu::query()->firstOrCreate(['location' => $location]);
    }

    protected function itemFor(Menu $menu, string $item): MenuItem
    {
        return $menu->allItems()->wherePublicId($item)->firstOrFail();
    }

    protected function parentIdFor(Menu $menu, ?string $parentPublicId): ?int
    {
        return filled($parentPublicId) ? $this->itemFor($menu, $parentPublicId)->id : null;
    }
}
