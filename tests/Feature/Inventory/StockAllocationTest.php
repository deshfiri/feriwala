<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Inventory\Actions\AllocateStock;
use App\Domain\Inventory\Actions\ReleaseAllocatedStock;
use App\Domain\Inventory\Enums\ReservationKind;
use App\Domain\Inventory\Enums\StockBucket;
use App\Domain\Inventory\Enums\StockMovementType;
use App\Domain\Inventory\Exceptions\InventoryRefused;
use App\Domain\Inventory\Models\StockAllocation;
use App\Domain\Inventory\Models\StockItem;
use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\StockAllocations;
use App\Domain\Inventory\StockLedger;
use App\Domain\Inventory\StockReservations;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * User-allocated stock, administered (P3-30, §19).
 *
 * Central stock set aside for one business account: units leave available, so no
 * other account or website can reach them, and only that account's orders draw on
 * them. Allocating and releasing are `inventory.approve`, with a reason, through
 * the stock ledger, and audited — and the database holds each item's allocated
 * figure to the sum of its allocations.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->manager = testPlatformStaff(PlatformRole::InventoryManager);
    $category = Category::create(['name' => 'Kitchen']);
    $this->kettle = Product::create(['name' => 'Kettle', 'sku' => 'FW-KT', 'category_id' => $category->id]);
    $this->warehouse = Warehouse::create(['code' => 'DHK', 'name' => 'Dhaka', 'is_default' => true]);
    $this->item = StockItem::create(['warehouse_id' => $this->warehouse->id, 'product_id' => $this->kettle->id]);

    app(StockLedger::class)->move($this->item, null, StockBucket::Available, 10, StockMovementType::Adjustment);

    $this->karim = tap(testBusinessAccount(AccountStatus::Active), fn (BusinessAccount $account) => $account->forceFill(['name' => 'Karim Traders'])->save());
    $this->rahim = tap(testBusinessAccount(AccountStatus::Active), fn (BusinessAccount $account) => $account->forceFill(['name' => 'Rahim Stores'])->save());
});

/**
 * @param  array<string, mixed>  $overrides
 */
function stockAllocationPost(array $overrides = [], ?User $as = null): TestResponse
{
    return test()->actingAs($as ?? test()->manager)->post(
        route('admin.inventory.stock.allocations.store', test()->item->public_id),
        ['account' => test()->karim->public_id, 'quantity' => 4, 'reason' => 'Held for the Eid wholesale contract', ...$overrides],
    );
}

function stockAllocationFor(BusinessAccount $account): ?StockAllocation
{
    return StockAllocation::query()
        ->where('stock_item_id', test()->item->id)
        ->where('business_account_id', $account->id)
        ->first();
}

