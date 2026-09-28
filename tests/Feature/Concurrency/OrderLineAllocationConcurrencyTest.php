<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Order\Actions\AllocateOrderLineSource;
use App\Domain\Order\Enums\AllocationSourceType;
use App\Domain\Order\Enums\AllocationStatus;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderItem;
use App\Domain\Order\Models\OrderItemAllocation;
use App\Domain\Supplier\Enums\PayableStatus;
use App\Domain\Supplier\Enums\SupplierStatus;
use App\Domain\Supplier\Models\Supplier;
use App\Domain\Supplier\Models\SupplierPayable;
use App\Models\User;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;

/*
 * Two staff members confirming a source for the same line at the same
 * moment (the allocation batch's own concurrency requirement). The per-line
 * DistributedLock (AllocateOrderLineSource::handle()) serialises the two
 * requests; the partial unique index on order_item_allocations is the
 * database's own, independent backstop if it ever did not. Real
 * pcntl_fork() workers, the same shape as SupplierWalletConcurrencyTest —
 * proven against a connection whose writes actually commit, not trusted at
 * design time.
 */
beforeEach(function () {
    config()->set('database.connections.allocation_race', config('database.connections.pgsql'));
    config()->set('database.default', 'allocation_race');

    // Seeded on this connection, not the RefreshDatabase-wrapped default:
    // a forked worker's fresh connection can only see what was actually
    // committed, never what sits inside another connection's open
    // transaction.
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->staff = testPlatformStaff(PlatformRole::Admin);
    $this->order = Order::factory()->create();
    $this->product = websiteTestProduct();
    $this->product->forceFill(['base_cost' => Money::fromDecimal('700.00', Currency::BDT)])->save();

    $this->line = OrderItem::create([
        'order_id' => $this->order->id,
        'line_number' => 1,
        'product_id' => $this->product->id,
        'sku' => $this->product->sku,
        'product_name' => $this->product->name,
        'quantity' => 2,
        'currency_code' => 'BDT',
        'unit_price' => Money::fromDecimal('1300.00', Currency::BDT),
        'line_subtotal' => Money::fromDecimal('2600.00', Currency::BDT),
        'line_total' => Money::fromDecimal('2600.00', Currency::BDT),
        'created_at' => now(),
    ]);

    $this->offerA = supplierTestOffer(Supplier::factory()->create(['status' => SupplierStatus::Approved]), $this->product, supplierRate: '900.00');
    $this->offerA->stock()->update(['quantity' => 10]);
    supplierTestOfferPriceVersion($this->offerA);

    $this->offerB = supplierTestOffer(Supplier::factory()->create(['status' => SupplierStatus::Approved]), $this->product, supplierRate: '950.00');
    $this->offerB->stock()->update(['quantity' => 10]);
    supplierTestOfferPriceVersion($this->offerB);
});

afterEach(function () {
    DB::statement('ALTER TABLE order_item_allocations DISABLE TRIGGER order_item_allocations_never_deleted');
    DB::statement('ALTER TABLE supplier_payables DISABLE TRIGGER supplier_payables_never_deleted');
    DB::statement('ALTER TABLE supplier_payable_status_history DISABLE TRIGGER supplier_payable_status_history_no_delete');

    DB::table('supplier_payable_status_history')
        ->whereIn('supplier_payable_id', DB::table('supplier_payables')->where('order_item_id', $this->line->id)->pluck('id'))
        ->delete();
    DB::table('supplier_payables')->where('order_item_id', $this->line->id)->delete();
    DB::table('order_item_allocations')->where('order_item_id', $this->line->id)->delete();

    // Orders and order lines are snapshots and are never deleted
    // (feriwala_order_item_is_a_snapshot, enforced at the database level),
    // and stock_reservations is kept pointed at by supplier_stock_movements
    // (RESTRICT) — all left behind, the same accepted trade-off already
    // made for other immutable fixture data in this project's race tests.

    DB::statement('ALTER TABLE supplier_payable_status_history ENABLE TRIGGER supplier_payable_status_history_no_delete');
    DB::statement('ALTER TABLE supplier_payables ENABLE TRIGGER supplier_payables_never_deleted');
    DB::statement('ALTER TABLE order_item_allocations ENABLE TRIGGER order_item_allocations_never_deleted');
});

/**
 * Run one piece of work in `$count` real processes, started together.
 */
function allocationRace(int $count, Closure $work): void
{
    DB::purge('allocation_race');

    $startAt = microtime(true) + 0.3;
    $pids = [];

    for ($worker = 0; $worker < $count; $worker++) {
        $pid = pcntl_fork();

        expect($pid)->not->toBe(-1);

        if ($pid === 0) {
            usleep((int) max(0, ($startAt - microtime(true)) * 1_000_000));

            try {
                $work($worker);
            } catch (Throwable) {
                // Losing the race is the expected outcome for one side of
                // this test. What happened is read back from the database
                // afterwards, never from an exit code.
            }

            posix_kill(posix_getpid(), SIGKILL);
        }

        $pids[] = $pid;
    }

    foreach ($pids as $pid) {
        pcntl_waitpid($pid, $status);
    }
}

it('never leaves two active allocations or two live payables when two staff confirm a source for the same line at once', function () {
    $lineId = $this->line->id;
    $offerAId = $this->offerA->public_id;
    $offerBId = $this->offerB->public_id;
    $staffId = $this->staff->id;

    allocationRace(2, function (int $worker) use ($lineId, $offerAId, $offerBId, $staffId) {
        /** @var OrderItem $line */
        $line = OrderItem::query()->findOrFail($lineId);
        /** @var User $staff */
        $staff = User::query()->findOrFail($staffId);

        app(AllocateOrderLineSource::class)->handle(
            $line,
            AllocationSourceType::SupplierOffer,
            $worker === 0 ? $offerAId : $offerBId,
            $staff,
            'Chosen by staff after comparing sources (race worker '.$worker.').',
        );
    });

    $active = OrderItemAllocation::query()
        ->where('order_item_id', $this->line->id)
        ->where('status', AllocationStatus::Active)
        ->get();

    // Never two — the per-line lock serialises the two requests, and the
    // partial unique index on order_item_allocations is the database's own
    // backstop if it ever did not.
    expect($active)->toHaveCount(1);

    $winner = $active->first();
    $winningOffer = $winner->supplier_offer_id === $this->offerA->id ? $this->offerA : $this->offerB;
    $losingOffer = $winningOffer->id === $this->offerA->id ? $this->offerB : $this->offerA;

    // Exactly one live claim, for whichever Supplier actually holds the
    // line — never two, never zero.
    $livePayables = SupplierPayable::query()
        ->where('order_item_id', $this->line->id)
        ->where('status', PayableStatus::Pending)
        ->get();

    expect($livePayables)->toHaveCount(1)
        ->and($livePayables->first()->supplier_offer_id)->toBe($winningOffer->id);

    // The loser's stock came all the way back; the winner's is held.
    expect($winningOffer->stock()->sole()->quantity)->toBe(8)
        ->and($winningOffer->stock()->sole()->reserved_quantity)->toBe(2)
        ->and($losingOffer->stock()->sole()->quantity)->toBe(10)
        ->and($losingOffer->stock()->sole()->reserved_quantity)->toBe(0);
})->group('slow');
