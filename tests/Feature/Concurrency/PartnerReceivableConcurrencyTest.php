<?php

use App\Domain\Billing\Events\PartnerGatewayPaymentVerified;
use App\Domain\Billing\Jobs\SettleGatewayNotification;
use App\Domain\Billing\Models\Payment;
use App\Domain\Inventory\Actions\ReleaseExpiredReservations;
use App\Domain\Inventory\Enums\ReservationKind;
use App\Domain\Inventory\Enums\StockReservationStatus;
use App\Domain\Inventory\Models\StockItem;
use App\Domain\Inventory\Models\StockReservation;
use App\Domain\Inventory\StockReservations;
use App\Domain\Order\Actions\CollectPartnerReceivable;
use App\Domain\Order\Actions\InitiateOrderPayment;
use App\Domain\Order\Actions\OpenReceivableCollection;
use App\Domain\Order\Actions\ReleaseCollectableReceivables;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Enums\ReceivableCollectionStatus;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderHoldReservation;
use App\Domain\Order\Models\OrderPlatformReceivable;
use App\Domain\Order\Models\OrderReceivableCollection;
use App\Domain\Payout\Actions\SavePayoutMethod;
use App\Domain\Payout\Enums\PayoutMethodType;
use App\Domain\Payout\Enums\PayoutOwnerType;
use App\Domain\Wallet\Actions\OpenWallet;
use App\Domain\Wallet\Actions\VerifyLedgerIntegrity;
use App\Domain\Wallet\Data\PostingContext;
use App\Domain\Wallet\Enums\LedgerTransactionType;
use App\Domain\Wallet\Events\WalletCredited;
use App\Domain\Wallet\Models\LedgerEntry;
use App\Domain\Wallet\Models\Wallet;
use App\Domain\Wallet\WalletService;
use App\Domain\Website\Models\WebsiteProduct;
use App\Domain\Withdrawal\Actions\RequestAccountWithdrawal;
use App\Domain\Withdrawal\Models\AccountWithdrawal;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

/*
 * Real-process races for collecting Banij's share of a Partner-gateway order from
 * the Partner's Wallet (D36): workers forked to race for the same rows on a
 * connection whose writes actually commit, then asked afterwards what happened —
 * the same shape as the other concurrency tests.
 *
 * The listeners are faked, so nothing collects until a worker does: each worker
 * stands for one of the triggers (a verification event, a top-up, the deadline
 * sweep, a withdrawal request, the reservation expiry sweep, a competing order).
 *
 * However they interleave, every race must end with: at most one collection and
 * one Wallet debit, no negative balance, no partial debit, a balanced ledger, no
 * order moved twice, no second reservation for the same units, and stock that
 * adds up.
 */
beforeEach(function () {
    config()->set('database.connections.partner_receivable_race', config('database.connections.pgsql'));
    config()->set('database.default', 'partner_receivable_race');

    partnerCheckoutWorld();
    Event::fake([PartnerGatewayPaymentVerified::class, WalletCredited::class]);
});

