<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Inventory\Actions\SetLowStockThreshold;
use App\Domain\Inventory\Enums\ReservationKind;
use App\Domain\Inventory\Enums\StockBucket;
use App\Domain\Inventory\Enums\StockMovementType;
use App\Domain\Inventory\Models\StockItem;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\StockLedger;
use App\Domain\Inventory\StockReservations;
use App\Domain\Notification\Queries\RecentNotifications;
use App\Models\User;
use App\Notifications\Inventory\StockRunningLow;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Low-stock alerts (P3-29, §19).
 *
 * Inventory staff are told when a SKU in a warehouse falls to the threshold
 * somebody set for it — once per shortfall, not on every movement while it stays
 * low, and again only after it has recovered. Running out entirely is the same
 * alert with a sharper event. Nobody outside platform inventory is told.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->manager = testPlatformStaff(PlatformRole::InventoryManager);
    $category = Category::create(['name' => 'Kitchen']);
    $this->product = Product::create(['name' => 'Kettle', 'sku' => 'FW-KT', 'category_id' => $category->id]);
    $this->warehouse = Warehouse::create(['code' => 'DHK', 'name' => 'Dhaka', 'is_default' => true]);
    $this->item = StockItem::create(['warehouse_id' => $this->warehouse->id, 'product_id' => $this->product->id]);

    app(StockLedger::class)->move($this->item, null, StockBucket::Available, 10, StockMovementType::Adjustment);
});

function lowStockMove(?StockBucket $from, ?StockBucket $to, int $quantity): void
{
    app(StockLedger::class)->move(test()->item->refresh(), $from, $to, $quantity, StockMovementType::Adjustment);
}

describe('alerting', function () {
    it('alerts once when stock falls to the threshold, stays quiet while it is low, and alerts again after recovering', function () {
        Notification::fake();

        app(SetLowStockThreshold::class)->handle($this->manager, $this->item, 5);
        Notification::assertNothingSent();

        lowStockMove(StockBucket::Available, null, 5);
        lowStockMove(StockBucket::Available, null, 1);

        Notification::assertSentToTimes($this->manager, StockRunningLow::class, 1);
        Notification::assertSentTo($this->manager, StockRunningLow::class, fn (StockRunningLow $alert) => $alert->available === 5
            && $alert->threshold === 5
            && $alert->sku === 'FW-KT'
            && $alert->warehouse === 'DHK'
            && $alert->event() === 'inventory.stock_low');

        lowStockMove(null, StockBucket::Available, 10);
        expect($this->item->refresh()->low_stock_alerted_at)->toBeNull();

        lowStockMove(StockBucket::Available, null, 10);

        Notification::assertSentToTimes($this->manager, StockRunningLow::class, 2);
    });

    it('counts a reservation taking stock away like any other movement, and calls running out by its name', function () {
        Notification::fake();

        app(SetLowStockThreshold::class)->handle($this->manager, $this->item, 0);

        app(StockReservations::class)->reserve($this->product, null, 10, ReservationKind::OnlinePayment, 'ORD-LOW-1');

        Notification::assertSentTo($this->manager, StockRunningLow::class, fn (StockRunningLow $alert) => $alert->available === 0
            && $alert->event() === 'inventory.stock_out'
            && $alert->toArray($this->manager)['stock_item'] === $this->item->public_id);
    });

    it('alerts at once when a threshold is set at or above what the warehouse already holds', function () {
        Notification::fake();

        app(SetLowStockThreshold::class)->handle($this->manager, $this->item, 12);

        Notification::assertSentToTimes($this->manager, StockRunningLow::class, 1);
        expect($this->item->refresh()->low_stock_alerted_at)->not->toBeNull();
    });

    it('stays silent where no threshold is set, and stops once one is cleared', function () {
        Notification::fake();

        lowStockMove(StockBucket::Available, null, 10);

        app(SetLowStockThreshold::class)->handle($this->manager, $this->item, 8);
        app(SetLowStockThreshold::class)->handle($this->manager, $this->item->refresh(), null);
        lowStockMove(null, StockBucket::Available, 1);
        lowStockMove(StockBucket::Available, null, 1);

        Notification::assertSentToTimes($this->manager, StockRunningLow::class, 1);
        expect($this->item->refresh()->low_stock_threshold)->toBeNull();
    });

    it('does not move when the stock last changed', function () {
        $stamped = $this->item->refresh()->updated_at;

        $this->travel(5)->minutes();
        Notification::fake();
        app(SetLowStockThreshold::class)->handle($this->manager, $this->item, 20);

        expect($this->item->refresh()->low_stock_alerted_at)->not->toBeNull()
            ->and($this->item->updated_at->equalTo($stamped))->toBeTrue();
    });
});

