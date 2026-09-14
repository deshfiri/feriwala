<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Inventory\Enums\ReservationKind;
use App\Domain\Inventory\Enums\StockBucket;
use App\Domain\Inventory\Enums\StockMovementType;
use App\Domain\Inventory\Enums\StockReservationStatus;
use App\Domain\Inventory\Exceptions\InventoryRefused;
use App\Domain\Inventory\Models\StockItem;
use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Inventory\Models\StockReservation;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\ReservationWindows;
use App\Domain\Inventory\StockLedger;
use App\Domain\Inventory\StockReservations;
use App\Domain\Settings\Enums\SettingType;
use App\Domain\Settings\SettingsRepository;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Stock reservations (P3-25, §19.1, §36.1, contract §6.1.2).
 *
 * Reserving moves units from available to reserved in one warehouse, chosen
 * default-first then by priority, and every reservation carries a stored expiry.
 * A reservation ends exactly once — committed, released or expired — and every
 * operation is idempotent, so a retry never reserves or releases twice, and no
 * sequence of calls takes a figure below zero.
 */

beforeEach(function () {
    $category = Category::create(['name' => 'Kitchen']);
    $this->kettle = Product::create(['name' => 'Kettle', 'sku' => 'FW-KT', 'category_id' => $category->id]);
    $this->dhaka = Warehouse::create(['code' => 'DHK', 'name' => 'Dhaka', 'is_default' => true, 'priority' => 5]);
    $this->chattogram = Warehouse::create(['code' => 'CTG', 'name' => 'Chattogram', 'priority' => 1]);
    $this->sylhet = Warehouse::create(['code' => 'SYL', 'name' => 'Sylhet', 'priority' => 2]);

    $this->reservations = app(StockReservations::class);
});

function reservationStock(Warehouse $warehouse, Product $product, int $available, ?ProductVariant $variant = null): StockItem
{
    $item = StockItem::create([
        'warehouse_id' => $warehouse->id,
        'product_id' => $product->id,
        'product_variant_id' => $variant?->id,
    ]);

    if ($available > 0) {
        app(StockLedger::class)->move($item, null, StockBucket::Available, $available, StockMovementType::Adjustment);
    }

    return $item->refresh();
}

