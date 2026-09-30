<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Order\Actions\AllocateOrderLineSource;
use App\Domain\Order\Enums\AllocationSourceType;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderItem;
use App\Domain\Supplier\Enums\OfferStatus;
use App\Domain\Supplier\Enums\SupplierStatus;
use App\Domain\Supplier\Enums\SupplyMode;
use App\Domain\Supplier\Models\Supplier;
use App\Domain\Supplier\Models\SupplierFulfilmentCommitment;
use App\Domain\Supplier\Models\SupplierOffer;
use App\Models\User;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;

/*
 * Two different order lines racing to allocate the same capacity-limited
 * on_demand offer (Supplier Bulk Product Listing batch, correction 7). Each
 * line has its own DistributedLock key, so -- unlike two staff confirming
 * the *same* line (OrderLineAllocationConcurrencyTest) -- the per-line lock
 * cannot serialise these two requests. What must serialise them instead is
 * RecordSupplierFulfilmentCommitment's own SupplierOffer row lock. Real
 * pcntl_fork() workers, the same shape as that test, proven against a
 * connection whose writes actually commit.
 */
beforeEach(function () {
    // Reuses the same connection name allocationRace() (defined in
    // OrderLineAllocationConcurrencyTest.php, this project's one shared
    // race-runner) purges before forking -- a different name here would
    // leave that purge silently targeting the wrong connection.
    config()->set('database.connections.allocation_race', config('database.connections.pgsql'));
    config()->set('database.default', 'allocation_race');

    $this->seed(RolesAndPermissionsSeeder::class);

    $this->staff = testPlatformStaff(PlatformRole::Admin);
    $this->product = websiteTestProduct();
    $supplier = Supplier::factory()->create(['status' => SupplierStatus::Approved]);

    $this->offer = SupplierOffer::create([
        'supplier_id' => $supplier->id,
        'product_id' => $this->product->id,
        'status' => OfferStatus::Active,
        'supplier_rate' => Money::fromDecimal('900.00', Currency::BDT),
        'platform_rate' => Money::fromDecimal('1300.00', Currency::BDT),
        'currency_code' => 'BDT',
        'activated_at' => now(),
        'supply_mode' => SupplyMode::OnDemand->value,
        'fulfilment_capacity' => 2,
        'lead_time_days' => 3,
    ]);
    supplierTestOfferPriceVersion($this->offer);

    $this->lines = collect(range(1, 3))->map(fn () => OrderItem::create([
        'order_id' => Order::factory()->create()->id,
        'line_number' => 1,
        'product_id' => $this->product->id,
        'sku' => $this->product->sku,
        'product_name' => $this->product->name,
        'quantity' => 1,
        'currency_code' => 'BDT',
        'unit_price' => Money::fromDecimal('1300.00', Currency::BDT),
        'line_subtotal' => Money::fromDecimal('1300.00', Currency::BDT),
        'line_total' => Money::fromDecimal('1300.00', Currency::BDT),
        'created_at' => now(),
    ]))->values();
});

afterEach(function () {
    DB::statement('ALTER TABLE order_item_allocations DISABLE TRIGGER order_item_allocations_never_deleted');
    DB::statement('ALTER TABLE supplier_payables DISABLE TRIGGER supplier_payables_never_deleted');
    DB::statement('ALTER TABLE supplier_payable_status_history DISABLE TRIGGER supplier_payable_status_history_no_delete');
    DB::statement('ALTER TABLE supplier_fulfilment_commitments DISABLE TRIGGER supplier_fulfilment_commitments_never_deleted');
    DB::statement('ALTER TABLE supplier_fulfilment_commitment_status_history DISABLE TRIGGER supplier_fulfilment_commitment_status_history_no_delete');

    $lineIds = $this->lines->pluck('id');
    $allocationIds = DB::table('order_item_allocations')->whereIn('order_item_id', $lineIds)->pluck('id');

    DB::table('supplier_fulfilment_commitment_status_history')
        ->whereIn('supplier_fulfilment_commitment_id', DB::table('supplier_fulfilment_commitments')->whereIn('order_item_allocation_id', $allocationIds)->pluck('id'))
        ->delete();
    DB::table('supplier_fulfilment_commitments')->whereIn('order_item_allocation_id', $allocationIds)->delete();
    DB::table('supplier_payable_status_history')
        ->whereIn('supplier_payable_id', DB::table('supplier_payables')->whereIn('order_item_id', $lineIds)->pluck('id'))
        ->delete();
    DB::table('supplier_payables')->whereIn('order_item_id', $lineIds)->delete();
    DB::table('order_item_allocations')->whereIn('order_item_id', $lineIds)->delete();

    DB::statement('ALTER TABLE supplier_fulfilment_commitment_status_history ENABLE TRIGGER supplier_fulfilment_commitment_status_history_no_delete');
    DB::statement('ALTER TABLE supplier_fulfilment_commitments ENABLE TRIGGER supplier_fulfilment_commitments_never_deleted');
    DB::statement('ALTER TABLE supplier_payables ENABLE TRIGGER supplier_payables_never_deleted');
    DB::statement('ALTER TABLE order_item_allocations ENABLE TRIGGER order_item_allocations_never_deleted');
});

/**
 * Run one piece of work in `$count` real processes, started together.
 *
 * A local copy of OrderLineAllocationConcurrencyTest.php's own
 * `allocationRace()` -- Pest's shared global namespace only actually shares
 * a helper when both files happen to run in the same process, which is not
 * something a single-file or filtered run can rely on. Prefixed for the
 * same reason every helper in this suite is (tests/Pest.php).
 */
function fulfilmentCommitmentConcurrencyRace(int $count, Closure $work): void
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
                // Losing the race is the expected outcome for one or more
                // workers. What happened is read back from the database
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

it('admits only as many concurrent commitments as the offer\'s capacity allows', function () {
    $lineIds = $this->lines->pluck('id')->all();
    $offerId = $this->offer->public_id;
    $staffId = $this->staff->id;

    fulfilmentCommitmentConcurrencyRace(3, function (int $worker) use ($lineIds, $offerId, $staffId) {
        /** @var OrderItem $line */
        $line = OrderItem::query()->findOrFail($lineIds[$worker]);
        /** @var User $staff */
        $staff = User::query()->findOrFail($staffId);

        try {
            app(AllocateOrderLineSource::class)->handle(
                $line,
                AllocationSourceType::SupplierOffer,
                $offerId,
                $staff,
                'Race worker '.$worker.' against a capacity of 2.',
            );
        } catch (\Throwable $e) {
            file_put_contents('/tmp/race-debug.log', "worker {$worker}: ".get_class($e).' '.$e->getMessage()."\n", FILE_APPEND);

            throw $e;
        }
    });

    // The offer declared capacity for 2 -- exactly 2 of the 3 racing lines
    // may hold a non-terminal commitment against it, whichever two won.
    $commitments = SupplierFulfilmentCommitment::query()
        ->where('supplier_offer_id', $this->offer->id)
        ->get();

    expect($commitments)->toHaveCount(2)
        ->and($commitments->sum('quantity'))->toBe(2);
})->group('slow');