afterEach(function () {
    // What the race committed outlives the test. The ledger, the settlements, the
    // collections and their links refuse deletion by design, and this is the one
    // place allowed to reach past that.
    $guards = [
        ['ledger_entries', 'ledger_entries_no_delete'],
        ['wallet_transaction_events', 'wallet_transaction_events_no_delete'],
        ['order_proceeds_settlements', 'order_proceeds_settlements_kept'],
        ['order_receivable_collections', 'order_receivable_collections_no_delete'],
        ['order_hold_reservations', 'order_hold_reservations_no_delete'],
        ['account_withdrawal_status_history', 'account_withdrawal_status_history_no_delete'],
        ['account_withdrawals', 'account_withdrawals_never_deleted'],
        ['payout_methods', 'payout_methods_never_deleted'],
    ];

    foreach ($guards as [$table, $trigger]) {
        DB::statement("ALTER TABLE {$table} DISABLE TRIGGER {$trigger}");
    }

    $wallets = DB::table('wallets')->where('business_account_id', $this->account->id)->pluck('id');
    $transactions = DB::table('wallet_transactions')->whereIn('wallet_id', $wallets)->pluck('id');
    $withdrawals = DB::table('account_withdrawals')->whereIn('wallet_id', $wallets)->pluck('id');
    $collections = DB::table('order_receivable_collections')->where('business_account_id', $this->account->id)->pluck('id');

    DB::table('order_hold_reservations')->whereIn('order_receivable_collection_id', $collections)->delete();
    DB::table('order_receivable_collections')->whereIn('id', $collections)->delete();
    DB::table('order_proceeds_settlements')->where('business_account_id', $this->account->id)->delete();
    DB::table('wallet_transaction_events')->whereIn('wallet_transaction_id', $transactions)->delete();
    DB::table('ledger_entries')->whereIn('wallet_id', $wallets)->delete();
    DB::table('account_withdrawal_status_history')->whereIn('account_withdrawal_id', $withdrawals)->delete();
    DB::table('account_withdrawals')->whereIn('id', $withdrawals)->delete();
    DB::table('wallet_transactions')->whereIn('id', $transactions)->delete();
    DB::table('payout_methods')->where('owner_type', PayoutOwnerType::BusinessAccount->value)->where('owner_id', $this->account->id)->delete();
    DB::table('wallets')->whereIn('id', $wallets)->delete();

    foreach (array_reverse($guards) as [$table, $trigger]) {
        DB::statement("ALTER TABLE {$table} ENABLE TRIGGER {$trigger}");
    }
});

/**
 * Run one piece of work in `$count` real processes, started together.
 *
 * @param  Closure(int): void  $work
 */
function partnerReceivableRace(int $count, Closure $work): void
{
    DB::purge('partner_receivable_race');

    // A forked worker that kept the parent's Redis socket would read other workers'
    // replies, and a lock it never saw granted would be held until it expired.
    foreach (array_keys(array_diff_key((array) config('database.redis'), array_flip(['client', 'options', 'clusters']))) as $connection) {
        Redis::purge((string) $connection);
    }

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
                // Losing the race is an expected outcome for one side of these.
                // What happened is read back from the database afterwards.
            }

            posix_kill(posix_getpid(), SIGKILL);
        }

        $pids[] = $pid;
    }

    foreach ($pids as $pid) {
        pcntl_waitpid($pid, $status);
    }
}

function partnerReceivableMoney(string $amount): Money
{
    return Money::fromDecimal($amount, Currency::BDT);
}

/**
 * A Partner order paid at the provider and reported, with nothing yet collected: the
 * listeners are faked, so a worker has to do it.
 *
 * @param  list<array{0: WebsiteProduct, 1: int}>|null  $lines
 * @return array{0: Order, 1: Payment, 2: Money} the order, its payment, and what Banij is owed
 */
function partnerReceivableOrder(?array $lines = null, ?Closure $beforeNotice = null): array
{
    $order = partnerCheckoutPlace(lines: $lines);
    app(InitiateOrderPayment::class)->handle($order);
    $payment = $order->payment()->first();
    $valId = partnerCheckoutPays($payment);

    if ($beforeNotice !== null) {
        $beforeNotice($order);
    }

    SettleGatewayNotification::dispatchSync($payment->id, $valId, 'ipn');

    $owed = OrderPlatformReceivable::query()->where('order_id', $order->id)->firstOrFail()->total;

    return [Order::query()->findOrFail($order->id), Payment::query()->findOrFail($payment->id), $owed];
}

function partnerReceivableWallet(): Wallet
{
    return Wallet::query()->where('business_account_id', test()->account->id)->first()
        ?? app(OpenWallet::class)->handle(test()->account);
}

/**
 * Money arriving the way it does: a top-up is platform credit, a sale's earning is withdrawable.
 */
