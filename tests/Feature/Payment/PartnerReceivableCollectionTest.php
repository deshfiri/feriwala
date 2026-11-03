<?php

use App\Domain\Account\Enums\AccountType;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Billing\Enums\PaymentStatus;
use App\Domain\Billing\Jobs\SettleGatewayNotification;
use App\Domain\Billing\Models\Payment;
use App\Domain\Inventory\Actions\ReleaseExpiredReservations;
use App\Domain\Inventory\Enums\StockReservationStatus;
use App\Domain\Inventory\Models\StockItem;
use App\Domain\Inventory\Models\StockReservation;
use App\Domain\Order\Actions\InitiateOrderPayment;
use App\Domain\Order\Actions\ReleaseCollectableReceivables;
use App\Domain\Order\Actions\SetPartnerCollectionDeadline;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Enums\ReceivableCollectionStatus;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderHoldReservation;
use App\Domain\Order\Models\OrderPlatformReceivable;
use App\Domain\Order\Models\OrderReceivableCollection;
use App\Domain\Order\PartnerCollectionDeadline;
use App\Domain\Payout\Actions\SavePayoutMethod;
use App\Domain\Payout\Enums\PayoutMethodType;
use App\Domain\Payout\Enums\PayoutOwnerType;
use App\Domain\Wallet\Actions\OpenWallet;
use App\Domain\Wallet\Actions\VerifyLedgerIntegrity;
use App\Domain\Wallet\Data\PostingContext;
use App\Domain\Wallet\Enums\BalanceType;
use App\Domain\Wallet\Enums\LedgerTransactionType;
use App\Domain\Wallet\Events\WalletCredited;
use App\Domain\Wallet\Models\LedgerEntry;
use App\Domain\Wallet\Models\Wallet;
use App\Domain\Wallet\WalletService;
use App\Domain\Website\Models\WebsiteProduct;
use App\Domain\Withdrawal\Actions\RequestAccountWithdrawal;
use App\Domain\Withdrawal\Exceptions\AccountWithdrawalRefused;
use App\Models\User;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

/*
 * Collecting Banij's share of a Partner-gateway order from the Partner's Wallet
 * (D36): the customer's payment stays paid because the Partner received it, and
 * the order may go ahead only once the whole frozen receivable has been debited —
 * never partly, never into a negative balance, never before stock is secured.
 */

beforeEach(function () {
    partnerCheckoutWorld();
});

function partnerCollectMoney(string $amount): Money
{
    return Money::fromDecimal($amount, Currency::BDT);
}

/**
 * Money into the Partner's Wallet the way it really arrives: a top-up is platform
 * credit, a sale's earning is withdrawable.
 */
function partnerCollectFund(string $platformCredit, string $withdrawable = '0.00'): Wallet
{
    $wallet = Wallet::query()->where('business_account_id', test()->account->id)->first()
        ?? app(OpenWallet::class)->handle(test()->account);

    foreach ([[LedgerTransactionType::TopUpCredit, $platformCredit], [LedgerTransactionType::SalesCredit, $withdrawable]] as [$type, $amount]) {
        if (partnerCollectMoney($amount)->isPositive()) {
            app(WalletService::class)->credit($wallet, $type, partnerCollectMoney($amount), new PostingContext(
                source: 'test',
                description: 'Funding '.$type->value,
                idempotencyKey: 'partner-collect-fund:'.Str::random(12),
            ));
        }
    }

    return $wallet->refresh();
}

/**
 * A Partner order paid at the provider and then reported to us — the way a customer's
 * payment really arrives. `$beforeNotice` runs after the customer has paid and before
 * the provider's notice reaches us, which is when a reservation can run out.
 *
 * @param  list<array{0: WebsiteProduct, 1: int}>|null  $lines
 * @return array{0: Order, 1: Payment}
 */
function partnerCollectVerified(?array $lines = null, ?Closure $beforeNotice = null): array
{
    $order = partnerCheckoutPlace(lines: $lines);
    app(InitiateOrderPayment::class)->handle($order);
    $payment = $order->payment()->first();
    $valId = partnerCheckoutPays($payment);

    if ($beforeNotice !== null) {
        $beforeNotice($order);
    }

    SettleGatewayNotification::dispatchSync($payment->id, $valId, 'ipn');

    return [$order->refresh(), $payment->refresh()];
}

