<?php

use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Inventory\Actions\ReceivePartnerPurchase;
use App\Domain\Inventory\Enums\ReservationKind;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\PartnerLotWarehouseScope;
use App\Domain\Inventory\StockReservations;
use App\Domain\Order\Enums\AllocationSourceType;
use App\Domain\Order\Enums\AllocationStatus;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderItemAllocation;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

/*
 * Real-process races for a Partner's warehouse-specific stock: two Storefront
 * holds started together, each drawing on the same warehouse's lots, on a
 * connection whose writes actually commit -- then asked what happened.
 *
 * A unit is never taken twice, and a hold that finds its warehouse's lots
 * already taken is never topped up from another warehouse.
 */
beforeEach(function () {
    config()->set('database.connections.lot_race', config('database.connections.pgsql'));
    config()->set('database.default', 'lot_race');

    $this->watermarks = [];

    foreach (['orders', 'payments', 'order_items', 'audit_logs', 'users', 'categories', 'stock_reservations'] as $table) {
        $this->watermarks[$table] = (int) DB::table($table)->max('id');
    }

    $this->account = testBusinessAccount(AccountStatus::Active);
    $this->product = websiteTestProduct();
    $this->tejgaon = Warehouse::create(['code' => 'LR-T-'.Str::upper(Str::random(5)), 'name' => 'Tejgaon', 'is_active' => true]);
    $this->chattogram = Warehouse::create(['code' => 'LR-C-'.Str::upper(Str::random(5)), 'name' => 'Chattogram', 'is_active' => true]);

    // Two units bought from each warehouse, credited through the real purchase.
    $this->tejgaonLot = partnerLotRaceBuy($this->tejgaon, 2);
    $this->chattogramLot = partnerLotRaceBuy($this->chattogram, 2);
});

afterEach(function () {
    $orders = DB::table('orders')->where('id', '>', $this->watermarks['orders'])->pluck('id')->all();
    $items = DB::table('partner_stock_items')->where('business_account_id', $this->account->id)->pluck('id')->all();
    $payments = DB::table('payments')->where('id', '>', $this->watermarks['payments'])->pluck('id')->all();

    concurrencyTestForgetCostLots($this->account->id);

    // Append-only by trigger; the guards come off only for rows this test wrote.
    $tables = ['partner_stock_movements', 'order_item_allocations', 'order_items', 'order_status_history', 'orders', 'audit_logs'];

    foreach ($tables as $table) {
        DB::statement("ALTER TABLE {$table} DISABLE TRIGGER USER");
    }

    DB::table('partner_stock_movements')->whereIn('partner_stock_item_id', $items)->delete();
    DB::table('stock_reservations')->where('id', '>', $this->watermarks['stock_reservations'])->delete();
    DB::table('partner_stock_items')->whereIn('id', $items)->delete();
    DB::table('order_item_allocations')->whereIn('order_id', $orders)->delete();
    DB::table('order_items')->whereIn('order_id', $orders)->delete();
    DB::table('order_status_history')->whereIn('order_id', $orders)->delete();
    DB::table('orders')->whereIn('id', $orders)->delete();
    DB::table('payments')->whereIn('id', $payments)->delete();
    DB::table('audit_logs')->where('id', '>', $this->watermarks['audit_logs'])->delete();

    foreach ($tables as $table) {
        DB::statement("ALTER TABLE {$table} ENABLE TRIGGER USER");
    }

    // Only a draft product may be deleted (P3-3); this one never went further
    // than the test, so it goes back to draft first.
    DB::table('products')->where('id', $this->product->id)->update(['status' => 'draft']);
    DB::table('products')->where('id', $this->product->id)->delete();
    DB::table('categories')->where('id', '>', $this->watermarks['categories'])->delete();
    DB::table('warehouses')->whereIn('id', [$this->tejgaon->id, $this->chattogram->id])->delete();
    DB::table('business_accounts')->where('id', $this->account->id)->delete();
    DB::table('users')->where('id', '>', $this->watermarks['users'])->delete();
});

/**
 * A paid wholesale purchase of `$units`, filled from `$warehouse`, credited to
 * the Partner -- the lot it opens.
 */
