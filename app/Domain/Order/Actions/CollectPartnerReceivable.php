<?php

namespace App\Domain\Order\Actions;

use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Billing\Models\Payment;
use App\Domain\Inventory\Enums\StockReservationStatus;
use App\Domain\Inventory\Exceptions\InventoryRefused;
use App\Domain\Inventory\Models\StockReservation;
use App\Domain\Inventory\StockReservations;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Enums\OrderStatusChangeSource;
use App\Domain\Order\Enums\ReceivableCollectionStatus;
use App\Domain\Order\Exceptions\ReceivableStockUnavailable;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderHoldReservation;
use App\Domain\Order\Models\OrderReceivableCollection;
use App\Domain\Wallet\Data\PostingContext;
use App\Domain\Wallet\Enums\LedgerTransactionType;
use App\Domain\Wallet\Exceptions\WalletOperationRefused;
use App\Domain\Wallet\Models\Wallet;
use App\Domain\Wallet\Models\WalletTransaction;
use App\Domain\Wallet\WalletService;
use App\Support\StatusHistory\StatusChange;
use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Log\LogManager;

/**
 * Collects Banij's frozen receivable on one Partner-gateway order from the
 * Partner's Wallet, or says why it could not (D36).
 *
 * The customer's payment is real and stays paid: the Partner received it. What is
 * decided here is whether the order may go ahead, and that depends on Banij being
 * paid its share first. All of it happens in one transaction, in this order, so
 * two workers can never both collect and a half-finished attempt leaves nothing:
 *
 *   1. lock the order, the collection, the payment and the Partner's Wallet;
 *   2. an order or collection that is no longer collectable is answered as it
 *      stands — a repeat does nothing;
 *   3. the payment must still be the verified Partner-owned payment bound to this
 *      order, and the receivable the frozen figure;
 *   4. stock is secured for every line — kept, or set aside again when the first
 *      reservation lapsed ({@see SecureReceivableStock});
 *   5. only then, if the Wallet can cover the **whole** amount, it is debited —
 *      platform credit first, then withdrawable, one ledger entry per kind used —
 *      the stock is committed, the collection is marked collected and the order
 *      moves on to paid.
 *
 * **Never partial, never negative.** A Wallet that cannot cover the whole amount
 * is not touched. The stock stays held to the deadline, the collection is
 * `wallet_insufficient` and the order is on hold with the reason
 * `partner_wallet_insufficient`. A deadline that has passed with the Wallet still
 * short releases the stock and marks the collection `deadline_expired_refund_required`
 * — without claiming the customer was refunded and without cancelling anything.
 *
 * If stock cannot be secured, nothing is debited: the attempt rolls back and the
 * collection becomes `reservation_recovery_required` for staff, with the stock
 * that was still held given back so it is not locked up under a review.
 *
 * Callers should hold the account's collection lock so receivables are taken
 * oldest first ({@see ReleaseCollectableReceivables}); correctness does not rest
 * on it — the row locks do.
 */
class CollectPartnerReceivable
{
    /** Stock is held a little past the deadline so the deadline, not the expiry sweep, ends the hold. */
    protected const HOLD_GRACE_HOURS = 1;

    public function __construct(
        protected DatabaseManager $database,
        protected WalletService $wallets,
        protected StockReservations $reservations,
        protected SecureReceivableStock $stock,
        protected RecordAuditLog $audit,
        protected LogManager $log,
    ) {}

    /**
     * `$mayDebit` is false for a receivable queued behind an older one that is still
     * waiting for money: it is secured and held exactly the same, but the Wallet is
     * not touched, so a newer order can never be paid for out of money the older
     * one is owed first.
     */
    public function attempt(int $collectionId, bool $mayDebit = true): OrderReceivableCollection
    {
        try {
            $this->database->transaction(fn () => $this->collect($collectionId, $mayDebit));
        } catch (ReceivableStockUnavailable $unavailable) {
            $this->requireRecovery($collectionId, $unavailable->getMessage());
        }

        return OrderReceivableCollection::query()->findOrFail($collectionId);
    }

    protected function collect(int $collectionId, bool $mayDebit): void
    {
        $orderId = (int) OrderReceivableCollection::query()->whereKey($collectionId)->value('order_id');

        /** @var Order $order */
        $order = Order::query()->lockForUpdate()->findOrFail($orderId);

        /** @var OrderReceivableCollection $collection */
        $collection = OrderReceivableCollection::query()->lockForUpdate()->findOrFail($collectionId);

        if (! $this->isCollectable($order, $collection)) {
            return;
        }

        /** @var Payment $payment */
        $payment = Payment::query()->lockForUpdate()->findOrFail($collection->payment_id);

        if (! $this->isTheVerifiedPayment($order, $collection, $payment)) {
            return;
        }

        $wallet = Wallet::query()->where('business_account_id', $collection->business_account_id)->lockForUpdate()->first();
        $canCover = $mayDebit && $this->canCover($wallet, $collection);

        // Past the deadline and still short: nothing to secure stock for.
        if (! $canCover && $collection->isPastDeadline()) {
            $this->expire($order, $collection);

            return;
        }

        $secured = $this->stock->handle($order, $collection);

        $debit = $canCover ? $this->debit($wallet, $order, $collection) : false;

        if ($debit === false) {
            $this->holdForWallet($order, $collection, $secured);

            return;
        }

        $this->settle($order, $collection, $payment, $secured, $debit);
    }

