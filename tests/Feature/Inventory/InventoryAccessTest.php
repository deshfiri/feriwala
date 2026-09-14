<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Account\Enums\AccountStatus;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Who reaches central stock, and what a refused person is shown (P3-22, §19).
 *
 * The navigation and the endpoints read the same answer: the shared
 * `inventory.view` ability is true exactly for the people the routes let in, so
 * nobody is shown a door that opens onto a refusal. A refusal is the inventory's
 * own page in the application shell, and JSON to an API caller.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

/**
 * The shared navigation permissions, as the browser receives them.
 *
 * @return array<string, bool>
 */
function inventoryNavPermissions(User $user, string $route): array
{
    $response = test()->actingAs($user)->get(route($route));

    /** @var array<string, bool> $permissions */
    $permissions = $response->viewData('page')['props']['permissions'] ?? [];

    return $permissions;
}

it('shows the inventory link exactly to those the stock screen lets in', function (Closure $identity, string $landing, bool $allowed) {
    $user = $identity();

    expect(inventoryNavPermissions($user, $landing)['inventory.view'] ?? null)->toBe($allowed);

    $response = test()->actingAs($user)->get(route('admin.inventory.stock.index'));

    $allowed ? $response->assertOk() : $response->assertForbidden();
})->with([
    'an inventory manager' => [fn () => testPlatformStaff(PlatformRole::InventoryManager), 'admin.inventory.stock.index', true],
    'a product manager, who may view stock' => [fn () => testPlatformStaff(PlatformRole::ProductManager), 'admin.inventory.stock.index', true],
    'staff without inventory' => [fn () => testPlatformStaff(PlatformRole::SmsManager), 'admin.sms.index', false],
    'a partner holding inventory.view directly' => [
        fn () => tap(testBusinessAccount(AccountStatus::Active)->owner, fn (User $owner) => $owner->givePermissionTo('inventory.view')),
        'catalog.wholesale.index',
        false,
    ],
]);

describe('a refused person is shown the inventory refusal page', function () {
    it('tells a partner that central stock is not theirs to change', function () {
        $this->actingAs(testBusinessAccount(AccountStatus::Active)->owner)
            ->get(route('admin.inventory.warehouses.index'))
            ->assertForbidden()
            ->assertInertia(fn (Assert $page) => $page
                ->component('inventory/forbidden')
                ->where('audience', 'business'));
    });

    it('tells staff who may view stock that changing it is what their role lacks', function () {
        $this->actingAs(testPlatformStaff(PlatformRole::ProductManager))
            ->post(route('admin.inventory.warehouses.store'), ['code' => 'X', 'name' => 'X'])
            ->assertForbidden()
            ->assertInertia(fn (Assert $page) => $page
                ->component('inventory/forbidden')
                ->where('audience', 'viewer'));
    });

    it('tells staff without inventory that it is not part of their role', function () {
        $this->actingAs(testPlatformStaff(PlatformRole::SmsManager))
            ->get(route('admin.inventory.stock.index'))
            ->assertForbidden()
            ->assertInertia(fn (Assert $page) => $page
                ->component('inventory/forbidden')
                ->where('audience', 'staff'));
    });

    it('answers an API caller with JSON, never a page', function () {
        $this->actingAs(testBusinessAccount(AccountStatus::Active)->owner)
            ->postJson(route('admin.inventory.stock.store'), [])
            ->assertForbidden()
            ->assertJsonStructure(['message'])
            ->assertJsonMissingPath('errors')
            ->assertJsonMissingPath('component');
    });
});