describe('recipients', function () {
    it('tells platform staff who may view inventory and the Super Admin, and nobody else', function () {
        $viewer = testPlatformStaff(PlatformRole::ProductManager);
        $superAdmin = testPlatformStaff(PlatformRole::SuperAdmin);
        $unrelated = testPlatformStaff(PlatformRole::SmsManager);
        $locked = User::factory()->staff()->locked()->create();
        $locked->assignRole(PlatformRole::InventoryManager->value);
        $partner = tap(testBusinessAccount(AccountStatus::Active)->owner, fn (User $owner) => $owner->givePermissionTo('inventory.view'));

        Notification::fake();

        app(SetLowStockThreshold::class)->handle($this->manager, $this->item, 10);

        foreach ([$this->manager, $viewer, $superAdmin] as $told) {
            Notification::assertSentToTimes($told, StockRunningLow::class, 1);
        }

        foreach ([$unrelated, $locked, $partner] as $untold) {
            Notification::assertNotSentTo($untold, StockRunningLow::class);
        }
    });

    it('rings the bell in the reader\'s language and links straight to the stock', function () {
        app(SetLowStockThreshold::class)->handle($this->manager, $this->item, 3);
        lowStockMove(StockBucket::Available, null, 10);

        $row = app(RecentNotifications::class)->forUser($this->manager)[0];

        expect($row->title)->toBe(__('notification.events', [], 'en')['inventory.stock_out']['title'])
            ->and($row->href)->toBe(route('admin.inventory.stock.show', $this->item->public_id));

        app()->setLocale('bn');

        expect(app(RecentNotifications::class)->forUser($this->manager)[0]->title)
            ->toBe(__('notification.events', [], 'bn')['inventory.stock_out']['title']);
    });
});

describe('setting a threshold', function () {
    it('saves and audits a threshold, and clears it when left empty', function () {
        $url = route('admin.inventory.stock.threshold', $this->item->public_id);

        $this->actingAs($this->manager)->patch($url, ['low_stock_threshold' => '4'])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        expect($this->item->refresh()->low_stock_threshold)->toBe(4)
            ->and(AuditLog::query()->where('action', 'inventory.low_stock_threshold_set')->sole())
            ->actor_id->toBe($this->manager->id)
            ->after->toBe(['low_stock_threshold' => 4]);

        $this->actingAs($this->manager)->patch($url, ['low_stock_threshold' => ''])->assertSessionHasNoErrors();

        expect($this->item->refresh()->low_stock_threshold)->toBeNull();
    });

    it('refuses a threshold that is not a whole number of units', function (mixed $value) {
        $this->actingAs($this->manager)
            ->patch(route('admin.inventory.stock.threshold', $this->item->public_id), ['low_stock_threshold' => $value])
            ->assertSessionHasErrors('low_stock_threshold');

        expect($this->item->refresh()->low_stock_threshold)->toBeNull();
    })->with([
        'negative' => [-1],
        'fractional' => ['2.5'],
        'text' => ['some'],
    ]);

    it('lets only those who may change stock set a threshold', function (Closure $identity) {
        $this->actingAs($identity())
            ->patch(route('admin.inventory.stock.threshold', $this->item->public_id), ['low_stock_threshold' => 3])
            ->assertForbidden();

        expect($this->item->refresh()->low_stock_threshold)->toBeNull();
    })->with([
        'staff who may only view stock' => [fn () => testPlatformStaff(PlatformRole::ProductManager)],
        'staff without inventory' => [fn () => testPlatformStaff(PlatformRole::SmsManager)],
        'a partner handed inventory permissions' => [
            fn () => tap(testBusinessAccount(AccountStatus::Active)->owner, fn (User $owner) => $owner->givePermissionTo(['inventory.view', 'inventory.edit'])),
        ],
    ]);

    it('refuses the action itself, and the database, whatever the caller', function () {
        expect(fn () => app(SetLowStockThreshold::class)->handle(testPlatformStaff(PlatformRole::ProductManager), $this->item, 3))
            ->toThrow(AuthorizationException::class)
            ->and(fn () => DB::table('stock_items')->where('id', $this->item->id)->update(['low_stock_threshold' => -1]))
            ->toThrow(QueryException::class);
    });
});

describe('the stock screens', function () {
    it('lists low stock under its own state, and shows who may set the threshold', function () {
        $other = StockItem::create([
            'warehouse_id' => Warehouse::create(['code' => 'CTG', 'name' => 'Chattogram'])->id,
            'product_id' => $this->product->id,
        ]);
        app(StockLedger::class)->move($other, null, StockBucket::Available, 50, StockMovementType::Adjustment);

        Notification::fake();
        app(SetLowStockThreshold::class)->handle($this->manager, $this->item, 10);
        app(SetLowStockThreshold::class)->handle($this->manager, $other->refresh(), 10);

        $this->actingAs($this->manager)
            ->get(route('admin.inventory.stock.index', ['state' => 'low_stock']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.state', 'low_stock')
                ->has('items.data', 1)
                ->where('items.data.0.id', $this->item->public_id)
                ->where('items.data.0.is_low', true)
                ->where('items.data.0.low_stock_threshold', 10));

        $this->actingAs(testPlatformStaff(PlatformRole::ProductManager))
            ->get(route('admin.inventory.stock.show', $this->item->public_id))
            ->assertInertia(fn (Assert $page) => $page
                ->where('item.low_stock_threshold', 10)
                ->where('can.adjust', false));
    });
});