function partnerReceivableCredit(string $platformCredit, string $withdrawable = '0.00'): void
{
    foreach ([[LedgerTransactionType::TopUpCredit, $platformCredit], [LedgerTransactionType::SalesCredit, $withdrawable]] as [$type, $amount]) {
        if (partnerReceivableMoney($amount)->isPositive()) {
            app(WalletService::class)->credit(partnerReceivableWallet(), $type, partnerReceivableMoney($amount), new PostingContext(
                source: 'test',
                description: 'Race credit',
                idempotencyKey: 'partner-receivable-race:'.Str::random(16),
            ));
        }
    }
}

function partnerReceivableCollection(Order $order): OrderReceivableCollection
{
    return OrderReceivableCollection::query()->where('order_id', $order->id)->firstOrFail();
}

function partnerReceivableDebits(): int
{
    return LedgerEntry::query()->where('type', LedgerTransactionType::PartnerGatewayReceivableDebit->value)->count();
}

/**
 * What must be true after any race: nothing negative, nothing unbalanced, nothing
 * taken twice, and no stock held twice.
 */
function partnerReceivableInvariants(): void
{
    $wallet = Wallet::query()->where('business_account_id', test()->account->id)->firstOrFail();
    $collected = OrderReceivableCollection::query()->where('status', ReceivableCollectionStatus::Collected->value)->get();

    expect($wallet->total->isNegative())->toBeFalse()
        ->and($wallet->usableBalance()->isNegative())->toBeFalse()
        ->and(app(VerifyLedgerIntegrity::class)->handle()['mismatched'])->toBe(0)
        // One collection, one debit: the wallet transaction is the collection's own, and unique.
        ->and(DB::table('wallet_transactions')->where('type', LedgerTransactionType::PartnerGatewayReceivableDebit->value)->count())
        ->toBe($collected->count())
        ->and($collected->pluck('wallet_transaction_id')->unique()->count())->toBe($collected->count());

    foreach ($collected as $collection) {
        // The whole amount and nothing else, as one posting.
        $debited = LedgerEntry::query()->where('wallet_transaction_id', $collection->wallet_transaction_id)->get()
            ->reduce(fn (Money $sum, LedgerEntry $entry) => $sum->plus($entry->debit), Money::zero(Currency::BDT));

        expect($debited->equals($collection->amount))->toBeTrue();
    }

    // No units reserved twice: whatever is reserved belongs to an order still waiting for money.
    $reserved = (int) StockReservation::query()->where('status', StockReservationStatus::Active->value)->sum('quantity');
    $stock = StockItem::query()->where('product_id', test()->product->id)->firstOrFail();

    expect($stock->reserved)->toBe($reserved)
        ->and($stock->available)->toBeGreaterThanOrEqual(0)
        ->and($stock->available + $stock->reserved + $stock->processing)->toBe(50);
}

it('collects once when a verification event and a top-up arrive together', function () {
    [$order, $payment, $owed] = partnerReceivableOrder();
    partnerReceivableCredit('0.00', '1.00');

    partnerReceivableRace(6, function (int $worker) use ($payment, $owed) {
        if ($worker % 2 === 0) {
            app(ReleaseCollectableReceivables::class)->forPayment(Payment::query()->findOrFail($payment->id));

            return;
        }

        partnerReceivableCredit($owed->toDecimal());
        app(ReleaseCollectableReceivables::class)->forAccount($this->account->id);
    });

    // Either order of arrival ends the same way, once the money is in: collected, once.
    app(ReleaseCollectableReceivables::class)->forAccount($this->account->id);

    expect(partnerReceivableCollection($order)->status)->toBe(ReceivableCollectionStatus::Collected)
        ->and(partnerReceivableDebits())->toBeGreaterThanOrEqual(1)
        ->and(Order::query()->findOrFail($order->id)->status)->toBe(OrderStatus::Paid);

    partnerReceivableInvariants();
});