describe('reserving', function () {
    it('moves units from available to reserved in the default warehouse, with a stored expiry and a movement', function () {
        CarbonImmutable::setTestNow('2026-09-25 10:00:00');
        $item = reservationStock($this->dhaka, $this->kettle, 10);
        reservationStock($this->chattogram, $this->kettle, 10);

        $reservation = $this->reservations->reserve($this->kettle, null, 3, ReservationKind::OnlinePayment, 'ORD-1');

        expect($reservation->status)->toBe(StockReservationStatus::Active)
            ->and($reservation->stock_item_id)->toBe($item->id)
            ->and($reservation->expires_at->toDateTimeString())->toBe('2026-09-25 10:15:00')
            ->and($item->refresh()->available)->toBe(7)
            ->and($item->reserved)->toBe(3)
            ->and(StockMovement::query()->where('type', 'reservation')->where('source_id', $reservation->id)->exists())->toBeTrue();

        CarbonImmutable::setTestNow();
    });

    it('falls through to the next warehouse by priority, skipping switched-off ones and any that hold too few', function () {
        reservationStock($this->dhaka, $this->kettle, 2);
        $closed = Warehouse::create(['code' => 'OLD', 'name' => 'Old depot', 'priority' => 0, 'is_active' => false]);
        reservationStock($closed, $this->kettle, 50);
        $sylhet = reservationStock($this->sylhet, $this->kettle, 5);
        $chattogram = reservationStock($this->chattogram, $this->kettle, 4);

        $reservation = $this->reservations->reserve($this->kettle, null, 4, ReservationKind::CashOnDelivery, 'ORD-2');

        expect($reservation->stock_item_id)->toBe($chattogram->id)
            ->and($chattogram->refresh()->reserved)->toBe(4)
            ->and($sylhet->refresh()->reserved)->toBe(0);
    });

    it('refuses when no active warehouse holds the whole quantity, and writes nothing', function () {
        reservationStock($this->dhaka, $this->kettle, 2);
        reservationStock($this->chattogram, $this->kettle, 2);

        expect(fn () => $this->reservations->reserve($this->kettle, null, 3, ReservationKind::OnlinePayment, 'ORD-3'))
            ->toThrow(InventoryRefused::class, __('inventory.refused.out_of_stock', ['sku' => 'FW-KT', 'requested' => 3, 'available' => 4]));

        expect(StockReservation::query()->count())->toBe(0)
            ->and(StockItem::query()->sum('reserved'))->toBe(0)
            ->and(StockMovement::query()->where('type', 'reservation')->count())->toBe(0);
    });

    it('reserves a variation from its own stock', function () {
        $shirt = Product::create(['name' => 'Panjabi', 'sku' => 'FW-PJ', 'category_id' => $this->kettle->category_id]);
        $medium = ProductVariant::create(['product_id' => $shirt->id, 'sku' => 'FW-PJ-M', 'combination_key' => 'm']);
        $large = ProductVariant::create(['product_id' => $shirt->id, 'sku' => 'FW-PJ-L', 'combination_key' => 'l']);
        reservationStock($this->dhaka, $shirt, 5, $medium);
        reservationStock($this->dhaka, $shirt, 1, $large);

        $this->reservations->reserve($shirt, $medium, 5, ReservationKind::OnlinePayment, 'ORD-4');

        expect(fn () => $this->reservations->reserve($shirt, $large, 2, ReservationKind::OnlinePayment, 'ORD-5'))
            ->toThrow(InventoryRefused::class);
    });

    it('reserves once for one reference however often it is retried, and refuses the reference reused for something else', function () {
        $item = reservationStock($this->dhaka, $this->kettle, 10);

        $first = $this->reservations->reserve($this->kettle, null, 3, ReservationKind::OnlinePayment, 'ORD-6');
        $again = $this->reservations->reserve($this->kettle, null, 3, ReservationKind::OnlinePayment, 'ORD-6');

        expect($again->id)->toBe($first->id)
            ->and($item->refresh()->reserved)->toBe(3)
            ->and(StockMovement::query()->where('type', 'reservation')->count())->toBe(1);

        expect(fn () => $this->reservations->reserve($this->kettle, null, 4, ReservationKind::OnlinePayment, 'ORD-6'))
            ->toThrow(InventoryRefused::class, __('inventory.refused.reference_in_use', ['reference' => 'ORD-6']));
    });

    it('stores the contract windows, or the configured ones, bounded', function () {
        CarbonImmutable::setTestNow('2026-09-25 10:00:00');
        reservationStock($this->dhaka, $this->kettle, 10);

        $cod = $this->reservations->reserve($this->kettle, null, 1, ReservationKind::CashOnDelivery, 'ORD-7');

        $settings = app(SettingsRepository::class);
        $settings->define(ReservationWindows::ONLINE_MINUTES, 'inventory', SettingType::Integer);
        $settings->set(ReservationWindows::ONLINE_MINUTES, 2);

        $online = $this->reservations->reserve($this->kettle, null, 1, ReservationKind::OnlinePayment, 'ORD-8');

        expect($cod->expires_at->toDateTimeString())->toBe('2026-09-26 10:00:00')
            // Two minutes is below the floor; the floor applies.
            ->and($online->expires_at->toDateTimeString())->toBe('2026-09-25 10:05:00');

        CarbonImmutable::setTestNow();
    });
});