function partnerCollectionOf(Order $order): OrderReceivableCollection
{
    return OrderReceivableCollection::query()->where('order_id', $order->id)->sole();
}

/**
 * The first fifteen-minute reservation ends before the Partner's payment is reported.
 */
function partnerCollectLapse(Order $order): void
{
    DB::table('stock_reservations')
        ->whereIn('id', $order->items()->pluck('stock_reservation_id'))
        ->update(['expires_at' => now()->subMinute()]);

    app(ReleaseExpiredReservations::class)->handle();
}

function partnerCollectDebits(): int
{
    return LedgerEntry::query()->where('type', LedgerTransactionType::PartnerGatewayReceivableDebit->value)->count();
}

function partnerCollectWallet(): Wallet
{
    return Wallet::query()->where('business_account_id', test()->account->id)->sole();
}

function partnerCollectStock(): StockItem
{
    return StockItem::query()->where('product_id', test()->product->id)->sole();
}

function partnerCollectWithdraw(string $amount, string $key): void
{
    $account = test()->account->refresh();
    $method = app(SavePayoutMethod::class)->handle(
        ownerType: PayoutOwnerType::BusinessAccount,
        ownerId: $account->id,
        type: PayoutMethodType::Bkash,
        label: 'Primary bKash',
        details: ['account_holder_name' => 'Karim Traders', 'account_number' => '01711112222'],
    );

    app(RequestAccountWithdrawal::class)->handle($account, partnerCollectWallet(), $method, partnerCollectMoney($amount), $key);
}

describe('collecting at once', function () {
    it('takes the whole receivable from a funded Wallet, platform credit first, and lets the order go ahead', function () {
        partnerCollectFund('1000.00', '9000.00');

        [$order, $payment] = partnerCollectVerified();

        $collection = partnerCollectionOf($order);
        $wallet = partnerCollectWallet();
        $entries = LedgerEntry::query()->where('type', LedgerTransactionType::PartnerGatewayReceivableDebit->value)->orderBy('id')->get();

        expect($collection->status)->toBe(ReceivableCollectionStatus::Collected)
            ->and($collection->collected_at)->not->toBeNull()
            ->and($collection->wallet_transaction_id)->not->toBeNull()
            // The customer's payment is the Partner's money and stays paid.
            ->and($payment->status)->toBe(PaymentStatus::Paid)
            ->and($order->status)->toBe(OrderStatus::Paid)
            ->and($order->paid_at)->not->toBeNull()
            ->and($order->hold_reason)->toBeNull()
            // Committed to the order: no longer reserved, no longer available.
            ->and($order->items()->sole()->stockReservation->status)->toBe(StockReservationStatus::Committed)
            // One debit for the whole amount, split by kind: platform credit first, withdrawable for the rest.
            ->and($entries)->toHaveCount(2)
            ->and($entries[0]->balance_type)->toBe(BalanceType::PlatformCredit)
            ->and($entries[0]->debit->toDecimal())->toBe('1000.00')
            ->and($entries[1]->balance_type)->toBe(BalanceType::Withdrawable)
            ->and($entries[0]->debit->plus($entries[1]->debit)->equals($collection->amount))->toBeTrue()
            ->and($wallet->total->plus($collection->amount)->toDecimal())->toBe('10000.00')
            ->and($wallet->platform_credit->toDecimal())->toBe('0.00');
    });

    it('credits no profit and records nothing as cash on delivery', function () {
        partnerCollectFund('0.00', '9000.00');

        [$order] = partnerCollectVerified();

        expect(LedgerEntry::query()->where('wallet_id', partnerCollectWallet()->id)->where('credit', '>', 0)->count())->toBe(1)
            ->and(LedgerEntry::query()->whereIn('type', [
                LedgerTransactionType::SalesCredit->value,
                LedgerTransactionType::CommissionCredit->value,
                LedgerTransactionType::CodCollectionCredit->value,
            ])->count())->toBe(1);
    });

    it('keeps the ledger whole', function () {
        partnerCollectFund('1000.00', '9000.00');

        partnerCollectVerified();

        $report = app(VerifyLedgerIntegrity::class)->handle();

        expect($report['mismatched'])->toBe(0)
            ->and($report['checked'])->toBeGreaterThanOrEqual(1);
    });

    it('collects once however many times the verification is announced', function () {
        partnerCollectFund('0.00', '9000.00');
        [$order, $payment] = partnerCollectVerified();

        $release = app(ReleaseCollectableReceivables::class);
        $release->forPayment($payment);
        $release->forPayment($payment);
        $release->forAccount($this->account->id);

        expect(partnerCollectDebits())->toBe(1)
            ->and(partnerCollectionOf($order)->status)->toBe(ReceivableCollectionStatus::Collected);
    });
});

