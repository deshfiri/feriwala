<?php

namespace App\Domain\Order\Actions;

use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Billing\Models\Payment;
use App\Domain\Order\Enums\CollectionChannel;
use App\Domain\Order\Models\OrderItem;
use App\Domain\Order\Models\OrderProceedsSettlement;
use App\Domain\Supplier\Actions\EvaluateSupplierPayableEligibility;
use App\Domain\Wallet\Actions\OpenWallet;
use App\Domain\Wallet\Data\PostingContext;
use App\Domain\Wallet\Enums\LedgerTransactionType;
use App\Domain\Wallet\WalletService;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\UniqueConstraintViolationException;
use InvalidArgumentException;
use LogicException;

/**
 * A Non-Conditional reseller's earning on one order line becomes real only
 * when both are true: the order reached Delivered, and the money for it was
 * actually collected (D-new, D36). Neither fact alone credits anything — the
 * entry points below record their own fact, idempotently, and only the second
 * one to arrive computes the earning and credits the wallet.
 *
 * Mirrors {@see EvaluateSupplierPayableEligibility}'s
 * exact shape, by the Project Owner's own explicit instruction: `Delivered`
 * and "the money reached Banij" are not the same financial event, so
 * crediting a reseller's wallet from `Delivered` alone would be exactly the
 * mistake that pattern already exists to prevent on the supplier side.
 *
 * **Three ways the money is collected, three different facts** (D36):
 *
 *   - cash on delivery, confirmed by staff ({@see markCodCollected()});
 *   - a verified payment to Feriwala's gateway ({@see markPaymentCollected()}) —
 *     the line's share of what the gateway confirmed, never dressed up as COD;
 *   - a verified payment to the Partner's own gateway, recorded the same way but
 *     for reporting only: the Partner already holds that money, so it never earns
 *     a wallet credit — the database refuses one.
 *
 * A confirmed collection below the line's own recoverable cost is **flagged
 * for review, never silently credited as zero** — the Project Owner's own
 * instruction, because zeroing it would not financially recover Banij's cost
 * and would hide the shortfall.
 */
class EvaluateOrderProceedsEligibility
{
    public function __construct(
        protected WalletService $wallet,
        protected OpenWallet $openWallet,
        protected RecordAuditLog $audit,
        protected DatabaseManager $database,
    ) {}

    public function markDelivered(OrderItem $item, ?CarbonImmutable $at = null): OrderProceedsSettlement
    {
        return $this->apply($item, deliveredAt: $at ?? CarbonImmutable::now());
    }

    public function markCodCollected(OrderItem $item, Money $amountCollected, ?CarbonImmutable $at = null): OrderProceedsSettlement
    {
        return $this->apply($item, collection: [CollectionChannel::Cod, $at ?? CarbonImmutable::now(), $amountCollected, null]);
    }

    /**
     * A verified payment is this line's collection fact (D36).
     *
     * `$amount` is the line's share of the payment the gateway confirmed. The
     * channel comes from the payment's own frozen ownership, never from the
     * Storefront's settings today; a payment with no ownership is never recorded
     * here, because it would have to be guessed.
     *
     * @throws InvalidArgumentException when the payment is not a settled one with a frozen ownership
     */
    public function markPaymentCollected(OrderItem $item, Payment $payment, Money $amount, ?CarbonImmutable $at = null): OrderProceedsSettlement
    {
        $channel = CollectionChannel::forOwnership($payment->gateway_ownership);

        if ($channel === null || ! $payment->status->isSettled()) {
            throw new InvalidArgumentException('Only a settled payment with a frozen ownership can be a line\'s collection fact.');
        }

        if ($amount->isNegative()) {
            throw new InvalidArgumentException('The amount collected cannot be negative.');
        }

        return $this->apply($item, collection: [$channel, $at ?? $payment->completed_at ?? CarbonImmutable::now(), $amount, $payment->id]);
    }

    /**
     * @param  array{0: CollectionChannel, 1: CarbonImmutable, 2: Money, 3: int|null}|null  $collection  channel, when, how much, from which payment
     */
    protected function apply(OrderItem $item, ?CarbonImmutable $deliveredAt = null, ?array $collection = null): OrderProceedsSettlement
    {
        try {
            return $this->database->transaction(
                fn () => $this->applyLocked($item, $deliveredAt, $collection)
            );
        } catch (UniqueConstraintViolationException $exception) {
            // `markDelivered()` and a collection fact can race to create
            // the first row for the same line. The unique index on
            // `order_item_id` settles it; the loser simply retries against
            // the row the winner created.
            if (OrderProceedsSettlement::query()->where('order_item_id', $item->id)->doesntExist()) {
                throw $exception;
            }

            return $this->database->transaction(
                fn () => $this->applyLocked($item, $deliveredAt, $collection)
            );
        }
    }

