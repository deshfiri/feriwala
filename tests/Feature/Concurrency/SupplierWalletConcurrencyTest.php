<?php

use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Order\Enums\OrderSource;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Models\Order;
use App\Domain\Supplier\Actions\AdvanceSupplierWithdrawalStatus;
use App\Domain\Supplier\Actions\OpenSupplierWallet;
use App\Domain\Supplier\Actions\RejectOrFailSupplierWithdrawal;
use App\Domain\Supplier\Actions\RequestSupplierWithdrawal;
use App\Domain\Supplier\Actions\SettleSupplierPayable;
use App\Domain\Supplier\Data\SupplierPostingContext;
use App\Domain\Supplier\Enums\PayableStatus;
use App\Domain\Supplier\Enums\SupplierPayoutMethodStatus;
use App\Domain\Supplier\Enums\SupplierPayoutMethodType;
use App\Domain\Supplier\Enums\SupplierWithdrawalStatus;
use App\Domain\Supplier\Models\Supplier;
use App\Domain\Supplier\Models\SupplierLedgerEntry;
use App\Domain\Supplier\Models\SupplierPayable;
use App\Domain\Supplier\Models\SupplierPayoutMethod;
use App\Domain\Supplier\Models\SupplierWallet;
use App\Domain\Supplier\Models\SupplierWithdrawal;
use App\Domain\Supplier\SupplierWalletService;
use App\Models\User;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Illuminate\Support\Facades\DB;

/*
 * P13-23/P13-24/P13-25's four required real-process concurrency proofs, in
 * the same shape as {@see WalletConcurrencyTest}: real `pcntl_fork()`
 * workers racing for the same row, on a connection whose writes actually
 * commit, asked afterward what happened rather than trusted at design time.
 *
 * One of the four (settling the same payable) needs a real SupplierPayable
 * — {@see SettleSupplierPayable} locks that row itself, so the race has to
 * be over it. Its Order/OrderItem are built directly rather than through
 * the full storefront checkout pipeline (no website, payment gateway or
 * webhook needed to prove a payable's own row lock). The other three race
 * directly at {@see SupplierWalletService} and the withdrawal actions,
 * which is where the row locks that matter actually live.
 */
beforeEach(function () {
    config()->set('database.connections.supplier_wallet_race', config('database.connections.pgsql'));
    config()->set('database.default', 'supplier_wallet_race');

    $this->supplier = Supplier::factory()->create();
    $this->wallet = app(OpenSupplierWallet::class)->handle($this->supplier, Currency::BDT);
});

afterEach(function () {
    DB::statement('ALTER TABLE supplier_ledger_entries DISABLE TRIGGER supplier_ledger_entries_no_delete');
    DB::statement('ALTER TABLE supplier_withdrawal_status_history DISABLE TRIGGER supplier_withdrawal_status_history_no_delete');
    DB::statement('ALTER TABLE supplier_withdrawals DISABLE TRIGGER supplier_withdrawals_never_deleted');
    DB::statement('ALTER TABLE supplier_payout_methods DISABLE TRIGGER supplier_payout_methods_never_deleted');

    DB::table('supplier_ledger_entries')->where('supplier_wallet_id', $this->wallet->id)->delete();
    DB::table('supplier_withdrawal_status_history')
        ->whereIn('supplier_withdrawal_id', DB::table('supplier_withdrawals')->where('supplier_wallet_id', $this->wallet->id)->pluck('id'))
        ->delete();
    DB::table('supplier_withdrawals')->where('supplier_wallet_id', $this->wallet->id)->delete();
    DB::table('supplier_payout_methods')->where('supplier_id', $this->supplier->id)->delete();
    DB::table('supplier_wallets')->where('id', $this->wallet->id)->delete();

    // Suppliers are never deleted, only closed (feriwala_suppliers_are_never_deleted)
    // — this fixture row, and the Order/OrderItem/SupplierPayable the first
    // test below builds, are left behind in the isolated testing_supplier
    // schema, the same accepted trade-off already made for other immutable
    // fixture data in this project's browser/testing schemas.

    DB::statement('ALTER TABLE supplier_payout_methods ENABLE TRIGGER supplier_payout_methods_never_deleted');
    DB::statement('ALTER TABLE supplier_withdrawals ENABLE TRIGGER supplier_withdrawals_never_deleted');
    DB::statement('ALTER TABLE supplier_withdrawal_status_history ENABLE TRIGGER supplier_withdrawal_status_history_no_delete');
    DB::statement('ALTER TABLE supplier_ledger_entries ENABLE TRIGGER supplier_ledger_entries_no_delete');
});

/**
 * Run one piece of work in `$count` real processes, started together.
 */
function supplierWalletRace(int $count, Closure $work): void
{
    DB::purge('supplier_wallet_race');

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
                // every test here. What happened is read back from the
                // database afterwards, never from an exit code.
            }

            posix_kill(posix_getpid(), SIGKILL);
        }

        $pids[] = $pid;
    }

    foreach ($pids as $pid) {
        pcntl_waitpid($pid, $status);
    }
}