describe('a Wallet that cannot cover it', function () {
    it('touches nothing, holds the order and its stock, and leaves the payment paid', function () {
        partnerCollectFund('0.00', '100.00');

        [$order, $payment] = partnerCollectVerified();

        $collection = partnerCollectionOf($order);
        $reservation = $order->items()->sole()->stockReservation->refresh();

        expect($collection->status)->toBe(ReceivableCollectionStatus::WalletInsufficient)
            ->and($collection->status_reason)->toBe('partner_wallet_insufficient')
            ->and($collection->wallet_insufficient_at)->not->toBeNull()
            ->and($collection->collected_at)->toBeNull()
            // No partial debit, and no negative balance.
            ->and(partnerCollectDebits())->toBe(0)
            ->and(partnerCollectWallet()->total->toDecimal())->toBe('100.00')
            ->and($payment->status)->toBe(PaymentStatus::Paid)
            ->and($order->status)->toBe(OrderStatus::OnHold)
            ->and($order->hold_reason)->toContain('Wallet')
            // Held until the deadline, not the fifteen minutes it was placed with.
            ->and($reservation->status)->toBe(StockReservationStatus::Active)
            ->and($reservation->expires_at->greaterThan($collection->deadline_at))->toBeTrue();
    });

    it('holds an order whose Partner has no Wallet at all', function () {
        [$order] = partnerCollectVerified();

        expect(partnerCollectionOf($order)->status)->toBe(ReceivableCollectionStatus::WalletInsufficient)
            ->and($order->status)->toBe(OrderStatus::OnHold)
            ->and(partnerCollectDebits())->toBe(0);
    });

    it('is released by the Wallet being topped up, and the credit is what sets it off', function () {
        partnerCollectFund('0.00', '100.00');
        [$order] = partnerCollectVerified();
        $amount = partnerCollectionOf($order)->amount;

        partnerCollectFund($amount->toDecimal());

        $collection = partnerCollectionOf($order);

        expect($collection->status)->toBe(ReceivableCollectionStatus::Collected)
            ->and($order->refresh()->status)->toBe(OrderStatus::Paid)
            ->and($order->held_at)->toBeNull()
            ->and($order->items()->sole()->stockReservation->status)->toBe(StockReservationStatus::Committed)
            ->and(partnerCollectDebits())->toBe(1)
            ->and(partnerCollectWallet()->total->toDecimal())->toBe('100.00');
    });

    it('is not released by a top-up that still leaves it short', function () {
        partnerCollectFund('0.00', '100.00');
        [$order] = partnerCollectVerified();

        partnerCollectFund('50.00');

        expect(partnerCollectionOf($order)->status)->toBe(ReceivableCollectionStatus::WalletInsufficient)
            ->and(partnerCollectDebits())->toBe(0)
            ->and(partnerCollectWallet()->total->toDecimal())->toBe('150.00');
    });

    it('is released once however many times the same credit is announced', function () {
        partnerCollectFund('0.00', '100.00');
        [$order] = partnerCollectVerified();
        $wallet = partnerCollectFund(partnerCollectionOf($order)->amount->toDecimal());

        foreach (range(1, 3) as $ignored) {
            event(new WalletCredited($wallet->id, $this->account->id, 1));
        }

        expect(partnerCollectDebits())->toBe(1)
            ->and(partnerCollectionOf($order)->status)->toBe(ReceivableCollectionStatus::Collected);
    });
});