    /**
     * @param  array{0: CollectionChannel, 1: CarbonImmutable, 2: Money, 3: int|null}|null  $collection
     */
    protected function applyLocked(OrderItem $item, ?CarbonImmutable $deliveredAt, ?array $collection): OrderProceedsSettlement
    {
        $settlement = OrderProceedsSettlement::query()->where('order_item_id', $item->id)->first();

        if ($settlement === null) {
            $settlement = OrderProceedsSettlement::create([
                'order_id' => $item->order_id,
                'order_item_id' => $item->id,
                'business_account_id' => $item->order->business_account_id,
                'currency_code' => $item->currency_code,
                // A storefront line declares no resale amount: it sold for what
                // the customer was charged for it.
                'resale_amount' => $item->resale_amount ?? $item->line_total,
                'recovered_amount' => $item->recoverableAmount(),
                'created_at' => now(),
            ]);
        }

        /** @var OrderProceedsSettlement $locked */
        $locked = OrderProceedsSettlement::query()->lockForUpdate()->findOrFail($settlement->id);

        // Only a settlement still waiting on both facts takes one —
        // already-eligible rows are left exactly as they are.
        if ($locked->eligible_at !== null) {
            return $locked;
        }

        if ($deliveredAt !== null && $locked->delivered_at === null) {
            $locked->forceFill(['delivered_at' => $deliveredAt]);
        }

        if ($collection !== null) {
            $this->recordCollection($locked, $collection);
        }

        if ($locked->delivered_at !== null && $locked->isCollected()) {
            $this->settle($locked);
        } elseif ($locked->isDirty()) {
            $locked->save();
        }

        $settlement->setRawAttributes($locked->getAttributes(), sync: true);

        return $locked;
    }

    /**
     * Take a collection fact once, by one channel.
     *
     * The same channel again changes nothing, so a repeated callback or a repeated
     * confirmation is a no-op. A different channel for a line that is already
     * collected is a contradiction, and refused rather than quietly overwritten.
     *
     * @param  array{0: CollectionChannel, 1: CarbonImmutable, 2: Money, 3: int|null}  $collection
     *
     * @throws LogicException when the line was already collected another way
     */
    protected function recordCollection(OrderProceedsSettlement $locked, array $collection): void
    {
        [$channel, $at, $amount, $paymentId] = $collection;

        if ($locked->isCollected()) {
            if ($locked->collectionChannel() === $channel) {
                return;
            }

            throw new LogicException(sprintf(
                'This line was already collected through %s, so it cannot also be collected through %s.',
                $locked->collectionChannel()?->value,
                $channel->value,
            ));
        }

        if ($channel === CollectionChannel::Cod) {
            $locked->forceFill([
                'collection_channel' => CollectionChannel::Cod,
                'cod_collected_at' => $at,
                'cod_amount_collected' => $amount,
            ]);

            return;
        }

        $locked->forceFill([
            'collection_channel' => $channel,
            'payment_collected_at' => $at,
            'payment_collected_amount' => $amount,
            'collection_payment_id' => $paymentId,
        ]);
    }

    /**
     * Both facts are in: compute the earning against what was actually
     * collected — never the originally declared estimate — credit it when
     * positive, and flag rather than silently zero a shortfall.
     *
     * Money the Partner collected on their own gateway earns no wallet credit at
     * all: they already hold it, and crediting it would create it twice.
     */
    protected function settle(OrderProceedsSettlement $locked): void
    {
        $channel = $locked->collectionChannel() ?? CollectionChannel::Cod;

        if (! $channel->feriwalaHoldsTheMoney()) {
            $this->settleExternally($locked, $channel);

            return;
        }

        /** @var Money $collected */
        $collected = $locked->collectedAmount();
        $earning = $collected->minus($locked->recovered_amount);
        $shortfall = $earning->isNegative();
        $resellerEarning = $shortfall ? Money::zero($earning->currency) : $earning;

        $locked->forceFill([
            'eligible_at' => CarbonImmutable::now(),
            'reseller_earning' => $resellerEarning,
            'flagged_for_review' => $shortfall,
        ])->save();

        $this->audit->handle(new AuditEntry(
            action: 'order_proceeds_settlement.eligible',
            auditableType: OrderProceedsSettlement::class,
            auditableId: $locked->id,
            accountId: $locked->business_account_id,
            after: [
                'collection_channel' => $channel->value,
                'amount_collected' => $collected->toDecimal(),
                'recovered_amount' => $locked->recovered_amount->toDecimal(),
                'reseller_earning' => $resellerEarning->toDecimal(),
                'flagged_for_review' => $shortfall,
            ],
            module: PermissionModule::Order->value,
            isSensitive: $shortfall,
        ));

        if ($shortfall || ! $earning->isPositive()) {
            return;
        }

        $account = $locked->businessAccount;
        $wallet = $this->openWallet->handle($account, Currency::from($locked->currency_code));

        $transaction = $this->wallet->credit($wallet, LedgerTransactionType::SalesCredit, $earning, new PostingContext(
            source: 'order_proceeds_settlement',
            description: "Reseller earning for order {$locked->order->reference}",
            idempotencyKey: 'order-proceeds-settlement:'.$locked->public_id,
        ));

        $locked->forceFill(['wallet_transaction_id' => $transaction->id])->save();
    }

    /**
     * A line the Partner was paid for directly: eligible, with nothing to credit.
     *
     * Its earning is recorded as nothing. What the Partner keeps is the gross
     * payment less Banij's receivable, which is an order-level figure reported
     * from the frozen receivable, not a per-line wallet amount.
     */
    protected function settleExternally(OrderProceedsSettlement $locked, CollectionChannel $channel): void
    {
        $locked->forceFill([
            'eligible_at' => CarbonImmutable::now(),
            'reseller_earning' => Money::zero(Currency::from($locked->currency_code)),
            'flagged_for_review' => false,
        ])->save();

        $this->audit->handle(new AuditEntry(
            action: 'order_proceeds_settlement.eligible',
            auditableType: OrderProceedsSettlement::class,
            auditableId: $locked->id,
            accountId: $locked->business_account_id,
            after: [
                'collection_channel' => $channel->value,
                'wallet_credit' => 'none: the Partner collected this payment directly',
            ],
            module: PermissionModule::Order->value,
        ));
    }
}
