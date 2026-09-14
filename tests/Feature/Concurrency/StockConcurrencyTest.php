<?php

use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Inventory\Enums\ReservationKind;
use App\Domain\Inventory\Enums\StockBucket;
use App\Domain\Inventory\Enums\StockMovementType;
use App\Domain\Inventory\Enums\StockReservationStatus;
use App\Domain\Inventory\Models\StockAllocation;
use App\Domain\Inventory\Models\StockItem;
use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Inventory\Models\StockReservation;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\StockAllocations;
use App\Domain\Inventory\StockLedger;
use App\Domain\Inventory\StockReservations;
use Illuminate\Support\Facades\DB;

/*
 * §43's overselling test, with real processes (P3-31).
 *
 * "Parallel orders on the last unit — exactly one succeeds." An order takes its
 * stock through a reservation at submission (contract §6.1.2), so these fork
 * real workers that submit reservations for the same stock at the same instant,
 * each on its own database connection, and then ask the database what happened.
 * A single-process test calling the action twice cannot tell a row lock from a
 * comment claiming there is one.
 *
 * What is being proved is the database guarantee, not the distributed lock: in
 * the test environment the lock store is per process, so nothing serialises the
 * workers except the stock item's row lock, the re-check inside it, and the
 * CHECK constraints underneath.
 *
 * As in the wallet races (P2-10): the data must be committed for the workers to
 * see it, so these tests run on a connection of their own and clean up after
 * themselves, and a child lets go of nothing it inherited — the parent purges its
 * connection before forking and each child opens its own.
 *
 * The product is left as a draft on purpose: out-of-stock protection only moves
 * a product that is on sale, so nothing here writes an audit entry that would
 * outlive the test.
 */

beforeEach(function () {
    config()->set('database.connections.stock_race', config('database.connections.pgsql'));
    config()->set('database.default', 'stock_race');

    $this->category = Category::create(['name' => 'Race '.uniqid()]);
    $this->product = Product::create(['name' => 'Race kettle', 'sku' => 'FW-RACE-'.strtoupper(uniqid()), 'category_id' => $this->category->id]);
    $this->warehouses = [Warehouse::create(['code' => 'RACE-'.strtoupper(uniqid()), 'name' => 'Race warehouse', 'is_default' => true])];
    $this->item = StockItem::create(['warehouse_id' => $this->warehouses[0]->id, 'product_id' => $this->product->id]);
    $this->accounts = [];
});

afterEach(function () {
    $items = StockItem::query()->where('product_id', $this->product->id)->pluck('id');

    // The movement history refuses deletion by design; this is the one place
    // allowed to reach past it, and only for rows this test wrote.
    DB::statement('ALTER TABLE stock_movements DISABLE TRIGGER stock_movements_no_delete');

    DB::table('stock_reservations')->whereIn('stock_item_id', $items)->delete();
    DB::table('stock_movements')->whereIn('stock_item_id', $items)->delete();
    DB::table('stock_allocations')->whereIn('stock_item_id', $items)->delete();
    DB::table('stock_items')->whereIn('id', $items)->delete();
    DB::table('warehouses')->whereIn('id', collect($this->warehouses)->pluck('id'))->delete();
    DB::table('products')->where('id', $this->product->id)->delete();
    DB::table('categories')->where('id', $this->category->id)->delete();

    foreach ($this->accounts as $account) {
        DB::table('business_accounts')->where('id', $account->id)->delete();
        DB::table('users')->where('id', $account->owner_id)->delete();
    }

    DB::statement('ALTER TABLE stock_movements ENABLE TRIGGER stock_movements_no_delete');
});

/**
 * Run one piece of work in `$count` real processes, started together.
 *
 * Children are killed rather than exited, so they never run PHPUnit's shutdown
 * against connection objects they only inherited and the parent still uses.
 */
function stockRace(int $count, Closure $work): void
{
    DB::purge('stock_race');

    // A shared starting gun, so the workers overlap instead of finishing one by
    // one in the time it takes to fork the next.
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
                // Being refused is the expected outcome for most workers. What
                // happened is read from the database afterwards, not from here.
            }

            posix_kill(posix_getpid(), SIGKILL);
        }

        $pids[] = $pid;
    }

    foreach ($pids as $pid) {
        pcntl_waitpid($pid, $status);
    }
}

function stockRaceStock(StockItem $item, int $units): void
{
    app(StockLedger::class)->move($item, null, StockBucket::Available, $units, StockMovementType::Adjustment);
}

function stockRaceAccount(): BusinessAccount
{
    $account = testBusinessAccount(AccountStatus::Active);

    // Recorded for afterEach, which removes what these committed tests wrote.
    test()->accounts = [...test()->accounts, $account];

    return $account;
}

/**
 * @return array<string, int>
 */
function stockRaceFigures(StockItem $item): array
{
    return StockItem::query()->findOrFail($item->id)->buckets();
}

it('lets exactly one of many parallel orders take the last unit', function () {
    stockRaceStock($this->item, 1);

    stockRace(6, function (int $worker) {
        app(StockReservations::class)->reserve(
            Product::query()->findOrFail(test()->product->id),
            null,
            1,
            ReservationKind::OnlinePayment,
            'race:last-unit:'.$worker,
        );
    });

    expect(StockReservation::query()->where('stock_item_id', $this->item->id)->count())->toBe(1)
        ->and(StockMovement::query()->where('stock_item_id', $this->item->id)->where('type', StockMovementType::Reservation->value)->count())->toBe(1)
        ->and(stockRaceFigures($this->item))->toMatchArray(['available' => 0, 'reserved' => 1]);
});