describe('oldest first', function () {
    it('collects the older order before a newer one, and not the newer when only it would fit', function () {
        partnerCollectFund('0.00', '1.00');

        [$older] = partnerCollectVerified(lines: [[$this->selection, 3]]);
        [$newer] = partnerCollectVerified(lines: [[$this->selection, 1]]);

        $olderAmount = partnerCollectionOf($older)->amount;
        $newerAmount = partnerCollectionOf($newer)->amount;

        expect($olderAmount->greaterThan($newerAmount))->toBeTrue();

        // Enough for the newer, smaller one alone — and still short for the older.
        partnerCollectFund($newerAmount->toDecimal());

        expect(partnerCollectionOf($older)->status)->toBe(ReceivableCollectionStatus::WalletInsufficient)
            ->and(partnerCollectionOf($newer)->status)->toBe(ReceivableCollectionStatus::WalletInsufficient)
            ->and(partnerCollectDebits())->toBe(0);

        // Enough for the older one; the newer one still waits for its own money.
        partnerCollectFund($olderAmount->minus($newerAmount)->toDecimal());

        expect(partnerCollectionOf($older)->status)->toBe(ReceivableCollectionStatus::Collected)
            ->and(partnerCollectionOf($newer)->status)->toBe(ReceivableCollectionStatus::WalletInsufficient);

        // Then enough for both.
        partnerCollectFund($newerAmount->toDecimal());

        expect(partnerCollectionOf($newer)->status)->toBe(ReceivableCollectionStatus::Collected)
            ->and(partnerCollectDebits())->toBe(2);
    });
});

describe('stock that has to be secured first', function () {
    it('sets stock aside again under a new reservation when the first one lapsed, then collects', function () {
        partnerCollectFund('0.00', '9000.00');

        [$order] = partnerCollectVerified(beforeNotice: fn (Order $order) => partnerCollectLapse($order));

        $line = $order->items()->sole();
        $original = $line->stockReservation->refresh();
        $hold = OrderHoldReservation::query()->sole();

        expect(partnerCollectionOf($order)->status)->toBe(ReceivableCollectionStatus::Collected)
            // The original is never revived, repointed or edited.
            ->and($original->status)->toBe(StockReservationStatus::Expired)
            ->and($line->stock_reservation_id)->toBe($original->id)
            ->and($hold->purpose)->toBe('recovery')
            ->and($hold->reservation->reference)->toBe($order->reference.'-L1-H1')
            ->and($hold->reservation->status)->toBe(StockReservationStatus::Committed)
            ->and($hold->reservation->quantity)->toBe(2)
            // One set of units, taken once: 50 less the 2 now committed to the order.
            ->and(partnerCollectStock()->available)->toBe(48)
            ->and(partnerCollectDebits())->toBe(1)
            ->and($order->status)->toBe(OrderStatus::Paid);
    });

    it('does not debit the Wallet when the stock cannot be set aside again, and asks staff', function () {
        partnerCollectFund('0.00', '9000.00');

        [$order, $payment] = partnerCollectVerified(beforeNotice: function (Order $order) {
            partnerCollectLapse($order);
            DB::table('stock_items')->where('product_id', $this->product->id)->update(['available' => 0]);
        });

        $collection = partnerCollectionOf($order);

        expect($collection->status)->toBe(ReceivableCollectionStatus::ReservationRecoveryRequired)
            ->and($collection->status_reason)->toBe('partner_stock_recovery_required')
            ->and($collection->recovery_required_at)->not->toBeNull()
            ->and(partnerCollectDebits())->toBe(0)
            ->and(partnerCollectWallet()->total->toDecimal())->toBe('9000.00')
            ->and($payment->status)->toBe(PaymentStatus::Paid)
            ->and($order->status)->toBe(OrderStatus::OnHold)
            ->and($order->hold_reason)->toContain('could not be set aside again')
            ->and(OrderHoldReservation::query()->count())->toBe(0);
    });

    it('rolls back the stock set aside for an earlier line when a later one is not there', function () {
        $other = partnerCheckoutSelection(websiteTestProduct());
        StockItem::create([
            'warehouse_id' => StockItem::query()->value('warehouse_id'),
            'product_id' => $other->product_id,
            'available' => 5,
        ]);
        partnerCollectFund('0.00', '9000.00');

        [$order] = partnerCollectVerified(lines: [[$this->selection, 1], [$other, 1]], beforeNotice: function (Order $order) use ($other) {
            partnerCollectLapse($order);
            DB::table('stock_items')->where('product_id', $other->product_id)->update(['available' => 0]);
        });

        expect(partnerCollectionOf($order)->status)->toBe(ReceivableCollectionStatus::ReservationRecoveryRequired)
            ->and(OrderHoldReservation::query()->count())->toBe(0)
            // The first line's units went back with everything else, not left holding stock.
            ->and(partnerCollectStock()->available)->toBe(50)
            ->and(StockReservation::query()->where('status', StockReservationStatus::Active->value)->count())->toBe(0);
    });

    it('holds the replacement stock to the deadline when the Wallet is short as well', function () {
        partnerCollectFund('0.00', '100.00');

        [$order] = partnerCollectVerified(beforeNotice: fn (Order $order) => partnerCollectLapse($order));

        $collection = partnerCollectionOf($order);
        $hold = OrderHoldReservation::query()->sole()->reservation;

        expect($collection->status)->toBe(ReceivableCollectionStatus::WalletInsufficient)
            ->and($hold->status)->toBe(StockReservationStatus::Active)
            ->and($hold->expires_at->greaterThan($collection->deadline_at))->toBeTrue()
            ->and(partnerCollectDebits())->toBe(0);

        // Then the money arrives, and the replacement is the stock that is committed.
        partnerCollectFund($collection->amount->toDecimal());

        expect(partnerCollectionOf($order)->status)->toBe(ReceivableCollectionStatus::Collected)
            ->and($hold->refresh()->status)->toBe(StockReservationStatus::Committed)
            ->and(OrderHoldReservation::query()->count())->toBe(1);
    });
});

