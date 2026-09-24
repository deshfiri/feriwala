<?php

use App\Domain\Inventory\Enums\ReservationKind;
use App\Domain\Inventory\Enums\StockReservationStatus;
use App\Domain\Inventory\Models\StockReservation;
use App\Domain\Inventory\StockReservations;
use App\Domain\Supplier\Enums\OfferStatus;
use App\Domain\Supplier\Enums\SupplierStatus;
use App\Domain\Supplier\Models\Supplier;
use App\Domain\Supplier\Models\SupplierOffer;
use App\Domain\Supplier\Models\SupplierOfferStock;
use App\Domain\Supplier\Models\SupplierStockMovement;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Illuminate\Support\Facades\DB;

/*
 * §43's overselling test, with real processes, for a Supplier's own stock
 * (D25, P13-21, P13-28) — the Supplier-sourced twin of
 * tests/Feature/Concurrency/StockConcurrencyTest.php. Same reasoning: workers
 * fork onto their own database connection, so what serialises them is the
 * offer stock row's own lock and the CHECK constraints under it, not the
 * distributed lock store (which is per-process in the test environment).
 */

beforeEach(function () {
    config()->set('database.connections.supplier_stock_race', config('database.connections.pgsql'));
    config()->set('database.default', 'supplier_stock_race');

    $this->supplier = Supplier::factory()->create(['status' => SupplierStatus::Approved]);
    $this->offer = SupplierOffer::create([
        'supplier_id' => $this->supplier->id,
        'product_id' => websiteTestProduct()->id,
        'status' => OfferStatus::Active,
        'supplier_rate' => Money::fromDecimal('1000.00', Currency::BDT),
        'platform_rate' => Money::fromDecimal('1300.00', Currency::BDT),
        'currency_code' => 'BDT',
        'activated_at' => now(),
    ]);
    $this->stock = $this->offer->stock()->create(['quantity' => 0]);
});

afterEach(function () {
    DB::statement('ALTER TABLE supplier_stock_movements DISABLE TRIGGER supplier_stock_movements_no_delete');

    // Movements reference the reservation they moved; delete them first. The
    // Supplier and its offer are left in place — a Supplier is never deleted
    // by design (D18-equivalent), and the testing schema starts fresh per run.
    DB::table('supplier_stock_movements')->where('supplier_offer_id', $this->offer->id)->delete();
    DB::table('stock_reservations')->where('supplier_offer_stock_id', $this->stock->id)->delete();

    DB::statement('ALTER TABLE supplier_stock_movements ENABLE TRIGGER supplier_stock_movements_no_delete');
});

/**
 * Run one piece of work in `$count` real processes, started together.
 *
 * Children are killed rather than exited, so they never run PHPUnit's
 * shutdown against connection objects they only inherited.
 */
function supplierStockRace(int $count, Closure $work): void
{
    DB::purge('supplier_stock_race');

    $startAt = microtime(true) + 0.4;
    $pids = [];

    for ($worker = 0; $worker < $count; $worker++) {
        $pid = pcntl_fork();

        expect($pid)->not->toBe(-1);

        if ($pid === 0) {
            usleep((int) max(0, ($startAt - microtime(true)) * 1_000_000));

            try {
                $work($worker);
            } catch (Throwable) {
                // Being refused is the expected outcome for most workers.
            }

            posix_kill(posix_getpid(), SIGKILL);
        }

        $pids[] = $pid;
    }

    foreach ($pids as $pid) {
        pcntl_waitpid($pid, $status);
    }
}

/**
 * @return array<string, int>
 */
function supplierStockRaceFigures(SupplierOfferStock $stock): array
{
    $fresh = SupplierOfferStock::query()->findOrFail($stock->id);

    return [
        'quantity' => $fresh->quantity,
        'reserved_quantity' => $fresh->reserved_quantity,
        'processing_quantity' => $fresh->processing_quantity,
    ];
}

it('lets exactly one of many parallel orders take the last Supplier unit', function () {
    $this->stock->forceFill(['quantity' => 1])->save();

    supplierStockRace(6, function (int $worker) {
        app(StockReservations::class)->reserveFromSupplier(
            SupplierOffer::query()->findOrFail(test()->offer->id),
            1,
            ReservationKind::OnlinePayment,
            'supplier-race:last-unit:'.$worker,
        );
    });

    expect(DB::table('stock_reservations')->where('supplier_offer_stock_id', $this->stock->id)->count())->toBe(1)
        ->and(SupplierStockMovement::query()->where('supplier_offer_id', $this->offer->id)->where('source', 'reservation')->count())->toBe(1)
        ->and(supplierStockRaceFigures($this->stock))->toMatchArray(['quantity' => 0, 'reserved_quantity' => 1]);
});

it('reserves one order once, however many workers submit it at the same instant', function () {
    $this->stock->forceFill(['quantity' => 3])->save();

    supplierStockRace(4, function () {
        app(StockReservations::class)->reserveFromSupplier(
            SupplierOffer::query()->findOrFail(test()->offer->id),
            1,
            ReservationKind::OnlinePayment,
            'supplier-race:the-same-order',
        );
    });

    expect(DB::table('stock_reservations')->where('reference', 'supplier-race:the-same-order')->count())->toBe(1)
        ->and(SupplierStockMovement::query()->where('supplier_offer_id', $this->offer->id)->where('source', 'reservation')->count())->toBe(1)
        ->and(supplierStockRaceFigures($this->stock))->toMatchArray(['quantity' => 2, 'reserved_quantity' => 1]);
});

it('ends a reservation on the last Supplier unit exactly once when a release and a commit race', function () {
    $this->stock->forceFill(['quantity' => 1])->save();

    $reservation = app(StockReservations::class)->reserveFromSupplier(
        $this->offer, 1, ReservationKind::CashOnDelivery, 'supplier-race:end-once',
    );

    supplierStockRace(4, function (int $worker) use ($reservation) {
        $fresh = StockReservation::query()->findOrFail($reservation->id);

        $worker % 2 === 0
            ? app(StockReservations::class)->release($fresh, 'Customer cancelled')
            : app(StockReservations::class)->commit($fresh);
    });

    $ended = StockReservation::query()->findOrFail($reservation->id);
    $endings = SupplierStockMovement::query()
        ->where('supplier_offer_id', $this->offer->id)
        ->where('stock_reservation_id', $reservation->id)
        ->where('source', '!=', 'reservation')
        ->count();

    expect($ended->status)->toBeIn([StockReservationStatus::Released, StockReservationStatus::Committed])
        ->and($endings)->toBe(1)
        ->and(supplierStockRaceFigures($this->stock))->toMatchArray($ended->status === StockReservationStatus::Committed
            ? ['quantity' => 0, 'reserved_quantity' => 0, 'processing_quantity' => 1]
            : ['quantity' => 1, 'reserved_quantity' => 0, 'processing_quantity' => 0]);
});
