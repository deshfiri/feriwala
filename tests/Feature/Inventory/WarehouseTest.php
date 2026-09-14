<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Inventory\Models\Warehouse;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Warehouses (P3-22, §19, D15).
 *
 * Where central stock is held, and which one is tried first. Only the platform
 * keeps them; exactly one active warehouse is the default, held by the database
 * as well as the action; and a code, printed on labels, never changes.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->manager = testPlatformStaff(PlatformRole::InventoryManager);
});

/**
 * @return array<string, mixed>
 */
function inventoryWarehousePayload(array $overrides = []): array
{
    return [
        'code' => 'dhk-1',
        'name' => 'Dhaka central',
        'address' => 'Tejgaon, Dhaka',
        'priority' => 10,
        'is_active' => true,
        ...$overrides,
    ];
}

describe('only the platform keeps warehouses (§19)', function () {
    it('refuses anybody outside inventory administration at every warehouse endpoint', function (Closure $identity) {
        $warehouse = Warehouse::create(['code' => 'W1', 'name' => 'One', 'is_default' => true]);
        $user = $identity();

        $this->actingAs($user)->get(route('admin.inventory.warehouses.index'))->assertForbidden();
        $this->actingAs($user)->post(route('admin.inventory.warehouses.store'), inventoryWarehousePayload())->assertForbidden();
        $this->actingAs($user)->patch(route('admin.inventory.warehouses.update', $warehouse->public_id), ['name' => 'Mine'])->assertForbidden();
        $this->actingAs($user)->patch(route('admin.inventory.warehouses.default', $warehouse->public_id))->assertForbidden();

        expect(Warehouse::query()->count())->toBe(1)
            ->and($warehouse->refresh()->name)->toBe('One');
    })->with([
        'a partner' => fn () => testBusinessAccount(AccountStatus::Active)->owner,
        'a partner holding inventory permissions directly' => fn () => tap(
            testBusinessAccount(AccountStatus::Active)->owner,
            fn (User $owner) => $owner->givePermissionTo(['inventory.view', 'inventory.edit', 'inventory.approve']),
        ),
        'staff whose role lacks inventory' => fn () => testPlatformStaff(PlatformRole::SmsManager),
    ]);

    it('lets staff who may only view read the warehouses and change nothing', function () {
        $viewer = testPlatformStaff(PlatformRole::ProductManager);

        $this->actingAs($viewer)
            ->get(route('admin.inventory.warehouses.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/inventory/warehouses')
                ->where('can.edit', false));

        $this->actingAs($viewer)
            ->post(route('admin.inventory.warehouses.store'), inventoryWarehousePayload())
            ->assertForbidden();

        expect(Warehouse::query()->count())->toBe(0);
    });
});