it('collects once when the same verification is announced from several workers', function () {
    [$order, $payment] = partnerReceivableOrder();
    partnerReceivableCredit('0.00', '20000.00');

    partnerReceivableRace(6, fn () => app(ReleaseCollectableReceivables::class)->forPayment(Payment::query()->findOrFail($payment->id)));

    expect(OrderReceivableCollection::query()->where('order_id', $order->id)->count())->toBe(1)
        ->and(partnerReceivableCollection($order)->status)->toBe(ReceivableCollectionStatus::Collected)
        ->and(partnerReceivableDebits())->toBeGreaterThanOrEqual(1)
        ->and(DB::table('wallet_transactions')->where('type', LedgerTransactionType::PartnerGatewayReceivableDebit->value)->count())->toBe(1)
        ->and(Order::query()->findOrFail($order->id)->status)->toBe(OrderStatus::Paid)
        // Placed, then paid: the move to paid was made once, not once per worker.
        ->and(DB::table('order_status_history')->where('order_id', $order->id)->count())->toBeLessThanOrEqual(2);

    partnerReceivableInvariants();
});

it('collects once when two workers attempt the same collection', function () {
    [$order, $payment] = partnerReceivableOrder();
    partnerReceivableCredit('0.00', '20000.00');
    $collection = app(OpenReceivableCollection::class)->handle($payment);

    partnerReceivableRace(2, fn () => app(CollectPartnerReceivable::class)->attempt($collection->id));

    expect(partnerReceivableCollection($order)->status)->toBe(ReceivableCollectionStatus::Collected)
        ->and(DB::table('wallet_transactions')->where('type', LedgerTransactionType::PartnerGatewayReceivableDebit->value)->count())->toBe(1);

    partnerReceivableInvariants();
});

it('takes a mixed Wallet\'s platform credit first, once, however many workers collect', function () {
    [$order, $payment, $owed] = partnerReceivableOrder();
    partnerReceivableCredit('1000.00', $owed->toDecimal());
    $collection = app(OpenReceivableCollection::class)->handle($payment);

    partnerReceivableRace(5, fn () => app(CollectPartnerReceivable::class)->attempt($collection->id));

    $entries = LedgerEntry::query()->where('type', LedgerTransactionType::PartnerGatewayReceivableDebit->value)->orderBy('id')->get();

    expect(partnerReceivableCollection($order)->status)->toBe(ReceivableCollectionStatus::Collected)
        ->and($entries)->toHaveCount(2)
        ->and($entries[0]->debit->toDecimal())->toBe('1000.00')
        ->and($entries[0]->balance_type->value)->toBe('platform_credit')
        ->and($entries[1]->balance_type->value)->toBe('withdrawable');

    partnerReceivableInvariants();
});

it('debits nothing, however many workers try, when the Wallet cannot cover the whole amount', function () {
    [$order, $payment] = partnerReceivableOrder();
    partnerReceivableCredit('0.00', '100.00');
    $collection = app(OpenReceivableCollection::class)->handle($payment);

    partnerReceivableRace(6, fn () => app(CollectPartnerReceivable::class)->attempt($collection->id));

    $moves = DB::table('order_status_history')->where('order_id', $order->id)->count();

    expect(partnerReceivableCollection($order)->status)->toBe(ReceivableCollectionStatus::WalletInsufficient)
        ->and(partnerReceivableDebits())->toBe(0)
        ->and(Wallet::query()->where('business_account_id', $this->account->id)->firstOrFail()->total->toDecimal())->toBe('100.00')
        ->and(Order::query()->findOrFail($order->id)->status)->toBe(OrderStatus::OnHold)
        // Placed, then held once — not held again by every worker.
        ->and($moves)->toBeLessThanOrEqual(2);

    partnerReceivableInvariants();
});