it('fills the last unit of each warehouse once, however the parallel orders fall across them', function () {
    $second = Warehouse::create(['code' => 'RACE-'.strtoupper(uniqid()), 'name' => 'Second race warehouse', 'priority' => 5]);
    $this->warehouses[] = $second;
    $other = StockItem::create(['warehouse_id' => $second->id, 'product_id' => $this->product->id]);

    stockRaceStock($this->item, 1);
    stockRaceStock($other, 1);

    stockRace(6, function (int $worker) {
        app(StockReservations::class)->reserve(
            Product::query()->findOrFail(test()->product->id),
            null,
            1,
            ReservationKind::CashOnDelivery,
            'race:two-warehouses:'.$worker,
        );
    });

    expect(StockReservation::query()->whereIn('stock_item_id', [$this->item->id, $other->id])->count())->toBe(2)
        ->and(StockReservation::query()->where('stock_item_id', $this->item->id)->count())->toBe(1)
        ->and(StockReservation::query()->where('stock_item_id', $other->id)->count())->toBe(1)
        ->and(stockRaceFigures($this->item))->toMatchArray(['available' => 0, 'reserved' => 1])
        ->and(stockRaceFigures($other))->toMatchArray(['available' => 0, 'reserved' => 1]);
});

it('reserves one order once, however many workers submit it at the same instant', function () {
    stockRaceStock($this->item, 3);

    stockRace(4, function () {
        app(StockReservations::class)->reserve(
            Product::query()->findOrFail(test()->product->id),
            null,
            1,
            ReservationKind::OnlinePayment,
            'race:the-same-order',
        );
    });

    expect(StockReservation::query()->where('reference', 'race:the-same-order')->count())->toBe(1)
        ->and(StockMovement::query()->where('stock_item_id', $this->item->id)->where('type', StockMovementType::Reservation->value)->count())->toBe(1)
        ->and(stockRaceFigures($this->item))->toMatchArray(['available' => 2, 'reserved' => 1]);
});

it('never lets an order and a manual removal both take the last unit', function () {
    stockRaceStock($this->item, 1);

    stockRace(6, function (int $worker) {
        $item = StockItem::query()->findOrFail(test()->item->id);

        if ($worker % 2 === 0) {
            app(StockReservations::class)->reserve(
                Product::query()->findOrFail(test()->product->id),
                null,
                1,
                ReservationKind::OnlinePayment,
                'race:order-or-removal:'.$worker,
            );
        } else {
            app(StockLedger::class)->move($item, StockBucket::Available, null, 1, StockMovementType::Adjustment);
        }
    });

    $reserved = StockReservation::query()->where('stock_item_id', $this->item->id)->count();
    $removed = StockMovement::query()
        ->where('stock_item_id', $this->item->id)
        ->where('type', StockMovementType::Adjustment->value)
        ->where('from_bucket', StockBucket::Available->value)
        ->count();

    expect($reserved + $removed)->toBe(1)
        ->and(stockRaceFigures($this->item))->toMatchArray(['available' => 0, 'reserved' => $reserved]);
});

it('keeps an account\'s allocated last unit for that account while other accounts race for shared stock', function () {
    $karim = stockRaceAccount();
    $rahim = stockRaceAccount();

    stockRaceStock($this->item, 2);
    app(StockAllocations::class)->allocate(StockItem::query()->findOrFail($this->item->id), $karim, 1);

    stockRace(6, function (int $worker) use ($karim, $rahim) {
        app(StockReservations::class)->reserve(
            Product::query()->findOrFail(test()->product->id),
            null,
            1,
            ReservationKind::OnlinePayment,
            'race:allocated:'.$worker,
            BusinessAccount::query()->findOrFail($worker < 2 ? $karim->id : $rahim->id),
        );
    });

    $reservations = StockReservation::query()->where('stock_item_id', $this->item->id)->get();

    expect($reservations)->toHaveCount(2)
        // The allocated unit went to Karim, and only to Karim…
        ->and($reservations->whereNotNull('stock_allocation_id')->pluck('business_account_id')->all())->toBe([$karim->id])
        // …Rahim never drew on it…
        ->and($reservations->where('business_account_id', $rahim->id)->whereNotNull('stock_allocation_id'))->toHaveCount(0)
        // …and the one shared unit went to exactly one order, whoever's it was.
        ->and($reservations->whereNull('stock_allocation_id'))->toHaveCount(1)
        ->and(StockAllocation::query()->where('stock_item_id', $this->item->id)->sole()->quantity)->toBe(0)
        ->and(stockRaceFigures($this->item))->toMatchArray(['available' => 0, 'allocated' => 0, 'reserved' => 2]);
});

it('ends a reservation on the last unit exactly once when a release and a commit race', function () {
    stockRaceStock($this->item, 1);

    $reservation = app(StockReservations::class)->reserve($this->product, null, 1, ReservationKind::CashOnDelivery, 'race:end-once');

    stockRace(4, function (int $worker) use ($reservation) {
        $fresh = StockReservation::query()->findOrFail($reservation->id);

        $worker % 2 === 0
            ? app(StockReservations::class)->release($fresh, 'Customer cancelled')
            : app(StockReservations::class)->commit($fresh);
    });

    $ended = StockReservation::query()->findOrFail($reservation->id);
    $endings = StockMovement::query()
        ->where('source_type', 'stock_reservation')
        ->where('source_id', $reservation->id)
        ->where('type', '!=', StockMovementType::Reservation->value)
        ->count();

    expect($ended->status)->toBeIn([StockReservationStatus::Released, StockReservationStatus::Committed])
        ->and($endings)->toBe(1)
        ->and(stockRaceFigures($this->item))->toMatchArray($ended->status === StockReservationStatus::Committed
            ? ['available' => 0, 'reserved' => 0, 'processing' => 1]
            : ['available' => 1, 'reserved' => 0, 'processing' => 0]);
});