describe('allocating stock to an account', function () {
    it('sets available units aside for the account, with a movement and an audit entry', function () {
        stockAllocationPost()->assertSessionHasNoErrors()->assertRedirect();

        $allocation = stockAllocationFor($this->karim);
        $movement = StockMovement::query()->where('type', StockMovementType::Allocation->value)->sole();
        $audit = AuditLog::query()->where('action', 'inventory.stock_allocated')->sole();

        expect($this->item->refresh()->buckets())->toMatchArray(['available' => 6, 'allocated' => 4])
            ->and($allocation->quantity)->toBe(4)
            ->and($movement->from_bucket)->toBe(StockBucket::Available)
            ->and($movement->to_bucket)->toBe(StockBucket::Allocated)
            ->and($movement->actor_id)->toBe($this->manager->id)
            ->and($movement->source_type)->toBe('stock_allocation')
            ->and($movement->source_id)->toBe($allocation->id)
            ->and($audit->actor_id)->toBe($this->manager->id)
            ->and($audit->reason)->toBe('Held for the Eid wholesale contract')
            ->and($audit->before['allocation'])->toBe(0)
            ->and($audit->after['allocation'])->toBe(4);
    });

    it('adds to an account\'s allocation rather than opening a second, and keeps accounts apart', function () {
        stockAllocationPost(['quantity' => 3]);
        stockAllocationPost(['quantity' => 2]);
        stockAllocationPost(['account' => $this->rahim->public_id, 'quantity' => 1]);

        expect(StockAllocation::query()->count())->toBe(2)
            ->and(stockAllocationFor($this->karim)->quantity)->toBe(5)
            ->and(stockAllocationFor($this->rahim)->quantity)->toBe(1)
            ->and($this->item->refresh()->buckets())->toMatchArray(['available' => 4, 'allocated' => 6]);
    });

    it('refuses more than is available, an account that cannot trade, and a switched-off warehouse, writing nothing', function () {
        stockAllocationPost(['quantity' => 11])->assertSessionHasErrors('quantity');

        $suspended = testBusinessAccount(AccountStatus::Suspended);

        stockAllocationPost(['account' => $suspended->public_id])
            ->assertSessionHasErrors(['account' => __('inventory.refused.account_cannot_hold_stock', ['account' => $suspended->name])]);

        $sylhet = Warehouse::create(['code' => 'SYL', 'name' => 'Sylhet']);
        $held = StockItem::create(['warehouse_id' => $sylhet->id, 'product_id' => $this->kettle->id]);
        app(StockLedger::class)->move($held, null, StockBucket::Available, 5, StockMovementType::Adjustment);
        $sylhet->forceFill(['is_active' => false])->save();

        $this->actingAs($this->manager)
            ->post(route('admin.inventory.stock.allocations.store', $held->public_id), [
                'account' => $this->karim->public_id, 'quantity' => 1, 'reason' => 'Held for the Eid wholesale contract',
            ])
            ->assertSessionHasErrors(['quantity' => __('inventory.refused.warehouse_inactive')]);

        expect(StockAllocation::query()->count())->toBe(0)
            ->and(StockMovement::query()->where('type', StockMovementType::Allocation->value)->exists())->toBeFalse()
            ->and(AuditLog::query()->where('action', 'inventory.stock_allocated')->exists())->toBeFalse()
            ->and($this->item->refresh()->allocated)->toBe(0);
    });

    it('refuses a request missing what an allocation needs', function (array $payload, string $field) {
        stockAllocationPost($payload)->assertSessionHasErrors($field);

        expect(StockAllocation::query()->count())->toBe(0);
    })->with([
        'no account' => [['account' => ''], 'account'],
        'an unknown account' => [['account' => '01JAAAAAAAAAAAAAAAAAAAAAAA'], 'account'],
        'no units' => [['quantity' => 0], 'quantity'],
        'a reason too short to act on' => [['reason' => 'because'], 'reason'],
    ]);

    it('lets only those who may approve inventory changes allocate or release stock', function (Closure $identity) {
        $allocation = app(StockAllocations::class)->allocate($this->item, $this->karim, 3);
        $who = $identity();

        stockAllocationPost([], $who)->assertForbidden();

        $this->actingAs($who)
            ->post(route('admin.inventory.allocations.release', $allocation->public_id), ['quantity' => 1, 'reason' => 'Contract ended early for Karim'])
            ->assertForbidden();

        expect(fn () => app(AllocateStock::class)->handle($who, $this->item, $this->karim, 1, 'Held for the Eid wholesale contract'))
            ->toThrow(AuthorizationException::class)
            ->and(fn () => app(ReleaseAllocatedStock::class)->handle($who, $allocation, 1, 'Contract ended early for Karim'))
            ->toThrow(AuthorizationException::class)
            ->and($allocation->refresh()->quantity)->toBe(3)
            ->and($this->item->refresh()->buckets())->toMatchArray(['available' => 7, 'allocated' => 3]);
    })->with([
        'staff who may only view stock' => [fn () => testPlatformStaff(PlatformRole::ProductManager)],
        'staff without inventory' => [fn () => testPlatformStaff(PlatformRole::SmsManager)],
        'a partner handed every inventory permission' => [
            fn () => tap(testBusinessAccount(AccountStatus::Active)->owner, fn (User $owner) => $owner->givePermissionTo(['inventory.view', 'inventory.edit', 'inventory.approve'])),
        ],
    ]);
});

describe('releasing allocated stock', function () {
    it('gives units back to available, audited, and never more than the allocation holds', function () {
        $allocation = app(StockAllocations::class)->allocate($this->item, $this->karim, 5);
        $url = route('admin.inventory.allocations.release', $allocation->public_id);

        $this->actingAs($this->manager)
            ->post($url, ['quantity' => 2, 'reason' => 'Contract reduced by the customer'])
            ->assertSessionHasNoErrors();

        expect($allocation->refresh()->quantity)->toBe(3)
            ->and($this->item->refresh()->buckets())->toMatchArray(['available' => 7, 'allocated' => 3])
            ->and(StockMovement::query()->where('type', StockMovementType::AllocationReleased->value)->sole()->to_bucket)->toBe(StockBucket::Available)
            ->and(AuditLog::query()->where('action', 'inventory.allocation_released')->sole()->after['allocation'])->toBe(3);

        $this->actingAs($this->manager)
            ->post($url, ['quantity' => 4, 'reason' => 'Contract reduced by the customer'])
            ->assertSessionHasErrors(['quantity' => __('inventory.refused.allocation_insufficient', ['held' => 3, 'requested' => 4])]);

        expect($allocation->refresh()->quantity)->toBe(3)
            ->and($this->item->refresh()->allocated)->toBe(3);
    });

    it('cannot give back units one of the account\'s orders has already reserved', function () {
        $allocation = app(StockAllocations::class)->allocate($this->item, $this->karim, 3);
        app(StockReservations::class)->reserve($this->kettle, null, 2, ReservationKind::OnlinePayment, 'ORD-ALLOC-1', $this->karim);

        expect(fn () => app(ReleaseAllocatedStock::class)->handle($this->manager, $allocation->refresh(), 2, 'Contract reduced by the customer'))
            ->toThrow(InventoryRefused::class)
            ->and($allocation->refresh()->quantity)->toBe(1)
            ->and($this->item->refresh()->buckets())->toMatchArray(['available' => 7, 'reserved' => 2, 'allocated' => 1]);
    });
});