describe('the default warehouse', function () {
    it('makes the first warehouse the default and not the next, and audits both', function () {
        $this->actingAs($this->manager)->post(route('admin.inventory.warehouses.store'), inventoryWarehousePayload());
        $this->actingAs($this->manager)->post(route('admin.inventory.warehouses.store'), inventoryWarehousePayload(['code' => 'ctg-1', 'name' => 'Chattogram']));

        expect(Warehouse::query()->where('code', 'DHK-1')->value('is_default'))->toBeTrue()
            ->and(Warehouse::query()->where('code', 'CTG-1')->value('is_default'))->toBeFalse()
            ->and(AuditLog::query()->where('action', 'inventory.warehouse_created')->count())->toBe(2);
    });

    it('moves the default so exactly one warehouse holds it', function () {
        $dhaka = Warehouse::create(['code' => 'DHK', 'name' => 'Dhaka', 'is_default' => true]);
        $chattogram = Warehouse::create(['code' => 'CTG', 'name' => 'Chattogram']);

        $this->actingAs($this->manager)
            ->patch(route('admin.inventory.warehouses.default', $chattogram->public_id))
            ->assertSessionHasNoErrors();

        expect($chattogram->refresh()->is_default)->toBeTrue()
            ->and($dhaka->refresh()->is_default)->toBeFalse()
            ->and(Warehouse::query()->where('is_default', true)->count())->toBe(1)
            ->and(AuditLog::query()->where('action', 'inventory.warehouse_default_changed')->exists())->toBeTrue();
    });

    it('refuses switching the default off, and making a switched-off warehouse the default', function () {
        $dhaka = Warehouse::create(['code' => 'DHK', 'name' => 'Dhaka', 'is_default' => true]);
        $closed = Warehouse::create(['code' => 'OLD', 'name' => 'Old depot', 'is_active' => false]);

        $this->actingAs($this->manager)
            ->patch(route('admin.inventory.warehouses.update', $dhaka->public_id), ['name' => 'Dhaka', 'is_active' => false])
            ->assertSessionHasErrors(['is_active' => __('inventory.refused.default_must_stay_active')]);

        $this->actingAs($this->manager)
            ->patch(route('admin.inventory.warehouses.default', $closed->public_id))
            ->assertSessionHasErrors(['warehouse' => __('inventory.refused.default_must_be_active')]);

        expect($dhaka->refresh()->is_active)->toBeTrue()
            ->and($closed->refresh()->is_default)->toBeFalse();
    });

    it('holds one default in the database', function () {
        Warehouse::create(['code' => 'DHK', 'name' => 'Dhaka', 'is_default' => true]);

        expect(fn () => DB::table('warehouses')->insert([
            'public_id' => (string) Str::ulid(), 'code' => 'CTG', 'name' => 'Chattogram', 'is_default' => true,
        ]))->toThrow(QueryException::class, 'warehouses_one_default');
    });

    it('holds an active default in the database', function () {
        expect(fn () => DB::table('warehouses')->insert([
            'public_id' => (string) Str::ulid(), 'code' => 'DHK', 'name' => 'Dhaka', 'is_default' => true, 'is_active' => false,
        ]))->toThrow(QueryException::class, 'warehouses_default_is_active');
    });
});

describe('warehouse codes', function () {
    it('stores a code upper-case and once', function () {
        $this->actingAs($this->manager)
            ->post(route('admin.inventory.warehouses.store'), inventoryWarehousePayload())
            ->assertSessionHasNoErrors();

        $this->actingAs($this->manager)
            ->post(route('admin.inventory.warehouses.store'), inventoryWarehousePayload(['code' => 'DHK-1', 'name' => 'Duplicate']))
            ->assertSessionHasErrors('code');

        $this->actingAs($this->manager)
            ->post(route('admin.inventory.warehouses.store'), inventoryWarehousePayload(['code' => 'DHK 1!']))
            ->assertSessionHasErrors('code');

        expect(Warehouse::query()->pluck('code')->all())->toBe(['DHK-1']);
    });

    it('refuses changing a code, by name in the form and in the database', function () {
        $warehouse = Warehouse::create(['code' => 'DHK', 'name' => 'Dhaka', 'is_default' => true]);

        $this->actingAs($this->manager)
            ->patch(route('admin.inventory.warehouses.update', $warehouse->public_id), ['name' => 'Dhaka', 'code' => 'NEW'])
            ->assertSessionHasErrors(['code' => __('inventory.warehouses.code_fixed')]);

        expect(fn () => DB::table('warehouses')->where('id', $warehouse->id)->update(['code' => 'NEW']))
            ->toThrow(QueryException::class, 'warehouses.code cannot be changed once written');
    });
});

it('lists warehouses default first, with how many SKUs each holds', function () {
    Warehouse::create(['code' => 'CTG', 'name' => 'Chattogram', 'priority' => 1]);
    Warehouse::create(['code' => 'DHK', 'name' => 'Dhaka', 'is_default' => true, 'priority' => 5]);

    $this->actingAs($this->manager)
        ->get(route('admin.inventory.warehouses.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/inventory/warehouses')
            ->has('warehouses', 2)
            ->where('warehouses.0.code', 'DHK')
            ->where('warehouses.0.is_default', true)
            ->where('warehouses.0.stock_items_count', 0)
            ->where('can.edit', true));
});
