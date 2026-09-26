<?php

namespace App\Http\Controllers\Admin\Cms;

use App\Domain\Cms\Enums\MenuLocation;
use App\Domain\Cms\Models\Menu;
use App\Domain\Cms\Models\MenuItem;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The three public-site menus — header, footer, legal (§4, §34). There is
 * exactly one of each (`cms_menus.location` is unique), so this is a single
 * workspace listing all three with their items, not a resource index —
 * {@see MenuItemController} does the actual writing.
 */
class MenuController extends Controller
{
    public function index(): Response
    {
        Gate::authorize('viewAny', Menu::class);

        $menus = Menu::query()->with('allItems.parent')->get()->keyBy(fn (Menu $menu) => $menu->location->value);

        return Inertia::render('admin/cms/menus/index', [
            'menus' => collect(MenuLocation::cases())->map(fn (MenuLocation $location) => [
                'location' => $location->value,
                'id' => $menus->get($location->value)?->public_id,
                'items' => $menus->get($location->value)?->allItems
                    ->map(fn (MenuItem $item) => [
                        'id' => $item->public_id,
                        'parent_id' => $item->parent?->public_id,
                        'label_en' => $item->label_en,
                        'label_bn' => $item->label_bn,
                        'route_name' => $item->route_name,
                        'external_url' => $item->external_url,
                        'href' => $item->href(),
                        'sort_order' => $item->sort_order,
                        'is_enabled' => $item->is_enabled,
                        'link_target' => $item->link_target,
                    ])
                    ->values() ?? [],
            ])->values(),

            'can' => [
                'edit' => Gate::allows('update', Menu::class),
            ],
        ]);
    }
}