describe('the database holds allocations together', function () {
    it('refuses, at commit, an allocated figure its allocations do not add up to', function (Closure $breakIt) {
        app(StockAllocations::class)->allocate($this->item, $this->karim, 3);

        expect(fn () => DB::transaction(function () use ($breakIt) {
            $breakIt();

            // What COMMIT would do: check the deferred guard now.
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        }))->toThrow(QueryException::class);

        DB::statement('SET CONSTRAINTS ALL DEFERRED');

        expect($this->item->refresh()->allocated)->toBe(3);
    })->with([
        'the figure moved with no allocation behind it' => [fn () => DB::table('stock_items')->where('id', test()->item->id)->update(['allocated' => 5])],
        'an allocation changed without the figure' => [fn () => DB::table('stock_allocations')->update(['quantity' => 1])],
    ]);

    it('refuses a reservation on another account\'s allocation, a negative allocation, and handing an allocation to another account', function () {
        $allocation = app(StockAllocations::class)->allocate($this->item, $this->karim, 3);

        expect(fn () => DB::transaction(fn () => DB::table('stock_reservations')->insert([
            'public_id' => (string) Str::ulid(),
            'stock_item_id' => $this->item->id,
            'quantity' => 1,
            'kind' => 'cod',
            'status' => 'active',
            'reference' => 'ORD-FORGED',
            'expires_at' => now()->addHour(),
            'business_account_id' => $this->rahim->id,
            'stock_allocation_id' => $allocation->id,
            'created_at' => now(),
            'updated_at' => now(),
        ])))->toThrow(QueryException::class)
            ->and(fn () => DB::transaction(fn () => DB::table('stock_allocations')->where('id', $allocation->id)->update(['quantity' => -1])))
            ->toThrow(QueryException::class)
            ->and(fn () => DB::transaction(fn () => DB::table('stock_allocations')->where('id', $allocation->id)->update(['business_account_id' => $this->rahim->id])))
            ->toThrow(QueryException::class);
    });
});

describe('the stock screens', function () {
    it('shows an item\'s allocations, and offers allocating only to those who may approve', function () {
        app(StockAllocations::class)->allocate($this->item, $this->karim, 3);
        testBusinessAccount(AccountStatus::Suspended)->forceFill(['name' => 'Karim Suspended'])->save();

        $this->actingAs($this->manager)
            ->get(route('admin.inventory.stock.show', ['item' => $this->item->public_id, 'account_search' => 'Karim']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('item.buckets.allocated', 3)
                ->where('allocations.0.account.name', 'Karim Traders')
                ->where('allocations.0.quantity', 3)
                ->where('can.allocate', true)
                ->missing('accounts')
                ->reloadOnly('accounts', fn (Assert $reload) => $reload
                    ->has('accounts', 1)
                    ->where('accounts.0.name', 'Karim Traders')));

        $this->actingAs(testPlatformStaff(PlatformRole::ProductManager))
            ->get(route('admin.inventory.stock.show', $this->item->public_id))
            ->assertInertia(fn (Assert $page) => $page
                ->where('can.allocate', false)
                ->has('allocations', 1));
    });

    it('lists allocations across stock for anyone who may view inventory, leaving emptied ones out unless asked', function () {
        $allocation = app(StockAllocations::class)->allocate($this->item, $this->karim, 3);
        app(StockAllocations::class)->allocate($this->item, $this->rahim, 2);
        app(StockAllocations::class)->release($allocation, 3);

        $viewer = testPlatformStaff(PlatformRole::ProductManager);

        $this->actingAs($viewer)
            ->get(route('admin.inventory.allocations.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/inventory/allocations')
                ->has('allocations.data', 1)
                ->where('allocations.data.0.account.name', 'Rahim Stores')
                ->where('allocations.data.0.sku', 'FW-KT')
                ->where('can.release', false));

        $this->actingAs($viewer)
            ->get(route('admin.inventory.allocations.index', ['held' => 'all', 'search' => 'karim']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('allocations.data', 1)
                ->where('allocations.data.0.quantity', 0));

        $this->actingAs(testPlatformStaff(PlatformRole::SmsManager))
            ->get(route('admin.inventory.allocations.index'))
            ->assertForbidden();

        $this->actingAs($this->rahim->owner)
            ->get(route('admin.inventory.allocations.index'))
            ->assertForbidden();
    });
});