describe('the deadline', function () {
    it('is twenty-four hours from the verified payment, frozen on the collection', function () {
        [$order, $payment] = partnerCollectVerified();

        $collection = partnerCollectionOf($order);

        expect($collection->deadline_hours)->toBe(24)
            ->and($collection->verified_at->equalTo($payment->completed_at))->toBeTrue()
            ->and($collection->deadline_at->equalTo($payment->completed_at->addHours(24)))->toBeTrue();
    });

    it('reaches only the holds that come after it when Admin changes it', function () {
        [$first] = partnerCollectVerified();

        app(SetPartnerCollectionDeadline::class)->handle($this->admin, 48);

        [$second] = partnerCollectVerified();

        expect(partnerCollectionOf($first)->deadline_hours)->toBe(24)
            ->and(partnerCollectionOf($second)->deadline_hours)->toBe(48)
            ->and(app(PartnerCollectionDeadline::class)->hours())->toBe(48);
    });

    it('collects at the deadline when the Wallet can cover it by then', function () {
        [$order] = partnerCollectVerified();
        DB::table('wallets')->where('business_account_id', $this->account->id)->exists();

        $this->travel(25)->hours();
        partnerCollectFund('0.00', '20000.00');

        // A credit announces itself; the sweep is the same path when nobody does.
        app(ReleaseCollectableReceivables::class)->sweep();

        expect(partnerCollectionOf($order)->status)->toBe(ReceivableCollectionStatus::Collected)
            ->and($order->refresh()->status)->toBe(OrderStatus::Paid)
            ->and(partnerCollectDebits())->toBeGreaterThanOrEqual(1);
    });

    it('releases the stock and asks for a refund when it passes with the Wallet still short', function () {
        partnerCollectFund('0.00', '100.00');
        [$order, $payment] = partnerCollectVerified();
        $reservation = $order->items()->sole()->stockReservation;

        $this->travel(25)->hours();
        app(ReleaseCollectableReceivables::class)->sweep();

        $collection = partnerCollectionOf($order);
        $order->refresh();

        expect($collection->status)->toBe(ReceivableCollectionStatus::DeadlineExpiredRefundRequired)
            ->and($collection->status_reason)->toBe('wallet_deadline_expired_refund_required')
            ->and($collection->deadline_expired_at)->not->toBeNull()
            ->and($reservation->refresh()->status)->toBe(StockReservationStatus::Released)
            ->and(partnerCollectStock()->available)->toBe(50)
            // Nothing is claimed about the customer, and nothing is cancelled or reversed.
            ->and($payment->refresh()->status)->toBe(PaymentStatus::Paid)
            ->and($order->status)->toBe(OrderStatus::OnHold)
            ->and($order->hold_reason)->toContain('refund')
            ->and(partnerCollectDebits())->toBe(0)
            ->and(partnerCollectWallet()->total->toDecimal())->toBe('100.00')
            ->and(AuditLog::query()->where('action', 'order_receivable.deadline_expired')->count())->toBe(1);
    });

    it('is closed once, however many times the sweep passes over it', function () {
        [$order] = partnerCollectVerified();

        $this->travel(25)->hours();
        $release = app(ReleaseCollectableReceivables::class);
        $release->sweep();
        $moves = $order->statusHistory()->count();
        $release->sweep();

        expect(AuditLog::query()->where('action', 'order_receivable.deadline_expired')->count())->toBe(1)
            ->and($order->statusHistory()->count())->toBe($moves);
    });

    it('is not collected after it has expired, even when the money arrives', function () {
        [$order] = partnerCollectVerified();
        $this->travel(25)->hours();
        app(ReleaseCollectableReceivables::class)->sweep();

        partnerCollectFund('0.00', '20000.00');

        expect(partnerCollectionOf($order)->status)->toBe(ReceivableCollectionStatus::DeadlineExpiredRefundRequired)
            ->and(partnerCollectDebits())->toBe(0);
    });

    it('can be set only by somebody who may manage gateways, and only within bounds', function () {
        $partner = User::factory()->create();

        expect(fn () => app(SetPartnerCollectionDeadline::class)->handle($partner, 12))->toThrow(AuthorizationException::class)
            ->and(fn () => app(SetPartnerCollectionDeadline::class)->handle($this->admin, 0))->toThrow(InvalidArgumentException::class)
            ->and(fn () => app(SetPartnerCollectionDeadline::class)->handle($this->admin, 169))->toThrow(InvalidArgumentException::class);
    });
});