function supplierWalletRaceWallet(): SupplierWallet
{
    /** @var SupplierWallet $wallet */
    $wallet = SupplierWallet::query()->findOrFail(test()->wallet->id);

    return $wallet;
}

function supplierWalletRacePayoutMethod(): SupplierPayoutMethod
{
    return SupplierPayoutMethod::create([
        'supplier_id' => test()->supplier->id,
        'type' => SupplierPayoutMethodType::Bkash,
        'label' => 'Race fixture',
        'details' => ['account_name' => 'Race', 'account_number' => '01711112222'],
        'last_four' => '2222',
        'is_default' => true,
        'status' => SupplierPayoutMethodStatus::Active,
    ]);
}

it('settles a payable exactly once when two workers race to settle it', function () {
    $businessAccount = BusinessAccount::factory()->create();

    $offer = supplierTestOffer($this->supplier, supplierRate: 100000, platformRate: 130000, preferred: true);
    $priceChange = supplierTestOfferPriceVersion($offer);

    $order = Order::create([
        'source' => OrderSource::ManualEntry,
        'status' => OrderStatus::Confirmed->value,
        'business_account_id' => $businessAccount->id,
        'customer' => ['contact_name' => 'Race fixture', 'email' => 'race@test.example', 'mobile' => '01700000000'],
        'currency_code' => 'BDT',
        'subtotal_minor' => 130000,
        'total_minor' => 130000,
        'placed_at' => now(),
    ]);

    $item = $order->items()->create([
        'line_number' => 1,
        'product_id' => $offer->product_id,
        'sku' => 'RACE-SKU-1',
        'product_name' => 'Race fixture product',
        'quantity' => 1,
        'currency_code' => 'BDT',
        'unit_price_minor' => $priceChange->platform_rate_minor,
        'line_subtotal_minor' => $priceChange->platform_rate_minor,
        'line_total_minor' => $priceChange->platform_rate_minor,
        'supplier_id' => $offer->supplier_id,
        'supplier_offer_id' => $offer->id,
        'supplier_offer_price_change_id' => $priceChange->id,
        'supplier_rate_minor' => $priceChange->supplier_rate_minor,
        'platform_rate_minor' => $priceChange->platform_rate_minor,
        'platform_margin_minor' => $priceChange->platform_rate_minor->minus($priceChange->supplier_rate_minor),
        'supplier_currency_code' => 'BDT',
        'supplier_allocated_quantity' => 1,
        'supplier_allocated_at' => now(),
    ]);

    $payable = SupplierPayable::create([
        'supplier_id' => $offer->supplier_id,
        'order_id' => $order->id,
        'order_item_id' => $item->id,
        'supplier_offer_id' => $offer->id,
        'supplier_offer_price_change_id' => $priceChange->id,
        'quantity' => 1,
        'supplier_rate_minor' => $priceChange->supplier_rate_minor,
        'gross_amount_minor' => $priceChange->supplier_rate_minor,
        'currency_code' => 'BDT',
        'status' => PayableStatus::Eligible,
        'triggering_event' => 'order_placed',
        'idempotency_key' => 'race:payable:'.$item->id,
        'delivered_at' => now(),
        'payment_settled_at' => now(),
        'eligible_at' => now(),
    ]);

    $manager = User::factory()->create();

    supplierWalletRace(2, function () use ($payable, $manager) {
        app(SettleSupplierPayable::class)->handle($payable->fresh(), $manager->id);
    });

    $wallet = supplierWalletRaceWallet();

    expect($wallet->total_minor->minorUnits)->toBe(100000)
        ->and(SupplierLedgerEntry::query()->where('supplier_wallet_id', $wallet->id)->count())->toBe(1)
        ->and($payable->fresh()->settled_at)->not->toBeNull();
})->group('slow');

it('reserves only what is available when two withdrawals compete for the same balance', function () {
    app(SupplierWalletService::class)->credit(
        supplierWalletRaceWallet(),
        Money::of(100000, Currency::BDT),
        new SupplierPostingContext(source: 'test', description: 'Opening'),
    );

    $method = supplierWalletRacePayoutMethod();
    $supplier = $this->supplier;

    supplierWalletRace(2, function (int $worker) use ($supplier, $method) {
        app(RequestSupplierWithdrawal::class)->handle(
            $supplier->fresh(),
            $method->fresh(),
            Money::of(70000, Currency::BDT),
            'race:withdrawal:'.$worker,
        );
    });

    $wallet = supplierWalletRaceWallet();

    // Only one of the two 70000 requests could fit inside 100000 available.
    expect($wallet->reserved_minor->minorUnits)->toBe(70000)
        ->and($wallet->availableBalance()->minorUnits)->toBe(30000)
        ->and(SupplierWithdrawal::query()->where('supplier_wallet_id', $wallet->id)->count())->toBe(1);
});