it('collects once when several top-ups arrive together', function () {
    [$order, $payment, $owed] = partnerReceivableOrder();
    partnerReceivableCredit('0.00', '1.00');
    app(ReleaseCollectableReceivables::class)->forPayment($payment);

    // Each top-up is a third of what is owed: no single one is enough, all of them together are.
    $third = $owed->multipliedBy('0.34');

    partnerReceivableRace(4, function () use ($third) {
        partnerReceivableCredit($third->plus(partnerReceivableMoney('1.00'))->toDecimal());
        app(ReleaseCollectableReceivables::class)->forAccount(test()->account->id);
    });

    app(ReleaseCollectableReceivables::class)->forAccount($this->account->id);

    expect(partnerReceivableCollection($order)->status)->toBe(ReceivableCollectionStatus::Collected)
        ->and(DB::table('wallet_transactions')->where('type', LedgerTransactionType::PartnerGatewayReceivableDebit->value)->count())->toBe(1);

    partnerReceivableInvariants();
});

it('never lets a withdrawal take money a live collection is owed, even racing the top-up that pays it', function () {
    [$order, $payment, $owed] = partnerReceivableOrder();
    partnerReceivableCredit('0.00', '700.00');
    app(ReleaseCollectableReceivables::class)->forPayment($payment);

    $method = app(SavePayoutMethod::class)->handle(
        ownerType: PayoutOwnerType::BusinessAccount,
        ownerId: $this->account->id,
        type: PayoutMethodType::Bkash,
        label: 'Race',
        details: ['account_holder_name' => 'Race', 'account_number' => '01711112222'],
    );

    expect(partnerReceivableCollection($order)->status)->toBe(ReceivableCollectionStatus::WalletInsufficient);

    partnerReceivableRace(6, function (int $worker) use ($owed, $method) {
        if ($worker % 2 === 0) {
            app(RequestAccountWithdrawal::class)->handle(
                test()->account->refresh(),
                partnerReceivableWallet(),
                $method,
                partnerReceivableMoney('500.00'),
                'partner-receivable-race-withdrawal-'.$worker,
            );

            return;
        }

        partnerReceivableCredit('0.00', $owed->toDecimal());
        app(ReleaseCollectableReceivables::class)->forAccount(test()->account->id);
    });

    $collection = partnerReceivableCollection($order);
    $reserved = (int) AccountWithdrawal::query()->count();

    // A withdrawal got through only after the order was collected, never in front of it.
    expect($collection->status)->toBe(ReceivableCollectionStatus::Collected)
        ->and(DB::table('wallet_transactions')->where('type', LedgerTransactionType::PartnerGatewayReceivableDebit->value)->count())->toBe(1)
        ->and($reserved)->toBeLessThanOrEqual(3);

    foreach (AccountWithdrawal::query()->get() as $withdrawal) {
        expect($withdrawal->requested_at->greaterThanOrEqualTo($collection->collected_at->subSecond()))->toBeTrue();
    }

    partnerReceivableInvariants();
});

it('ends a hold exactly one way when the deadline sweep races the top-up that would pay it', function () {
    [$order, $payment, $owed] = partnerReceivableOrder();
    partnerReceivableCredit('0.00', '1.00');
    app(ReleaseCollectableReceivables::class)->forPayment($payment);

    $this->travel(25)->hours();

    partnerReceivableRace(6, function (int $worker) use ($owed) {
        if ($worker % 2 === 0) {
            app(ReleaseCollectableReceivables::class)->sweep();

            return;
        }

        partnerReceivableCredit('0.00', $owed->toDecimal());
        app(ReleaseCollectableReceivables::class)->forAccount(test()->account->id);
    });

    $collection = partnerReceivableCollection($order);
    $reservation = $order->items()->sole()->stockReservation->refresh();

    // Paid before the deadline was applied, or expired before the money was in — never both, never neither.
    if ($collection->status === ReceivableCollectionStatus::Collected) {
        expect(partnerReceivableDebits())->toBeGreaterThanOrEqual(1)
            ->and($reservation->status)->toBe(StockReservationStatus::Committed)
            ->and(Order::query()->findOrFail($order->id)->status)->toBe(OrderStatus::Paid);
    } else {
        expect($collection->status)->toBe(ReceivableCollectionStatus::DeadlineExpiredRefundRequired)
            ->and(partnerReceivableDebits())->toBe(0)
            ->and($reservation->status)->toBe(StockReservationStatus::Released)
            ->and(Order::query()->findOrFail($order->id)->status)->toBe(OrderStatus::OnHold);
    }

    partnerReceivableInvariants();
});