describe('withdrawals while a collection is live', function () {
    it('are refused, naming the order and the amount and nothing about the customer', function () {
        partnerCollectFund('0.00', '100.00');
        [$order] = partnerCollectVerified();
        $amount = partnerCollectionOf($order)->amount;

        try {
            partnerCollectWithdraw('500.00', 'partner-collect-withdraw-1');
            $message = null;
        } catch (AccountWithdrawalRefused $refused) {
            $message = $refused->getMessage();
        }

        expect($message)->toContain($order->reference)
            ->and($message)->toContain($amount->toDecimal())
            ->and($message)->not->toContain('01')
            ->and(partnerCollectWallet()->reserved->toDecimal())->toBe('0.00');
    });

    it('resume once the order is collected', function () {
        partnerCollectFund('0.00', '100.00');
        [$order] = partnerCollectVerified();
        partnerCollectFund('0.00', partnerCollectionOf($order)->amount->plus(partnerCollectMoney('1000.00'))->toDecimal());

        partnerCollectWithdraw('500.00', 'partner-collect-withdraw-2');

        expect(partnerCollectionOf($order)->status)->toBe(ReceivableCollectionStatus::Collected)
            ->and(partnerCollectWallet()->reserved->toDecimal())->toBe('500.00');
    });

    it('resume once the deadline has passed uncollected', function () {
        partnerCollectFund('0.00', '1000.00');
        partnerCollectVerified();

        $this->travel(25)->hours();
        app(ReleaseCollectableReceivables::class)->sweep();

        partnerCollectWithdraw('500.00', 'partner-collect-withdraw-3');

        expect(partnerCollectWallet()->reserved->toDecimal())->toBe('500.00');
    });

    it('are not held back by an order staff have to look at because its stock is gone', function () {
        partnerCollectFund('0.00', '1000.00');
        partnerCollectVerified(beforeNotice: function (Order $order) {
            partnerCollectLapse($order);
            DB::table('stock_items')->where('product_id', $this->product->id)->update(['available' => 0]);
        });

        partnerCollectWithdraw('500.00', 'partner-collect-withdraw-4');

        expect(partnerCollectWallet()->reserved->toDecimal())->toBe('500.00');
    });
});