    /**
     * Whether this order and collection may still be collected at all.
     */
    protected function isCollectable(Order $order, OrderReceivableCollection $collection): bool
    {
        $status = $collection->status;

        if (! $status->isActive() && $status !== ReceivableCollectionStatus::ReservationRecoveryRequired) {
            return false;
        }

        if (! in_array($order->status, [OrderStatus::PaymentPending, OrderStatus::OnHold], true)) {
            return false;
        }

        // A hold somebody else put on the order is theirs to lift, not ours to override.
        return ! ($order->status === OrderStatus::OnHold && $status === ReceivableCollectionStatus::PendingCollection);
    }

    protected function isTheVerifiedPayment(Order $order, OrderReceivableCollection $collection, Payment $payment): bool
    {
        $receivable = $collection->receivable;

        $sound = $payment->isPartnerOwned()
            && $payment->status->isSettled()
            && $order->payment_id === $payment->id
            && $receivable->order_id === $order->id
            && $receivable->total->equals($collection->amount);

        if (! $sound) {
            $this->log->channel('payment')->critical('A Partner-gateway collection no longer matches its payment or receivable', [
                'order' => $order->reference,
                'collection' => $collection->public_id,
                'payment' => $payment->reference,
            ]);
        }

        return $sound;
    }

    protected function canCover(?Wallet $wallet, OrderReceivableCollection $collection): bool
    {
        if ($collection->amount->isZero()) {
            return true;
        }

        return $wallet !== null
            && $wallet->currency_code === $collection->currency_code
            && ! $wallet->usableBalance()->lessThan($collection->amount);
    }

    /**
     * The whole amount, in one debit — null when there is nothing to debit, false
     * when the Wallet refused it.
     */
    protected function debit(?Wallet $wallet, Order $order, OrderReceivableCollection $collection): WalletTransaction|false|null
    {
        if ($collection->amount->isZero()) {
            return null;
        }

        if ($wallet === null) {
            return false;
        }

        try {
            return $this->wallets->debit(
                $wallet,
                LedgerTransactionType::PartnerGatewayReceivableDebit,
                $collection->amount,
                new PostingContext(
                    source: 'order',
                    description: __('wallet.partner_receivable.description', ['order' => $order->reference]),
                    idempotencyKey: 'partner-receivable:'.$collection->public_id,
                ),
            );
        } catch (WalletOperationRefused) {
            // Checked under the same lock a moment ago, so this is a rule the
            // Wallet applies that the balance alone did not show. The Wallet is untouched.
            return false;
        }
    }

    /**
     * @param  list<StockReservation>  $secured
     */
    protected function settle(Order $order, OrderReceivableCollection $collection, Payment $payment, array $secured, ?WalletTransaction $debit): void
    {
        foreach ($secured as $reservation) {
            $this->reservations->commit($reservation);
        }

        $order->forceFill([
            'paid_at' => $payment->completed_at ?? CarbonImmutable::now(),
            'held_at' => null,
            'hold_reason' => null,
        ]);

        $order->moveTo(
            OrderStatus::Paid,
            StatusChange::bySystem('Banij\'s share of the order was collected from the Partner\'s Wallet.', 'orders.notes.paid'),
            OrderStatusChangeSource::PaymentGateway,
        );

        $collection->transitionTo(ReceivableCollectionStatus::Collected);
        $collection->forceFill([
            'collected_at' => CarbonImmutable::now(),
            'wallet_transaction_id' => $debit?->id,
            'status_reason' => null,
        ])->save();

        $this->audit->handle(new AuditEntry(
            action: 'order_receivable.collected',
            auditableType: OrderReceivableCollection::class,
            auditableId: $collection->id,
            after: ['order' => $order->reference, 'amount' => $collection->amount->toDecimal(), 'currency' => $collection->currency_code],
            accountId: $collection->business_account_id,
            module: 'orders',
        ));
    }

    /**
     * The Wallet cannot cover the whole amount yet: hold the order and its stock until the deadline.
     *
     * @param  list<StockReservation>  $secured
     */
    protected function holdForWallet(Order $order, OrderReceivableCollection $collection, array $secured): void
    {
        $until = $collection->deadline_at->addHours(self::HOLD_GRACE_HOURS);

        foreach ($secured as $reservation) {
            $this->reservations->extend($reservation, $until);
        }

        if ($collection->status !== ReceivableCollectionStatus::WalletInsufficient) {
            $collection->transitionTo(ReceivableCollectionStatus::WalletInsufficient);
            $collection->forceFill([
                'wallet_insufficient_at' => $collection->wallet_insufficient_at ?? CarbonImmutable::now(),
                'status_reason' => OrderReceivableCollection::REASON_WALLET_INSUFFICIENT,
            ])->save();
        }

        $this->holdOrder(
            $order,
            OrderReceivableCollection::REASON_WALLET_INSUFFICIENT,
            'Waiting for the Partner\'s Wallet to cover Banij\'s share of this order.',
        );
    }