it('never double-decides a withdrawal when approval races rejection', function () {
    app(SupplierWalletService::class)->credit(
        supplierWalletRaceWallet(),
        Money::of(100000, Currency::BDT),
        new SupplierPostingContext(source: 'test', description: 'Opening'),
    );

    $method = supplierWalletRacePayoutMethod();

    $withdrawal = app(RequestSupplierWithdrawal::class)->handle(
        $this->supplier->fresh(),
        $method,
        Money::of(50000, Currency::BDT),
        'race:withdrawal:decision',
    );

    $staff = User::factory()->create();
    $withdrawal = app(AdvanceSupplierWithdrawalStatus::class)->handle($withdrawal, SupplierWithdrawalStatus::UnderReview, $staff->id);

    supplierWalletRace(2, function (int $worker) use ($withdrawal, $staff) {
        if ($worker === 0) {
            app(AdvanceSupplierWithdrawalStatus::class)->handle($withdrawal->fresh(), SupplierWithdrawalStatus::Approved, $staff->id);
        } else {
            app(RejectOrFailSupplierWithdrawal::class)->handle($withdrawal->fresh(), SupplierWithdrawalStatus::Rejected, 'Race fixture rejection.', $staff->id);
        }
    });

    $fresh = $withdrawal->fresh();
    $wallet = supplierWalletRaceWallet();

    // Exactly one of the two decisions won. Whichever it was, the reserved
    // bucket agrees with it: still held for Approved, given back for
    // Rejected — never both, never neither.
    expect(in_array($fresh->status, [SupplierWithdrawalStatus::Approved, SupplierWithdrawalStatus::Rejected], true))->toBeTrue();

    if ($fresh->status === SupplierWithdrawalStatus::Approved) {
        expect($wallet->reserved_minor->minorUnits)->toBe(50000);
    } else {
        expect($wallet->reserved_minor->minorUnits)->toBe(0);
    }

    // Requested -> UnderReview -> (Approved | Rejected): three rows, never four.
    expect($fresh->statusHistory()->count())->toBe(3);
});

it('never lets a payable reversal and a withdrawal reservation together overdraw the wallet', function () {
    app(SupplierWalletService::class)->credit(
        supplierWalletRaceWallet(),
        Money::of(100000, Currency::BDT),
        new SupplierPostingContext(source: 'test', description: 'Opening'),
    );

    $wallets = app(SupplierWalletService::class);

    supplierWalletRace(2, function (int $worker) use ($wallets) {
        if ($worker === 0) {
            // Stands in for the settlement-side clawback ReverseSupplierPayable
            // performs: debits whatever is available, records the rest as
            // recovery. Same wallet row lock, same method, no payable needed
            // to prove it serialises against a competing reservation.
            $wallets->debitForReversal(
                supplierWalletRaceWallet(),
                Money::of(70000, Currency::BDT),
                new SupplierPostingContext(source: 'test', description: 'Reversal', reason: 'Race fixture return.'),
            );
        } else {
            $wallets->reserve(
                supplierWalletRaceWallet(),
                Money::of(70000, Currency::BDT),
                new SupplierPostingContext(source: 'test', description: 'Withdrawal reservation'),
            );
        }
    });

    $wallet = supplierWalletRaceWallet();

    // Whichever ran first took the full 70000 from the 100000 available;
    // the other found only 30000 left. A reversal always "succeeds" in the
    // sense of recording *something* (debit, or recovery, or both), so what
    // proves the lock held is that the two effects never overlap: the
    // wallet's own invariants (reserved <= total, everything non-negative)
    // are enforced by CHECK constraints that a race without the lock would
    // have tripped by now, and the ledger explains every minor unit moved.
    expect($wallet->reserved_minor->minorUnits)->toBeLessThanOrEqual($wallet->total_minor->minorUnits)
        ->and($wallet->total_minor->minorUnits)->toBeGreaterThanOrEqual(0)
        ->and($wallet->recovery_minor->minorUnits)->toBeGreaterThanOrEqual(0);

    $entries = SupplierLedgerEntry::query()->where('supplier_wallet_id', $wallet->id)->orderBy('id')->get();

    /*
     * Opening credit, plus one entry for whichever worker ran first, plus a
     * third only if the second worker's operation still found something to
     * do: `debitForReversal()` always posts (it caps itself at whatever is
     * available, even zero), but `reserve()` throws and posts nothing at
     * all once the first worker has already taken the balance it needed —
     * so 2 or 3 entries are both a correct outcome of a genuine race, and
     * what actually matters is asserted below: the chain never breaks.
     */
    expect($entries->count())->toBeGreaterThanOrEqual(2)->toBeLessThanOrEqual(3);

    foreach ($entries as $index => $entry) {
        if ($index === 0) {
            continue;
        }

        $previous = $entries[$index - 1];
        expect($entry->balance_before_minor->minorUnits)->toBe($previous->balance_after_minor->minorUnits)
            ->and($entry->reserved_before_minor->minorUnits)->toBe($previous->reserved_after_minor->minorUnits)
            ->and($entry->recovery_before_minor->minorUnits)->toBe($previous->recovery_after_minor->minorUnits);
    }
});
