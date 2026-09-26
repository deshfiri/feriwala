<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Cms\Enums\MenuLocation;
use App\Domain\Cms\Models\Menu;
use Database\Seeders\RolesAndPermissionsSeeder;

/*
 * Items within the three public-site menus (§4, §34, Stage 7). An item
 * points at exactly one destination — the same exclusivity rule
 * SectionContentValidatorTest already proved for a CTA's own href.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->manager = testPlatformStaff(PlatformRole::ContentManager);
});

function menuAdminMenu(MenuLocation $location = MenuLocation::Header): Menu
{
    return Menu::query()->firstOrCreate(['location' => $location->value]);
}

it('adds a menu item pointing at a named route', function () {
    $this->actingAs($this->manager)
        ->post(route('admin.cms.menus.items.store', 'header'), [
            'label_en' => 'Sign in',
            'route_name' => 'login',
            'link_target' => 'self',
        ])
        ->assertRedirect();

    expect(menuAdminMenu()->allItems()->where('label_en', 'Sign in')->exists())->toBeTrue();
});

it('adds a menu item pointing at a safe external url', function () {
    $this->actingAs($this->manager)
        ->post(route('admin.cms.menus.items.store', 'legal'), [
            'label_en' => 'Terms',
            'external_url' => '/terms',
            'link_target' => 'self',
        ])
        ->assertRedirect();

    expect(menuAdminMenu(MenuLocation::Legal)->allItems()->where('label_en', 'Terms')->exists())->toBeTrue();
});

it('refuses a menu item with both a route and an external url', function () {
    $this->actingAs($this->manager)
        ->post(route('admin.cms.menus.items.store', 'header'), [
            'label_en' => 'Both',
            'route_name' => 'login',
            'external_url' => '/somewhere',
            'link_target' => 'self',
        ])
        ->assertSessionHasErrors();
});

it('refuses a menu item with neither destination', function () {
    $this->actingAs($this->manager)
        ->post(route('admin.cms.menus.items.store', 'header'), [
            'label_en' => 'Neither',
            'link_target' => 'self',
        ])
        ->assertSessionHasErrors();
});

it('refuses an unsafe external url scheme', function () {
    $this->actingAs($this->manager)
        ->post(route('admin.cms.menus.items.store', 'header'), [
            'label_en' => 'Bad',
            'external_url' => 'javascript:alert(1)',
            'link_target' => 'self',
        ])
        ->assertSessionHasErrors('external_url');
});

it('reorders menu items in one request', function () {
    $menu = menuAdminMenu();
    $a = $menu->allItems()->create(['label_en' => 'A', 'external_url' => '/a', 'sort_order' => 0]);
    $b = $menu->allItems()->create(['label_en' => 'B', 'external_url' => '/b', 'sort_order' => 1]);

    $this->actingAs($this->manager)
        ->post(route('admin.cms.menus.items.reorder', 'header'), [
            'order' => [$b->public_id, $a->public_id],
        ])
        ->assertRedirect();

    expect($menu->allItems()->orderBy('sort_order')->pluck('label_en')->all())->toBe(['B', 'A']);
});

it('removes a menu item', function () {
    $menu = menuAdminMenu();
    $item = $menu->allItems()->create(['label_en' => 'Gone', 'external_url' => '/gone']);

    $this->actingAs($this->manager)
        ->delete(route('admin.cms.menus.items.destroy', ['header', $item->public_id]))
        ->assertRedirect();

    expect($menu->allItems()->count())->toBe(0);
});