    /**
     * The deadline passed with the Wallet still short.
     */
    protected function expire(Order $order, OrderReceivableCollection $collection): void
    {
        $this->releaseStock($order, $collection, 'partner_wallet_deadline_expired');

        $collection->transitionTo(ReceivableCollectionStatus::DeadlineExpiredRefundRequired);
        $collection->forceFill([
            'deadline_expired_at' => CarbonImmutable::now(),
            'status_reason' => OrderReceivableCollection::REASON_DEADLINE_EXPIRED,
        ])->save();

        $this->holdOrder(
            $order,
            OrderReceivableCollection::REASON_DEADLINE_EXPIRED,
            'The Partner\'s Wallet did not cover Banij\'s share before the deadline. The customer\'s payment was received by the Partner and needs refund handling by a person.',
        );

        $this->log->channel('payment')->critical('A Partner-gateway order passed its Wallet deadline uncollected', [
            'order' => $order->reference,
            'collection' => $collection->public_id,
            'amount' => $collection->amount->toDecimal(),
        ]);

        $this->audit->handle(new AuditEntry(
            action: 'order_receivable.deadline_expired',
            auditableType: OrderReceivableCollection::class,
            auditableId: $collection->id,
            after: ['order' => $order->reference, 'amount' => $collection->amount->toDecimal(), 'currency' => $collection->currency_code],
            accountId: $collection->business_account_id,
            module: 'orders',
            isSensitive: true,
        ));
    }

    /**
     * Stock could not be secured, so nothing was debited and the attempt rolled back.
     *
     * In its own transaction, after that rollback. Re-checked under the lock: the
     * collection may have been settled by another worker in the meantime.
     */
    protected function requireRecovery(int $collectionId, string $why): void
    {
        $this->database->transaction(function () use ($collectionId, $why): void {
            $orderId = (int) OrderReceivableCollection::query()->whereKey($collectionId)->value('order_id');

            /** @var Order $order */
            $order = Order::query()->lockForUpdate()->findOrFail($orderId);

            /** @var OrderReceivableCollection $collection */
            $collection = OrderReceivableCollection::query()->lockForUpdate()->findOrFail($collectionId);

            if (! $this->isCollectable($order, $collection)) {
                return;
            }

            // Held stock is given back rather than locked up under a review nobody has started.
            $this->releaseStock($order, $collection, 'partner_stock_recovery_required');

            if ($collection->status !== ReceivableCollectionStatus::ReservationRecoveryRequired) {
                $collection->transitionTo(ReceivableCollectionStatus::ReservationRecoveryRequired);
                $collection->forceFill([
                    'recovery_required_at' => CarbonImmutable::now(),
                    'status_reason' => OrderReceivableCollection::REASON_STOCK_RECOVERY,
                ])->save();
            }

            $this->holdOrder(
                $order,
                OrderReceivableCollection::REASON_STOCK_RECOVERY,
                'The stock held for this order lapsed and could not be set aside again, so Banij\'s share was not taken from the Partner\'s Wallet. '.$why,
            );

            $this->log->channel('payment')->critical('A Partner-gateway order could not have its stock set aside again', [
                'order' => $order->reference,
                'collection' => $collection->public_id,
            ]);

            $this->audit->handle(new AuditEntry(
                action: 'order_receivable.stock_recovery_required',
                auditableType: OrderReceivableCollection::class,
                auditableId: $collection->id,
                after: ['order' => $order->reference],
                accountId: $collection->business_account_id,
                module: 'orders',
                isSensitive: true,
            ));
        });
    }

    /**
     * Give back whatever stock the order still holds: the original reservations
     * and any made since, each only while it is still active.
     */
    protected function releaseStock(Order $order, OrderReceivableCollection $collection, string $reason): void
    {
        $ids = $order->items()->pluck('stock_reservation_id')
            ->merge(OrderHoldReservation::query()->where('order_receivable_collection_id', $collection->id)->pluck('stock_reservation_id'))
            ->filter()
            ->unique();

        foreach (StockReservation::query()->whereIn('id', $ids)->where('status', StockReservationStatus::Active->value)->get() as $reservation) {
            try {
                $this->reservations->release($reservation, $order->reference.': '.$reason);
            } catch (InventoryRefused) {
                // Ended another way between the read and the lock; it holds nothing now.
            }
        }
    }

    /**
     * Keep the order from going ahead, with the reason for staff.
     */
    protected function holdOrder(Order $order, string $code, string $sentence): void
    {
        if ($order->status === OrderStatus::OnHold) {
            if ($order->hold_reason !== $sentence) {
                $order->forceFill(['hold_reason' => $sentence])->save();
            }

            return;
        }

        $order->forceFill(['held_at' => CarbonImmutable::now(), 'hold_reason' => $sentence]);

        $order->moveTo(
            OrderStatus::OnHold,
            new StatusChange(reason: $sentence, internalNote: $code, publicNote: 'orders.notes.held'),
            OrderStatusChangeSource::PaymentGateway,
        );
    }
}
