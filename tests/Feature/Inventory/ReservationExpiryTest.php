<?php

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Inventory\Actions\ReleaseExpiredReservations;
use App\Domain\Inventory\Enums\ReservationKind;
use App\Domain\Inventory\Enums\StockBucket;
use App\Domain\Inventory\Enums\StockMovementType;
use App\Domain\Inventory\Enums\StockReservationStatus;
use App\Domain\Inventory\Exceptions\InventoryRefused;
use App\Domain\Inventory\Models\StockItem;
use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\StockLedger;
use App\Domain\Inventory\StockReservations;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;

/*
 * Reservation expiry and the release job (P3-26, §19.1, contract §6.1.2).
 *
 * The contract: expired reservations are released by the scheduler, not lazily
 * at read time, so stock frees up predictably. Only a reservation that is still
 * active and whose stored window has run out is expired; everything that ended
 * another way, or was extended, is left exactly as it is.
 */

beforeEach(function () {
    $category = Category::create(['name' => 'Kitchen']);
    $this->kettle = Product::create(['name' => 'Kettle', 'sku' => 'FW-KT', 'category_id' => $category->id]);
    $warehouse = Warehouse::create(['code' => 'DHK', 'name' => 'Dhaka', 'is_default' => true]);
    $this->item = StockItem::create(['warehouse_id' => $warehouse->id, 'product_id' => $this->kettle->id]);

    app(StockLedger::class)->move($this->item, null, StockBucket::Available, 20, StockMovementType::Adjustment);

    $this->reservations = app(StockReservations::class);
    $this->sweep = app(ReleaseExpiredReservations::class);
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

it('releases only active reservations whose window has run out, returning their units to available', function () {
    CarbonImmutable::setTestNow('2026-09-25 10:00:00');

    $due = $this->reservations->reserve($this->kettle, null, 3, ReservationKind::OnlinePayment, 'ORD-DUE');
    $later = $this->reservations->reserve($this->kettle, null, 2, ReservationKind::CashOnDelivery, 'ORD-LATER');
    $paid = $this->reservations->reserve($this->kettle, null, 1, ReservationKind::OnlinePayment, 'ORD-PAID');
    $cancelled = $this->reservations->reserve($this->kettle, null, 1, ReservationKind::OnlinePayment, 'ORD-CANCELLED');
    $this->reservations->commit($paid);
    $this->reservations->release($cancelled, 'Payment failed');

    CarbonImmutable::setTestNow('2026-09-25 10:16:00');

    expect($this->sweep->handle())->toBe(1);

    expect($due->fresh()->status)->toBe(StockReservationStatus::Expired)
        ->and($due->fresh()->released_at)->not->toBeNull()
        ->and($later->fresh()->status)->toBe(StockReservationStatus::Active)
        ->and($paid->fresh()->status)->toBe(StockReservationStatus::Committed)
        ->and($cancelled->fresh()->status)->toBe(StockReservationStatus::Released)
        ->and($this->item->refresh()->buckets())->toMatchArray(['available' => 17, 'reserved' => 2, 'processing' => 1])
        ->and(StockMovement::query()->where('type', 'reservation_expired')->count())->toBe(1);
});

it('does nothing on a second pass', function () {
    CarbonImmutable::setTestNow('2026-09-25 10:00:00');
    $this->reservations->reserve($this->kettle, null, 3, ReservationKind::OnlinePayment, 'ORD-DUE');

    CarbonImmutable::setTestNow('2026-09-25 11:00:00');

    expect($this->sweep->handle())->toBe(1)
        ->and($this->sweep->handle())->toBe(0)
        ->and($this->item->refresh()->available)->toBe(20)
        ->and(StockMovement::query()->where('type', 'reservation_expired')->count())->toBe(1);
});

it('refuses to expire a reservation whose window has not run out, so an extension made mid-sweep holds', function () {
    CarbonImmutable::setTestNow('2026-09-25 10:00:00');
    $reservation = $this->reservations->reserve($this->kettle, null, 3, ReservationKind::OnlinePayment, 'ORD-EARLY');

    CarbonImmutable::setTestNow('2026-09-25 10:05:00');

    expect(fn () => $this->reservations->expire($reservation))
        ->toThrow(InventoryRefused::class, __('inventory.refused.not_yet_expired'));

    expect($reservation->fresh()->status)->toBe(StockReservationStatus::Active)
        ->and($this->item->refresh()->reserved)->toBe(3);
});

it('is run by the scheduler', function () {
    Artisan::call('schedule:list');

    expect(Artisan::output())->toContain('Release stock reservations whose window has run out');
});
