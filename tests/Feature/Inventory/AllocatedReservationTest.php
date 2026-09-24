<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Catalog\Enums\ProductStatus;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Inventory\Actions\SetLowStockThreshold;
use App\Domain\Inventory\Enums\ReservationKind;
use App\Domain\Inventory\Enums\StockBucket;
use App\Domain\Inventory\Enums\StockMovementType;
use App\Domain\Inventory\Exceptions\InventoryRefused;
use App\Domain\Inventory\Models\StockItem;
use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\Queries\StockAvailability;
use App\Domain\Inventory\StockAllocations;
use App\Domain\Inventory\StockLedger;
use App\Domain\Inventory\StockReservations;
use App\Notifications\Inventory\StockRunningLow;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;

/*
 * Orders drawing on user-allocated stock (P3-30, §19, §19.1).
 *
 * An order placed for a business account draws on that account's allocation
 * first, and on shared stock only when the allocation cannot cover the whole
 * order — never on another account's allocation, never partly from each. Units
 * that go back from such an order go back to the allocation. Every other
 * account's availability leaves the allocation out, the product stays on sale
 * while allocated stock remains, and low-stock alerts keep watching the shared
 * figure.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $category = Category::create(['name' => 'Kitchen']);
    $this->kettle = Product::create([
        'name' => 'Kettle',
        'sku' => 'FW-KT',
        'category_id' => $category->id,
        'wholesale_price' => Money::fromDecimal('1200.00', Currency::BDT),
        'status' => ProductStatus::Active,
    ]);
    $this->warehouse = Warehouse::create(['code' => 'DHK', 'name' => 'Dhaka', 'is_default' => true]);
    $this->item = StockItem::create(['warehouse_id' => $this->warehouse->id, 'product_id' => $this->kettle->id]);

    app(StockLedger::class)->move($this->item, null, StockBucket::Available, 10, StockMovementType::Adjustment);

    $this->karim = testBusinessAccount(AccountStatus::Active);
    $this->rahim = testBusinessAccount(AccountStatus::Active);

    $this->allocation = app(StockAllocations::class)->allocate($this->item, $this->karim, 3);
    $this->reservations = app(StockReservations::class);
});

/**
 * @return array<string, int>
 */
function allocatedReservationFigures(): array
{
    return test()->item->refresh()->buckets();
}

it('reserves an account\'s order from its own allocation, and gives the units back to it when released or expired', function () {
    $held = $this->reservations->reserve($this->kettle, null, 2, ReservationKind::OnlinePayment, 'ORD-K-1', $this->karim);

    expect($held->stock_allocation_id)->toBe($this->allocation->id)
        ->and($held->business_account_id)->toBe($this->karim->id)
        ->and(allocatedReservationFigures())->toMatchArray(['available' => 7, 'allocated' => 1, 'reserved' => 2])
        ->and($this->allocation->refresh()->quantity)->toBe(1)
        ->and(StockMovement::query()->where('source_type', 'stock_reservation')->where('source_id', $held->id)->sole()->from_bucket)
        ->toBe(StockBucket::Allocated);

    $this->reservations->release($held, 'Payment failed');

    expect(allocatedReservationFigures())->toMatchArray(['available' => 7, 'allocated' => 3, 'reserved' => 0])
        ->and($this->allocation->refresh()->quantity)->toBe(3);

    $lapsing = $this->reservations->reserve($this->kettle, null, 3, ReservationKind::OnlinePayment, 'ORD-K-2', $this->karim);
    $this->travel(2)->hours();
    $this->reservations->expire($lapsing);

    expect(allocatedReservationFigures())->toMatchArray(['available' => 7, 'allocated' => 3, 'reserved' => 0])
        ->and($this->allocation->refresh()->quantity)->toBe(3)
        ->and(StockMovement::query()->where('type', StockMovementType::ReservationExpired->value)->sole()->to_bucket)
        ->toBe(StockBucket::Allocated);
});

it('commits units drawn from an allocation on to processing, like any other order', function () {
    $held = $this->reservations->reserve($this->kettle, null, 2, ReservationKind::CashOnDelivery, 'ORD-K-3', $this->karim);

    $this->reservations->commit($held);

    expect(allocatedReservationFigures())->toMatchArray(['available' => 7, 'allocated' => 1, 'reserved' => 0, 'processing' => 2])
        ->and($this->allocation->refresh()->quantity)->toBe(1);
});