it('keeps the units whole when the reservation runs out while it is being collected', function () {
    [$order, $payment] = partnerReceivableOrder(beforeNotice: function (Order $order) {
        // Ended in time, but the expiry sweep has not run yet.
        DB::table('stock_reservations')
            ->whereIn('id', $order->items()->pluck('stock_reservation_id'))
            ->update(['expires_at' => now()->subMinute()]);
    });
    partnerReceivableCredit('0.00', '20000.00');
    $collection = app(OpenReceivableCollection::class)->handle($payment);

    partnerReceivableRace(6, function (int $worker) use ($collection) {
        if ($worker % 2 === 0) {
            app(CollectPartnerReceivable::class)->attempt($collection->id);

            return;
        }

        app(ReleaseExpiredReservations::class)->handle();
    });

    $stock = StockItem::query()->where('product_id', $this->product->id)->firstOrFail();

    expect(partnerReceivableCollection($order)->status)->toBe(ReceivableCollectionStatus::Collected)
        ->and(DB::table('wallet_transactions')->where('type', LedgerTransactionType::PartnerGatewayReceivableDebit->value)->count())->toBe(1)
        // Two units, committed once — whether the original was kept or set aside again.
        ->and($stock->processing)->toBe(2)
        ->and($stock->reserved)->toBe(0)
        ->and(OrderHoldReservation::query()->count())->toBeLessThanOrEqual(1)
        ->and(StockReservation::query()->where('status', StockReservationStatus::Committed->value)->count())->toBe(1);

    partnerReceivableInvariants();
});

it('never both collects and loses the stock to another order that takes it first', function () {
    [$order, $payment] = partnerReceivableOrder(beforeNotice: function (Order $order) {
        DB::table('stock_reservations')
            ->whereIn('id', $order->items()->pluck('stock_reservation_id'))
            ->update(['expires_at' => now()->subMinute()]);
        app(ReleaseExpiredReservations::class)->handle();
        // Exactly the units this order needs are on the shelf, and no more.
        DB::table('stock_items')->where('product_id', $this->product->id)->update(['available' => 2]);
    });
    partnerReceivableCredit('0.00', '20000.00');
    $collection = app(OpenReceivableCollection::class)->handle($payment);

    partnerReceivableRace(4, function (int $worker) use ($collection) {
        if ($worker % 2 === 0) {
            app(CollectPartnerReceivable::class)->attempt($collection->id);

            return;
        }

        // Another customer's checkout, for the same two units.
        app(StockReservations::class)->reserve(
            test()->product,
            null,
            2,
            ReservationKind::OnlinePayment,
            'RACE-COMPETITOR-'.$worker,
        );
    });

    $stock = StockItem::query()->where('product_id', $this->product->id)->firstOrFail();
    $status = partnerReceivableCollection($order)->status;
    $competitors = StockReservation::query()->where('reference', 'like', 'RACE-COMPETITOR-%')->where('status', StockReservationStatus::Active->value)->count();

    // Whoever got the two units, only they have them.
    if ($status === ReceivableCollectionStatus::Collected) {
        expect($competitors)->toBe(0)
            ->and($stock->processing)->toBe(2)
            ->and(partnerReceivableDebits())->toBeGreaterThanOrEqual(1);
    } else {
        expect($status)->toBe(ReceivableCollectionStatus::ReservationRecoveryRequired)
            ->and($competitors)->toBe(1)
            ->and($stock->reserved)->toBe(2)
            // The Wallet was not touched for an order that could not be given its stock.
            ->and(partnerReceivableDebits())->toBe(0);
    }

    expect($stock->available)->toBeGreaterThanOrEqual(0)
        ->and($stock->reserved + $stock->processing)->toBeLessThanOrEqual(2);
});