function partnerLotRaceBuy(Warehouse $warehouse, int $units): object
{
    $order = Order::factory()->create(['business_account_id' => test()->account->id, 'paid_at' => now()]);
    $unit = Money::fromDecimal('1500.00', Currency::BDT);
    $line = $order->items()->create([
        'line_number' => 1, 'product_id' => test()->product->id, 'sku' => test()->product->sku,
        'product_name' => test()->product->name, 'quantity' => $units, 'currency_code' => 'BDT',
        'unit_price' => $unit, 'line_subtotal' => $unit->multipliedBy($units), 'line_total' => $unit->multipliedBy($units),
    ]);

    OrderItemAllocation::create([
        'order_id' => $order->id, 'order_item_id' => $line->id, 'source_type' => AllocationSourceType::Warehouse,
        'warehouse_id' => $warehouse->id, 'quantity' => $units, 'currency_code' => 'BDT', 'status' => AllocationStatus::Active,
        'allocated_at' => now(), 'unit_cost' => Money::fromDecimal('1000.00', Currency::BDT), 'platform_rate' => $unit,
        'expected_margin' => Money::fromDecimal(bcmul('500.00', (string) $units, 2), Currency::BDT),
        'idempotency_key' => 'alloc:'.Str::ulid(),
    ]);

    app(ReceivePartnerPurchase::class)->handle($order->refresh());

    return DB::table('partner_stock_cost_lots')->where('source_order_item_id', $line->id)->sole();
}

/**
 * Run one piece of work in `$count` real processes, started together.
 */
function partnerLotRace(int $count, Closure $work): void
{
    DB::purge('lot_race');

    // A forked worker must not share the parent's Redis sockets (lock replies).
    foreach (array_keys(config('database.redis')) as $connection) {
        if (! in_array($connection, ['client', 'options', 'clusters'], true)) {
            Redis::purge($connection);
        }
    }

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
                // Losing a race is an expected outcome; the database says what happened.
            }

            posix_kill(posix_getpid(), SIGKILL);
        }

        $pids[] = $pid;
    }

    foreach ($pids as $pid) {
        pcntl_waitpid($pid, $status);
    }
}

it('never gives the same warehouse units to two racing holds, nor tops one up from another warehouse', function () {
    $account = $this->account;
    $product = $this->product;
    $warehouse = $this->tejgaon->id;

    // Both want 2 units from Tejgaon, which holds 2. The Partner holds 4 in all.
    partnerLotRace(2, function (int $worker) use ($account, $product, $warehouse) {
        app(PartnerLotWarehouseScope::class)->within($warehouse, fn () => app(StockReservations::class)->reserveFromPartner(
            $account, $product, null, 2, ReservationKind::CashOnDelivery, 'LOT-RACE-'.$worker.'-'.Str::upper(Str::random(6)),
        ));
    });

    $taken = fn (object $lot) => (int) -DB::table('partner_stock_lot_movements')
        ->where('partner_stock_cost_lot_id', $lot->id)->whereIn('type', ['allocated', 'released'])->sum('quantity');

    $tejgaon = DB::table('partner_stock_cost_lots')->where('id', $this->tejgaonLot->id)->sole();
    $chattogram = DB::table('partner_stock_cost_lots')->where('id', $this->chattogramLot->id)->sole();
    $holds = DB::table('stock_reservations')->where('id', '>', $this->watermarks['stock_reservations'])->whereNotNull('partner_stock_item_id')->count();

    expect($holds)->toBe(1)                                   // one hold won Tejgaon's units; the other was refused and rolled back
        ->and($taken($tejgaon))->toBe(2)                       // Tejgaon's 2 units went to exactly one of them
        ->and($tejgaon->remaining_quantity)->toBe(0)
        ->and($taken($chattogram))->toBe(0)                    // the loser was never topped up from Chattogram
        ->and($chattogram->remaining_quantity)->toBe(2);

    // The lots still hold exactly the Partner's available units.
    $available = (int) DB::table('partner_stock_items')->where('business_account_id', $this->account->id)->sum('available');
    $remaining = (int) DB::table('partner_stock_cost_lots')->where('business_account_id', $this->account->id)->sum('remaining_quantity');

    expect($remaining)->toBe($available);
})->group('concurrency');