it('never lets another account\'s order, or an order for no account, reach an allocation', function () {
    expect(fn () => $this->reservations->reserve($this->kettle, null, 8, ReservationKind::OnlinePayment, 'ORD-R-1', $this->rahim))
        ->toThrow(InventoryRefused::class, __('inventory.refused.out_of_stock', ['sku' => 'FW-KT', 'requested' => 8, 'available' => 7]));

    $this->reservations->reserve($this->kettle, null, 7, ReservationKind::OnlinePayment, 'ORD-R-2', $this->rahim);

    expect(fn () => $this->reservations->reserve($this->kettle, null, 1, ReservationKind::OnlinePayment, 'ORD-GUEST'))
        ->toThrow(InventoryRefused::class)
        ->and(fn () => $this->reservations->reserve($this->kettle, null, 1, ReservationKind::OnlinePayment, 'ORD-R-3', $this->rahim))
        ->toThrow(InventoryRefused::class);

    $this->reservations->reserve($this->kettle, null, 3, ReservationKind::OnlinePayment, 'ORD-K-4', $this->karim);

    expect(allocatedReservationFigures())->toMatchArray(['available' => 0, 'allocated' => 0, 'reserved' => 10]);
});

it('draws on shared stock when the account\'s allocation cannot cover the whole order, never part of each', function () {
    $held = $this->reservations->reserve($this->kettle, null, 5, ReservationKind::OnlinePayment, 'ORD-K-5', $this->karim);

    expect($held->stock_allocation_id)->toBeNull()
        ->and($held->business_account_id)->toBe($this->karim->id)
        ->and(allocatedReservationFigures())->toMatchArray(['available' => 2, 'allocated' => 3, 'reserved' => 5]);

    $this->reservations->release($held, 'Customer cancelled');

    expect(allocatedReservationFigures())->toMatchArray(['available' => 7, 'allocated' => 3, 'reserved' => 0]);
});

it('answers a retried reference only when it is the same account\'s order', function () {
    $first = $this->reservations->reserve($this->kettle, null, 1, ReservationKind::OnlinePayment, 'ORD-K-6', $this->karim);

    expect($this->reservations->reserve($this->kettle, null, 1, ReservationKind::OnlinePayment, 'ORD-K-6', $this->karim)->id)->toBe($first->id)
        ->and(fn () => $this->reservations->reserve($this->kettle, null, 1, ReservationKind::OnlinePayment, 'ORD-K-6', $this->rahim))
        ->toThrow(InventoryRefused::class, __('inventory.refused.reference_in_use', ['reference' => 'ORD-K-6']))
        ->and($this->allocation->refresh()->quantity)->toBe(2);
});

it('keeps an allocation out of every other account\'s availability, and adds it to its own', function () {
    $availability = app(StockAvailability::class);
    $shared = $availability->forProduct($this->kettle)[0];

    expect($availability->forSkus(['FW-KT'])['FW-KT']['quantity'])->toBe(7)
        ->and($availability->forSkus(['FW-KT'], $this->rahim)['FW-KT']['quantity'])->toBe(7)
        ->and($availability->forSkus(['FW-KT'], $this->karim)['FW-KT']['quantity'])->toBe(10)
        ->and($availability->forProduct($this->kettle, $this->karim)[0])->toBe([
            'sku' => 'FW-KT',
            'in_stock' => true,
            'quantity' => 10,
            'updated_at' => $shared['updated_at'],
        ]);
});

it('keeps a product on sale while stock allocated to an account remains, and takes it off once that runs out too', function () {
    app(StockLedger::class)->move($this->item->refresh(), StockBucket::Available, null, 7, StockMovementType::Adjustment);

    expect($this->kettle->refresh()->status)->toBe(ProductStatus::Active);

    $held = $this->reservations->reserve($this->kettle, null, 3, ReservationKind::OnlinePayment, 'ORD-K-7', $this->karim);

    expect($this->kettle->refresh()->status)->toBe(ProductStatus::OutOfStock);

    $this->reservations->release($held, 'Payment failed');

    expect($this->kettle->refresh()->status)->toBe(ProductStatus::Active);
});

it('watches the shared figure for low stock when stock is allocated away, and re-arms when it comes back', function () {
    Notification::fake();

    $manager = testPlatformStaff(PlatformRole::InventoryManager);
    app(SetLowStockThreshold::class)->handle($manager, $this->item->refresh(), 5);

    Notification::assertNothingSent();

    app(StockAllocations::class)->allocate($this->item->refresh(), $this->karim, 3);

    Notification::assertSentToTimes($manager, StockRunningLow::class, 1);

    app(StockAllocations::class)->release($this->allocation->refresh(), 6);

    expect($this->item->refresh()->low_stock_alerted_at)->toBeNull()
        ->and(allocatedReservationFigures())->toMatchArray(['available' => 10, 'allocated' => 0]);
});