describe('ending a reservation, exactly once', function () {
    beforeEach(function () {
        $this->item = reservationStock($this->dhaka, $this->kettle, 10);
        $this->reservation = $this->reservations->reserve($this->kettle, null, 4, ReservationKind::OnlinePayment, 'ORD-9');
    });

    it('commits the units on to processing', function () {
        $this->reservations->commit($this->reservation);
        $this->reservations->commit($this->reservation->fresh());

        expect($this->reservation->fresh()->status)->toBe(StockReservationStatus::Committed)
            ->and($this->reservation->fresh()->committed_at)->not->toBeNull()
            ->and($this->item->refresh()->buckets())->toMatchArray(['available' => 6, 'reserved' => 0, 'processing' => 4])
            ->and(StockMovement::query()->where('type', 'reservation_committed')->count())->toBe(1);
    });

    it('releases the units back to available once, however often it is retried', function () {
        $this->reservations->release($this->reservation, 'Payment failed');
        $this->reservations->release($this->reservation->fresh(), 'Payment failed');

        expect($this->reservation->fresh()->status)->toBe(StockReservationStatus::Released)
            ->and($this->reservation->fresh()->release_reason)->toBe('Payment failed')
            ->and($this->item->refresh()->buckets())->toMatchArray(['available' => 10, 'reserved' => 0])
            ->and(StockMovement::query()->where('type', 'reservation_released')->count())->toBe(1);
    });

    it('refuses ending a reservation a second, different way', function () {
        $this->reservations->commit($this->reservation);

        expect(fn () => $this->reservations->release($this->reservation->fresh()))
            ->toThrow(InventoryRefused::class, __('inventory.refused.reservation_ended', ['status' => __('inventory.reservation_statuses.committed')]));

        expect($this->item->refresh()->processing)->toBe(4)
            ->and($this->item->available)->toBe(6);
    });
});

it('shows what the reserved bucket is holding on the stock history screen, active first', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $item = reservationStock($this->dhaka, $this->kettle, 10);

    $released = $this->reservations->reserve($this->kettle, null, 1, ReservationKind::CashOnDelivery, 'ORD-OLD');
    $this->reservations->release($released, 'Customer cancelled');
    $this->reservations->reserve($this->kettle, null, 2, ReservationKind::OnlinePayment, 'ORD-NEW');

    $this->actingAs(testPlatformStaff(PlatformRole::ProductManager))
        ->get(route('admin.inventory.stock.show', $item->public_id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('reservations', 2)
            ->where('reservations.0.reference', 'ORD-NEW')
            ->where('reservations.0.status', 'active')
            ->where('reservations.0.ended_at', null)
            ->where('reservations.1.status', 'released')
            ->where('reservations.1.release_reason', 'Customer cancelled')
            ->where('item.buckets.reserved', 2));
});

describe('the database', function () {
    beforeEach(function () {
        reservationStock($this->dhaka, $this->kettle, 10);
        $this->reservation = $this->reservations->reserve($this->kettle, null, 2, ReservationKind::OnlinePayment, 'ORD-10');
    });

    it('holds a status and its timestamps consistent', function () {
        expect(fn () => DB::table('stock_reservations')->where('id', $this->reservation->id)->update(['status' => 'released']))
            ->toThrow(QueryException::class, 'stock_reservations_status_consistent');
    });

    it('never changes what a reservation is for', function (string $column, mixed $value) {
        expect(fn () => DB::table('stock_reservations')->where('id', $this->reservation->id)->update([$column => $value]))
            ->toThrow(QueryException::class, "stock_reservations.{$column} cannot be changed once written");
    })->with([
        'quantity' => ['quantity', 1],
        'reference' => ['reference', 'ORD-OTHER'],
        'kind' => ['kind', 'cod'],
    ]);

    it('holds one reservation per reference', function () {
        expect(fn () => DB::table('stock_reservations')->insert([
            'public_id' => (string) Str::ulid(),
            'stock_item_id' => $this->reservation->stock_item_id,
            'quantity' => 1,
            'kind' => 'cod',
            'reference' => 'ORD-10',
            'expires_at' => now()->addDay(),
        ]))->toThrow(QueryException::class, 'stock_reservations_reference_unique');
    });
});