describe('the account type at placement', function () {
    it('is frozen with the receivable, once', function () {
        [$order] = partnerCollectVerified();

        $receivable = OrderPlatformReceivable::query()->where('order_id', $order->id)->sole();

        expect($receivable->account_type_at_placement)->toBe(AccountType::NonConditional->value)
            ->and($receivable->eligibility_version)->toBe(OrderPlatformReceivable::ELIGIBILITY_VERSION)
            ->and($receivable->gateway_ownership)->toBe('partner')
            ->and($receivable->website_id)->toBe($this->website->id)
            ->and(fn () => DB::table('order_platform_receivables')->where('id', $receivable->id)->update(['account_type_at_placement' => 'conditional']))
            ->toThrow(QueryException::class);
    });

    it('is not what a later change of account type reaches', function () {
        partnerCollectFund('0.00', '100.00');
        [$order, $payment] = partnerCollectVerified();
        $receivable = OrderPlatformReceivable::query()->where('order_id', $order->id)->sole();
        $amount = $receivable->total;

        // The Business Account stops being Non-Conditional after the order was placed and held.
        $this->account->forceFill(['account_type' => AccountType::Conditional])->save();
        partnerCollectFund($amount->toDecimal());

        $payment->refresh();

        expect(partnerCollectionOf($order)->status)->toBe(ReceivableCollectionStatus::Collected)
            ->and($order->refresh()->status)->toBe(OrderStatus::Paid)
            ->and($payment->isPartnerOwned())->toBeTrue()
            ->and($payment->status)->toBe(PaymentStatus::Paid)
            ->and(OrderPlatformReceivable::query()->where('order_id', $order->id)->sole()->total->equals($amount))->toBeTrue()
            ->and(partnerCollectDebits())->toBeGreaterThanOrEqual(1)
            // What was decided stays decided, whatever the account is now.
            ->and(OrderPlatformReceivable::query()->where('order_id', $order->id)->sole()->account_type_at_placement)->toBe(AccountType::NonConditional->value);
    });
});

describe('what the database refuses', function () {
    it('keeps what a collection was made from', function () {
        [$order] = partnerCollectVerified();
        $collection = partnerCollectionOf($order);

        foreach ([['amount', '1.00'], ['deadline_at', now()->addYears(1)], ['verified_at', now()->subYear()], ['deadline_hours', 99]] as [$column, $value]) {
            expect(fn () => DB::table('order_receivable_collections')->where('id', $collection->id)->update([$column => $value]))
                ->toThrow(QueryException::class);
        }
    });

    it('allows only the declared moves', function () {
        [$order] = partnerCollectVerified();
        $collection = partnerCollectionOf($order);

        // wallet_insufficient cannot go straight back to pending, nor on to a reversal it never had.
        foreach (['pending_collection', 'reversed', 'partially_reversed'] as $status) {
            expect(fn () => DB::table('order_receivable_collections')->where('id', $collection->id)->update(['status' => $status]))
                ->toThrow(QueryException::class);
        }
    });

    it('writes the collection fact once and refuses it a second time', function () {
        partnerCollectFund('0.00', '9000.00');
        [$order] = partnerCollectVerified();
        $collection = partnerCollectionOf($order);

        expect($collection->status)->toBe(ReceivableCollectionStatus::Collected)
            ->and(fn () => DB::table('order_receivable_collections')->where('id', $collection->id)->update(['collected_at' => now()->addDay()]))
            ->toThrow(QueryException::class)
            ->and(fn () => DB::table('order_receivable_collections')->where('id', $collection->id)->update(['wallet_transaction_id' => null]))
            ->toThrow(QueryException::class)
            // Collected cannot become uncollected, or expired.
            ->and(fn () => DB::table('order_receivable_collections')->where('id', $collection->id)->update(['status' => 'deadline_expired_refund_required']))
            ->toThrow(QueryException::class)
            ->and(fn () => DB::table('order_receivable_collections')->where('id', $collection->id)->delete())
            ->toThrow(QueryException::class);
    });

    it('never edits or deletes a replacement reservation link', function () {
        partnerCollectFund('0.00', '9000.00');
        partnerCollectVerified(beforeNotice: fn (Order $order) => partnerCollectLapse($order));
        $hold = OrderHoldReservation::query()->sole();

        expect(fn () => DB::table('order_hold_reservations')->where('id', $hold->id)->update(['sequence' => 9]))->toThrow(QueryException::class)
            ->and(fn () => DB::table('order_hold_reservations')->where('id', $hold->id)->delete())->toThrow(QueryException::class);
    });
});

describe('where an order stands, as the screens read it', function () {
    it('tells the five stages apart', function () {
        partnerCollectFund('0.00', '100.00');
        [$waiting] = partnerCollectVerified();
        $waitingState = partnerCollectionOf($waiting)->state()->toArray();

        partnerCollectFund(partnerCollectionOf($waiting)->amount->toDecimal());
        $collectedState = partnerCollectionOf($waiting)->state()->toArray();

        [$recovery] = partnerCollectVerified(beforeNotice: function (Order $order) {
            partnerCollectLapse($order);
            DB::table('stock_items')->where('product_id', $this->product->id)->update(['available' => 0]);
        });
        $recoveryState = partnerCollectionOf($recovery)->state()->toArray();

        DB::table('stock_items')->where('product_id', $this->product->id)->update(['available' => 50]);
        [$expiring] = partnerCollectVerified();
        $this->travel(25)->hours();
        app(ReleaseCollectableReceivables::class)->sweep();
        $expiredState = partnerCollectionOf($expiring)->state()->toArray();

        expect($waitingState['stage'])->toBe('wallet_insufficient')
            ->and($waitingState['blocks_withdrawals'])->toBeTrue()
            ->and($waitingState['needs_staff'])->toBeFalse()
            ->and($waitingState['deadline_at'])->not->toBeNull()
            ->and($collectedState['stage'])->toBe('collected')
            ->and($collectedState['collected_at'])->not->toBeNull()
            ->and($recoveryState['stage'])->toBe('stock_recovery_required')
            ->and($recoveryState['needs_staff'])->toBeTrue()
            ->and($recoveryState['blocks_withdrawals'])->toBeFalse()
            ->and($expiredState['stage'])->toBe('expired_refund_required')
            ->and($expiredState['needs_staff'])->toBeTrue()
            ->and($expiredState['blocks_withdrawals'])->toBeFalse()
            // Money only as the server serialises it, and nothing about the customer.
            ->and($waitingState['amount'])->toHaveKeys(['amount', 'currency'])
            ->and(array_keys($waitingState))->not->toContain('customer');
    });

    it('is on the admin order screen only for an order that has one', function () {
        [$order] = partnerCollectVerified();

        $this->actingAs($this->admin)
            ->get(route('admin.orders.show', $order->public_id))
            ->assertInertia(fn ($page) => $page->where('order.partner_collection.stage', 'wallet_insufficient'));
    });
});

it('does not run anything for an order paid to Feriwala\'s own gateway', function () {
    Event::fake([WalletCredited::class]);
    partnerCheckoutEnable(enabled: false);

    $order = partnerCheckoutPlace();
    app(InitiateOrderPayment::class)->handle($order);

    expect(OrderReceivableCollection::query()->count())->toBe(0)
        ->and($order->refresh()->payment->isPartnerOwned())->toBeFalse();
});
