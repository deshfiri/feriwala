<?php

namespace App\Domain\Order\Actions;

use App\Domain\Access\Enums\PermissionAction;
use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Access\PermissionCatalogue;
use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Billing\Enums\RefundStatus;
use App\Domain\Billing\Models\RefundRequest;
use App\Domain\Damage\Enums\DamageStatus;
use App\Domain\Damage\Models\OrderDamage;
use App\Domain\Order\Enums\CollectionChannel;
use App\Domain\Order\Enums\OrderDeliveryStatus;
use App\Domain\Order\Enums\OrderSource;
use App\Domain\Order\Enums\StorefrontProfitStatus;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderDeliveryStatusChange;
use App\Domain\Order\Models\OrderItem;
use App\Domain\Order\Models\OrderProceedsSettlement;
use App\Domain\Order\Models\OrderReturn;
use App\Domain\Order\Models\PartnerStorefrontProfit;
use App\Domain\Order\Models\PartnerStorefrontProfitLine;
use App\Domain\Order\Models\PartnerStorefrontProfitReversal;
use App\Domain\Order\Queries\StorefrontProfitCalculator;
use App\Domain\Wallet\Actions\OpenWallet;
use App\Domain\Wallet\Data\PostingContext;
use App\Domain\Wallet\Enums\BalanceType;
use App\Domain\Wallet\Enums\LedgerDirection;
use App\Domain\Wallet\Enums\LedgerTransactionType;
use App\Domain\Wallet\Exceptions\WalletOperationRefused;
use App\Domain\Wallet\Models\LedgerEntry;
use App\Domain\Wallet\WalletService;
use App\Models\User;
use App\Notifications\Orders\StorefrontProfitUpdated;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Log\LogManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use InvalidArgumentException;
use Throwable;

/**
 * Works out, holds and credits a Partner's profit on one Storefront order.
 *
 * {@see sync()} is called whenever something the profit depends on happens —
 * Delivered, a collection confirmed, a payment settled, a return, a cancellation
 * — and is safe to call any number of times: it reads the facts from their own
 * records, works the figures out again from frozen values only, and decides
 * where the profit stands. It never credits anything.
 *
 * {@see release()} is the minute sweep's half: a profit whose 24-hour hold has
 * ended is checked once more under its lock and, if nothing blocks it, credited
 * to the Partner's withdrawable balance under `partner-storefront-profit:{order}`
 * — at most once, whatever happens around it.
 *
 * After it is credited, a return takes back what the returned units earned by a
 * compensating debit from withdrawable money, never into a negative balance;
 * what the Wallet cannot carry is recorded as owed and taken by the sweep when it
 * can.
 */
class SettleStorefrontProfit
{
    public function __construct(
        protected DatabaseManager $database,
        protected StorefrontProfitCalculator $calculator,
        protected WalletService $wallet,
        protected OpenWallet $wallets,
        protected RecordAuditLog $audit,
        protected LogManager $log,
    ) {}

    /**
     * Bring one order's profit up to date with its facts. Null for an order that
     * is not a Storefront order.
     */
    public function sync(Order $order): ?PartnerStorefrontProfit
    {
        if ($order->source !== OrderSource::Website) {
            return null;
        }

        return $this->locked($order, fn (PartnerStorefrontProfit $profit, Order $lockedOrder) => $this->refresh($profit, $lockedOrder));
    }

    /**
     * {@see sync()}, once the surrounding transaction has committed — what the
     * delivery, payment and return paths call. A failure here is logged and
     * never undoes the change that triggered it; the minute sweep catches up.
     */
    public function syncAfterCommit(Order $order): void
    {
        if ($order->source !== OrderSource::Website) {
            return;
        }

        $orderId = $order->id;

        DB::afterCommit(function () use ($orderId): void {
            try {
                $fresh = Order::query()->find($orderId);

                if ($fresh !== null) {
                    $this->sync($fresh);
                }
            } catch (Throwable $throwable) {
                $this->log->channel('wallet')->error('A Storefront profit could not be brought up to date', [
                    'order' => $orderId,
                    'error' => $throwable->getMessage(),
                ]);
            }
        });
    }

    /**
     * Staff confirm the cash collected on delivery for a Storefront order.
     *
     * The whole order's collection, once: a second confirmation of the same
     * amount changes nothing; a different amount is refused. An amount other
     * than the order's total is recorded as collected, and the profit waits for
     * a person rather than being credited.
     */
    public function confirmCodCollection(User $actor, Order $order, Money $amount): PartnerStorefrontProfit
    {
        if ($order->source !== OrderSource::Website) {
            throw new InvalidArgumentException(__('storefront_profit.refused.not_storefront'));
        }

        if ($order->payment?->gateway_ownership !== null) {
            throw new InvalidArgumentException(__('storefront_profit.refused.not_cod'));
        }

        if ($amount->isNegative() || $amount->currency->value !== $order->currency_code) {
            throw new InvalidArgumentException(__('storefront_profit.refused.amount_invalid'));
        }

        return $this->locked($order, function (PartnerStorefrontProfit $profit, Order $lockedOrder) use ($actor, $amount) {
            if ($profit->collected_at !== null) {
                if ($profit->collected_amount?->equals($amount) !== true) {
                    throw new InvalidArgumentException(__('storefront_profit.refused.already_collected'));
                }

                return $profit;
            }

            $profit->forceFill([
                'collection_channel' => CollectionChannel::Cod,
                'collected_at' => CarbonImmutable::now(),
                'collected_amount' => $amount,
                'cod_confirmed_by' => $actor->id,
            ]);

            $this->audit->handle(new AuditEntry(
                action: 'storefront_profit.cod_collected',
                actorId: $actor->id,
                auditableType: PartnerStorefrontProfit::class,
                auditableId: $profit->id,
                after: ['order' => $lockedOrder->reference, 'amount' => $amount->toDecimal(), 'order_total' => $lockedOrder->total->toDecimal()],
                accountId: $profit->business_account_id,
                module: PermissionModule::Order->value,
                isSensitive: ! $amount->equals($lockedOrder->total),
            ));

            return $this->refresh($profit, $lockedOrder);
        });
    }

    /**
     * The sweep's step for one profit: credit it if its hold has ended and
     * nothing blocks it. A technical failure marks it failed for staff to retry.
     */
    public function release(int $profitId, ?CarbonImmutable $now = null): PartnerStorefrontProfit
    {
        $now ??= CarbonImmutable::now();
        $orderId = (int) PartnerStorefrontProfit::query()->whereKey($profitId)->value('order_id');

        /** @var Order $order */
        $order = Order::query()->findOrFail($orderId);

        try {
            return $this->locked($order, function (PartnerStorefrontProfit $profit, Order $lockedOrder) use ($now) {
                $this->refresh($profit, $lockedOrder, $now);

                if ($profit->status === StorefrontProfitStatus::Holding && $profit->release_at !== null && $profit->release_at->lessThanOrEqualTo($now)) {
                    $this->credit($profit, $lockedOrder);
                }

                return $profit;
            });
        } catch (Throwable $throwable) {
            return $this->fail($profitId, $throwable);
        }
    }

    /**
     * Staff ask for a failed credit to be tried again. Only a technical failure
     * can be retried; anything else is decided by the profit's own facts.
     */
    public function retry(User $actor, PartnerStorefrontProfit $profit): PartnerStorefrontProfit
    {
        if ($profit->status !== StorefrontProfitStatus::Failed) {
            throw new InvalidArgumentException(__('storefront_profit.refused.not_failed'));
        }

        $this->database->transaction(function () use ($actor, $profit): void {
            /** @var PartnerStorefrontProfit $locked */
            $locked = PartnerStorefrontProfit::query()->lockForUpdate()->findOrFail($profit->id);

            if ($locked->status !== StorefrontProfitStatus::Failed) {
                return;
            }

            $locked->transitionTo(StorefrontProfitStatus::Holding);
            $locked->forceFill(['status_reason' => null])->save();

            $this->audit->handle(new AuditEntry(
                action: 'storefront_profit.retry_requested',
                actorId: $actor->id,
                auditableType: PartnerStorefrontProfit::class,
                auditableId: $locked->id,
                before: ['status' => StorefrontProfitStatus::Failed->value, 'failure' => $locked->failure_message],
                accountId: $locked->business_account_id,
                module: PermissionModule::Order->value,
            ));
        });

        return $this->release($profit->id);
    }

    /**
     * Hold a profit for a person: nothing is credited until it is cleared.
     * Only before anything was credited; a repeat changes nothing.
     */
    public function placeReviewHold(PartnerStorefrontProfit $profit, string $reason): PartnerStorefrontProfit
    {
        return $this->locked($profit->order, function (PartnerStorefrontProfit $locked, Order $order) use ($reason) {
            if ($locked->review_hold === null && $locked->status->isUnsettled()) {
                $locked->forceFill(['review_hold' => $reason, 'review_hold_at' => CarbonImmutable::now()])->save();

                $this->audit->handle(new AuditEntry(
                    action: 'storefront_profit.review_hold_placed',
                    actorType: 'system',
                    auditableType: PartnerStorefrontProfit::class,
                    auditableId: $locked->id,
                    after: ['order' => $order->reference, 'reason' => $reason],
                    accountId: $locked->business_account_id,
                    module: PermissionModule::Order->value,
                    isSensitive: true,
                ));
            }

            return $this->refresh($locked, $order);
        });
    }

    /**
     * Staff have looked and the profit stands: the hold comes off and the
     * profit follows its facts again — credited by the sweep when due, once,
     * under the order's own key.
     */
    public function clearReviewHold(User $actor, PartnerStorefrontProfit $profit, string $reason): PartnerStorefrontProfit
    {
        $reason = trim($reason);

        if (mb_strlen($reason) < 10) {
            throw new InvalidArgumentException(__('validation.min.string', ['attribute' => 'reason', 'min' => 10]));
        }

        return $this->locked($profit->order, function (PartnerStorefrontProfit $locked, Order $order) use ($actor, $reason) {
            if ($locked->review_hold === null) {
                return $locked;
            }

            $before = $locked->review_hold;
            $locked->forceFill(['review_hold' => null, 'review_hold_at' => null])->save();

            $this->audit->handle(new AuditEntry(
                action: 'storefront_profit.review_hold_cleared',
                actorId: $actor->id,
                auditableType: PartnerStorefrontProfit::class,
                auditableId: $locked->id,
                before: ['review_hold' => $before],
                reason: $reason,
                accountId: $locked->business_account_id,
                module: PermissionModule::Order->value,
                isSensitive: true,
            ));

            return $this->refresh($locked, $order);
        });
    }

    /**
     * Take an owed reversal when the Wallet can carry it.
     */
    public function recover(PartnerStorefrontProfitReversal $reversal): PartnerStorefrontProfitReversal
    {
        $this->database->transaction(function () use ($reversal): void {
            /** @var PartnerStorefrontProfit $profit */
            $profit = PartnerStorefrontProfit::query()->lockForUpdate()->findOrFail($reversal->partner_storefront_profit_id);

            /** @var PartnerStorefrontProfitReversal $locked */
            $locked = PartnerStorefrontProfitReversal::query()->lockForUpdate()->findOrFail($reversal->id);

            if ($locked->status === PartnerStorefrontProfitReversal::STATUS_OWED) {
                $this->postReversal($profit, $locked);
            }

            $reversal->setRawAttributes($locked->getAttributes(), sync: true);
        });

        return $reversal;
    }

    /**
     * Lock the order and its profit (created on first use) and run `$work`.
     *
     * @template T
     *
     * @param  callable(PartnerStorefrontProfit, Order): T  $work
     * @return T
     */
    protected function locked(Order $order, callable $work): mixed
    {
        $attempt = function () use ($order, $work) {
            return $this->database->transaction(function () use ($order, $work) {
                /** @var Order $lockedOrder */
                $lockedOrder = Order::query()->lockForUpdate()->findOrFail($order->id);

                $profit = PartnerStorefrontProfit::query()->where('order_id', $lockedOrder->id)->lockForUpdate()->first()
                    ?? PartnerStorefrontProfit::create([
                        'order_id' => $lockedOrder->id,
                        'business_account_id' => $lockedOrder->business_account_id,
                        'currency_code' => $lockedOrder->currency_code,
                        'status' => StorefrontProfitStatus::AwaitingFacts,
                        'released_amount' => Money::zero(Currency::from($lockedOrder->currency_code)),
                        'reversed_amount' => Money::zero(Currency::from($lockedOrder->currency_code)),
                        'legacy_credited_amount' => Money::zero(Currency::from($lockedOrder->currency_code)),
                    ]);

                return $work($profit, $lockedOrder);
            });
        };

        try {
            return $attempt();
        } catch (UniqueConstraintViolationException $exception) {
            // Two first events for the same order raced; the unique index kept one row.
            if (PartnerStorefrontProfit::query()->where('order_id', $order->id)->doesntExist()) {
                throw $exception;
            }

            return $attempt();
        }
    }

    /**
     * Read the facts, work the figures out again and decide where the profit
     * stands. Credits nothing; posts a compensating debit only for a profit that
     * was already credited and has since shrunk.
     */
    protected function refresh(PartnerStorefrontProfit $profit, Order $order, ?CarbonImmutable $now = null): PartnerStorefrontProfit
    {
        $before = $profit->status;
        $currency = Currency::from($order->currency_code);

        $this->readFacts($profit, $order);

        $lines = $this->calculator->lines($order);
        $this->writeLines($profit, $lines);

        $proven = collect($lines)->every(fn (array $line) => $line['cost'] !== null);
        $selling = collect($lines)->reduce(fn (Money $sum, array $line) => $sum->plus($line['selling']), Money::zero($currency));
        $cost = $proven ? collect($lines)->reduce(fn (Money $sum, array $line) => $sum->plus($line['cost'] ?? Money::zero($currency)), Money::zero($currency)) : null;

        $profit->forceFill([
            'selling_amount' => $selling,
            'cost_amount' => $cost,
            'profit_amount' => $cost === null ? null : $selling->minus($cost),
            'legacy_credited_amount' => $this->legacyCredited($order, $currency),
            'breakdown' => $this->breakdown($order, $lines, $selling, $cost),
        ]);

        if ($profit->status === StorefrontProfitStatus::Released) {
            $this->takeBackWhatNoLongerStands($profit, $order);
            $profit->save();

            return $profit;
        }

        if ($profit->status->isTerminal()) {
            $profit->save();

            return $profit;
        }

        [$status, $reason] = $this->decide($profit, $order);

        // A technical failure stays failed until staff retry it — unless the
        // order's own facts have since ruled the credit out.
        if ($before === StorefrontProfitStatus::Failed && in_array($status, [StorefrontProfitStatus::Holding, StorefrontProfitStatus::AwaitingFacts], true)) {
            $status = StorefrontProfitStatus::Failed;
            $reason = 'technical_failure';
        }

        // Exactly 24 hours after the later of the authoritative Delivered time
        // and the authoritative collection time; a later block or review does
        // not move it.
        $profit->forceFill([
            'status_reason' => $reason,
            'release_at' => $profit->delivered_at !== null && $profit->collected_at !== null
                ? $profit->delivered_at->max($profit->collected_at)->addHours(PartnerStorefrontProfit::HOLD_HOURS)
                : null,
        ]);

        if ($status !== $before) {
            $profit->transitionTo($status);
        }

        $profit->save();

        if ($status !== $before) {
            $this->announce($profit, $before);
        }

        return $profit;
    }

    /**
     * The whole calculation, frozen with the profit so it can be shown and
     * checked: what the customer pays, what Banij keeps (the units' frozen
     * Product Base Price and the order's frozen delivery charge), what is tax,
     * and what is left for the Partner. Delivery is never on the Partner's side
     * of the sum, so it is never taken off twice.
     *
     * @param  list<array{item: OrderItem, basis: string, quantity: int, returned: int, selling: Money, cost: Money|null, profit: Money|null, unproven: string|null}>  $lines
     * @return array<string, mixed>
     */
    protected function breakdown(Order $order, array $lines, Money $selling, ?Money $cost): array
    {
        $delivery = $order->delivery;
        $deliveryTax = $order->delivery_tax ?? Money::zero($delivery->currency);

        return [
            'customer_total' => $order->total->toDecimal(),
            'discount' => $order->discount->toDecimal(),
            'product_tax_added' => $order->tax->toDecimal(),
            'product_tax_included' => $order->tax_included->toDecimal(),
            'delivery_charge_retained' => $delivery->toDecimal(),
            'delivery_tax' => $deliveryTax->toDecimal(),
            'net_product_selling' => $selling->toDecimal(),
            'product_base_price_total' => $cost?->toDecimal(),
            'banij_receivable' => $cost?->plus($delivery)->toDecimal(),
            'partner_profit' => $cost === null ? null : $selling->minus($cost)->toDecimal(),
            'lines' => array_map(fn (array $line) => [
                'line' => $line['item']->public_id,
                'basis' => $line['basis'],
                'quantity' => $line['quantity'],
                'returned' => $line['returned'],
                'net_selling' => $line['selling']->toDecimal(),
                'product_base_price' => $line['cost']?->toDecimal(),
                'unproven' => $line['unproven'],
            ], $lines),
        ];
    }

    /**
     * The two facts, each from its own authoritative record.
     */
    protected function readFacts(PartnerStorefrontProfit $profit, Order $order): void
    {
        // Delivered: when the delivery history first recorded it.
        $deliveredAt = OrderDeliveryStatusChange::query()
            ->where('order_id', $order->id)
            ->where('new_status', OrderDeliveryStatus::Delivered->value)
            ->orderBy('changed_at')
            ->orderBy('id')
            ->value('changed_at');

        $profit->forceFill(['delivered_at' => $deliveredAt === null ? null : CarbonImmutable::parse($deliveredAt)]);

        // Paid online: the payment's own frozen ownership decides the channel.
        $payment = $order->payment;
        $channel = CollectionChannel::forOwnership($payment?->gateway_ownership);

        if ($payment !== null && $channel !== null) {
            $profit->forceFill(['collection_channel' => $channel]);

            if ($payment->status->isSettled() && $profit->collected_at === null) {
                $profit->forceFill([
                    'collected_at' => $payment->completed_at ?? CarbonImmutable::now(),
                    'collected_amount' => $payment->amount,
                ]);
            }

            return;
        }

        // Cash on delivery already confirmed by staff on the earlier, per-line
        // settlement: authoritative only when every line of the order carries
        // it — then the order's collection is when the last line was confirmed,
        // and how much is what they add up to.
        if ($profit->collected_at === null) {
            $lines = OrderProceedsSettlement::query()->where('order_id', $order->id)->get();
            $itemIds = $order->items()->pluck('id');

            if ($itemIds->isNotEmpty()
                && $lines->count() === $itemIds->count()
                && $lines->every(fn (OrderProceedsSettlement $line) => $line->cod_collected_at !== null && $line->cod_amount_collected !== null)) {
                $currency = Currency::from($order->currency_code);

                $profit->forceFill([
                    'collection_channel' => CollectionChannel::Cod,
                    'collected_at' => $lines->max('cod_collected_at'),
                    'collected_amount' => $lines->reduce(fn (Money $sum, OrderProceedsSettlement $line) => $sum->plus($line->cod_amount_collected ?? Money::zero($currency)), Money::zero($currency)),
                ]);
            }
        }
    }

    /**
     * @param  list<array{item: OrderItem, basis: string, quantity: int, returned: int, selling: Money, cost: Money|null, profit: Money|null, unproven: string|null}>  $lines
     */
    protected function writeLines(PartnerStorefrontProfit $profit, array $lines): void
    {
        foreach ($lines as $line) {
            PartnerStorefrontProfitLine::query()->updateOrCreate(
                ['order_item_id' => $line['item']->id],
                [
                    'partner_storefront_profit_id' => $profit->id,
                    'currency_code' => $line['selling']->currency->value,
                    'cost_basis' => $line['basis'],
                    'quantity' => $line['quantity'],
                    'returned_quantity' => $line['returned'],
                    'selling_amount' => $line['selling'],
                    'cost_amount' => $line['cost'],
                    'profit_amount' => $line['profit'],
                    'unproven_reason' => $line['unproven'],
                ],
            );
        }
    }

    /**
     * @return array{0: StorefrontProfitStatus, 1: string|null}
     */
    protected function decide(PartnerStorefrontProfit $profit, Order $order): array
    {
        if ($order->cancelled_at !== null) {
            return [StorefrontProfitStatus::Cancelled, 'order_cancelled'];
        }

        if ($profit->collection_channel === CollectionChannel::PartnerGatewayExternal) {
            return [StorefrontProfitStatus::ReportedOnly, 'partner_gateway'];
        }

        if ($profit->delivered_at === null) {
            return [StorefrontProfitStatus::AwaitingFacts, 'awaiting_delivery'];
        }

        if ($profit->collected_at === null) {
            return [StorefrontProfitStatus::AwaitingFacts, 'awaiting_collection'];
        }

        if (($blocker = $this->blocker($order)) !== null) {
            return [StorefrontProfitStatus::Blocked, $blocker];
        }

        // Something a person must look at that the figures cannot clear: an
        // earlier credit that may already be this profit, or a Delivered time
        // the records cannot prove.
        if ($profit->review_hold !== null) {
            return [StorefrontProfitStatus::ReviewRequired, $profit->review_hold];
        }

        if ($profit->collected_amount?->equals($order->total) !== true) {
            return [StorefrontProfitStatus::ReviewRequired, 'collection_mismatch'];
        }

        if ($profit->profit_amount === null) {
            return [StorefrontProfitStatus::ReviewRequired, 'cost_unproven'];
        }

        if ($profit->profit_amount->isNegative()) {
            return [StorefrontProfitStatus::ReviewRequired, 'negative_profit'];
        }

        $due = $profit->profit_amount->minus($profit->legacy_credited_amount);

        if (! $due->isPositive()) {
            return [StorefrontProfitStatus::NoProfit, $profit->legacy_credited_amount->isPositive() ? 'already_credited' : 'zero_profit'];
        }

        return [StorefrontProfitStatus::Holding, null];
    }

    /**
     * What, if anything, keeps the profit from being credited now.
     */
    protected function blocker(Order $order): ?string
    {
        if (in_array($order->delivery_status, [OrderDeliveryStatus::ReturnRequested, OrderDeliveryStatus::Returned, OrderDeliveryStatus::Refunded], true)) {
            return 'delivery_reversed';
        }

        $openReturn = OrderReturn::query()
            ->where('order_id', $order->id)
            ->get(['id', 'status'])
            ->contains(fn (OrderReturn $return) => ! $return->status->isTerminal());

        if ($openReturn) {
            return 'return_open';
        }

        $openDamage = OrderDamage::query()
            ->where('order_id', $order->id)
            ->get(['id', 'status'])
            ->contains(fn (OrderDamage $damage) => $damage->status !== DamageStatus::Rejected && ! $damage->status->isDecided());

        if ($openDamage) {
            return 'damage_open';
        }

        if ($order->payment_id !== null && RefundRequest::query()
            ->where('payment_id', $order->payment_id)
            ->whereIn('status', [RefundStatus::Requested->value, RefundStatus::Approved->value])
            ->exists()) {
            return 'refund_open';
        }

        return null;
    }

    /**
     * Anything this order's lines already earned through the earlier, per-line
     * settlement — credited once, and never again by this one.
     */
    protected function legacyCredited(Order $order, Currency $currency): Money
    {
        return OrderProceedsSettlement::query()
            ->where('order_id', $order->id)
            ->whereNotNull('wallet_transaction_id')
            ->get()
            ->reduce(
                fn (Money $sum, OrderProceedsSettlement $settlement) => $sum->plus(($settlement->reseller_earning ?? Money::zero($currency))->minus($settlement->reversed_amount ?? Money::zero($currency))),
                Money::zero($currency),
            );
    }

    /**
     * Post the credit. Withdrawable money, under the order's own key.
     */
    protected function credit(PartnerStorefrontProfit $profit, Order $order): void
    {
        /** @var Money $amount */
        $amount = $profit->profit_amount?->minus($profit->legacy_credited_amount);

        $transaction = $this->wallet->credit(
            $this->wallets->handle($profit->businessAccount, Currency::from($profit->currency_code)),
            LedgerTransactionType::SalesCredit,
            $amount,
            new PostingContext(
                source: 'partner_storefront_profit',
                description: __('storefront_profit.ledger.credit', ['order' => $order->reference]),
                idempotencyKey: $profit->creditKey(),
            ),
            BalanceType::Withdrawable,
        );

        $profit->transitionTo(StorefrontProfitStatus::Released);
        $profit->forceFill([
            'status_reason' => null,
            'released_amount' => $amount,
            'released_at' => CarbonImmutable::now(),
            'wallet_transaction_id' => $transaction->id,
            'failure_message' => null,
        ])->save();

        $this->audit->handle(new AuditEntry(
            action: 'storefront_profit.released',
            actorType: 'system',
            auditableType: PartnerStorefrontProfit::class,
            auditableId: $profit->id,
            after: [
                'order' => $order->reference,
                'amount' => $amount->toDecimal(),
                'selling' => $profit->selling_amount?->toDecimal(),
                'cost' => $profit->cost_amount?->toDecimal(),
                'delivered_at' => $profit->delivered_at?->toIso8601String(),
                'collected_at' => $profit->collected_at?->toIso8601String(),
            ],
            accountId: $profit->business_account_id,
            module: PermissionModule::Order->value,
        ));

        $this->notifyPartner($profit, StorefrontProfitUpdated::RELEASED, $amount);
    }

    /**
     * After the credit: what the units now kept earn is all that may stay. The
     * rest comes back by a compensating debit, or is owed until it can.
     */
    protected function takeBackWhatNoLongerStands(PartnerStorefrontProfit $profit, Order $order): void
    {
        $currency = Currency::from($profit->currency_code);
        $entitled = $profit->profit_amount === null
            ? null
            : $profit->profit_amount->minus($profit->legacy_credited_amount);

        if ($entitled === null) {
            // The cost can no longer be proven; nothing is taken on a guess.
            $profit->forceFill(['status_reason' => 'review_required_after_release']);

            return;
        }

        if ($entitled->isNegative()) {
            $entitled = Money::zero($currency);
            $profit->forceFill(['status_reason' => 'negative_profit_after_release']);
        }

        $claimed = $profit->reversals()
            ->get()
            ->reduce(fn (Money $sum, PartnerStorefrontProfitReversal $reversal) => $sum->plus($reversal->amount), Money::zero($currency));

        $due = $profit->released_amount->minus($claimed)->minus($entitled);

        if (! $due->isPositive()) {
            return;
        }

        $sequence = $profit->reversals()->count() + 1;

        /** @var PartnerStorefrontProfitReversal $reversal */
        $reversal = PartnerStorefrontProfitReversal::create([
            'partner_storefront_profit_id' => $profit->id,
            'currency_code' => $profit->currency_code,
            'amount' => $due,
            'status' => PartnerStorefrontProfitReversal::STATUS_OWED,
            'cause' => $order->cancelled_at !== null ? 'cancellation' : 'return',
            'idempotency_key' => 'partner-storefront-profit-reversal:'.$order->public_id.':'.$sequence,
            'reason' => 'Units of this order came back after its profit was credited.',
        ]);

        $this->postReversal($profit, $reversal);
    }

    /**
     * Debit an owed reversal from withdrawable money — the kind the profit was
     * credited as — or leave it owed when the Wallet cannot carry all of it.
     */
    protected function postReversal(PartnerStorefrontProfit $profit, PartnerStorefrontProfitReversal $reversal): void
    {
        try {
            $transaction = $this->wallet->debit(
                $this->wallets->handle($profit->businessAccount, Currency::from($profit->currency_code)),
                LedgerTransactionType::ReturnAdjustment,
                $reversal->amount,
                new PostingContext(
                    source: 'partner_storefront_profit',
                    description: __('storefront_profit.ledger.reversal', ['order' => $profit->order->reference]),
                    idempotencyKey: $reversal->idempotency_key,
                    reason: $reversal->reason,
                    direction: LedgerDirection::Debit,
                    correctsLedgerEntryId: LedgerEntry::query()->where('wallet_transaction_id', $profit->wallet_transaction_id)->value('id'),
                ),
                BalanceType::Withdrawable,
            );
        } catch (WalletOperationRefused) {
            $this->log->channel('wallet')->warning('A Storefront profit reversal is owed: the Wallet cannot carry it yet', [
                'profit' => $profit->public_id,
                'reversal' => $reversal->public_id,
            ]);

            return;
        }

        $reversal->forceFill([
            'status' => PartnerStorefrontProfitReversal::STATUS_POSTED,
            'wallet_transaction_id' => $transaction->id,
            'posted_at' => CarbonImmutable::now(),
        ])->save();

        $profit->forceFill(['reversed_amount' => $profit->reversed_amount->plus($reversal->amount)]);

        if ($profit->reversed_amount->equals($profit->released_amount)) {
            $profit->transitionTo(StorefrontProfitStatus::Reversed);
        }

        $profit->save();

        $this->audit->handle(new AuditEntry(
            action: 'storefront_profit.reversed',
            actorType: 'system',
            auditableType: PartnerStorefrontProfit::class,
            auditableId: $profit->id,
            after: [
                'order' => $profit->order->reference,
                'amount' => $reversal->amount->toDecimal(),
                'reversed_total' => $profit->reversed_amount->toDecimal(),
                'released' => $profit->released_amount->toDecimal(),
            ],
            accountId: $profit->business_account_id,
            module: PermissionModule::Order->value,
        ));

        $this->notifyPartner($profit, StorefrontProfitUpdated::REVERSED, $reversal->amount);
    }

    /**
     * A technical failure: recorded on the profit, in its own transaction after
     * the failed one rolled back, for staff to retry.
     */
    protected function fail(int $profitId, Throwable $throwable): PartnerStorefrontProfit
    {
        $this->log->channel('wallet')->error('A Storefront profit could not be credited', [
            'profit' => $profitId,
            'error' => $throwable->getMessage(),
        ]);

        return $this->database->transaction(function () use ($profitId, $throwable) {
            /** @var PartnerStorefrontProfit $locked */
            $locked = PartnerStorefrontProfit::query()->lockForUpdate()->findOrFail($profitId);

            if (! $locked->status->isUnsettled()) {
                return $locked;
            }

            $before = $locked->status;

            if ($locked->status !== StorefrontProfitStatus::Failed) {
                $locked->transitionTo(StorefrontProfitStatus::Failed);
            }

            $locked->forceFill([
                'status_reason' => 'technical_failure',
                'attempts' => $locked->attempts + 1,
                'last_attempt_at' => CarbonImmutable::now(),
                'failure_message' => mb_substr(class_basename($throwable).': '.$throwable->getMessage(), 0, 500),
            ])->save();

            if ($before !== StorefrontProfitStatus::Failed) {
                $this->notifyStaff($locked);
            }

            return $locked;
        });
    }

    protected function announce(PartnerStorefrontProfit $profit, StorefrontProfitStatus $before): void
    {
        $this->audit->handle(new AuditEntry(
            action: 'storefront_profit.status_changed',
            actorType: 'system',
            auditableType: PartnerStorefrontProfit::class,
            auditableId: $profit->id,
            before: ['status' => $before->value],
            after: [
                'status' => $profit->status->value,
                'reason' => $profit->status_reason,
                'profit' => $profit->profit_amount?->toDecimal(),
                'release_at' => $profit->release_at?->toIso8601String(),
            ],
            accountId: $profit->business_account_id,
            module: PermissionModule::Order->value,
        ));

        match ($profit->status) {
            StorefrontProfitStatus::Holding => $this->notifyPartner($profit, StorefrontProfitUpdated::PENDING, $profit->profit_amount?->minus($profit->legacy_credited_amount)),
            StorefrontProfitStatus::ReviewRequired => $this->notifyStaff($profit),
            default => null,
        };
    }

    protected function notifyPartner(PartnerStorefrontProfit $profit, string $event, ?Money $amount): void
    {
        $owner = $profit->businessAccount->owner;

        if ($owner === null) {
            return;
        }

        $notification = new StorefrontProfitUpdated($profit, $event, $amount?->jsonSerialize());

        DB::afterCommit(fn () => $owner->notify($notification));
    }

    protected function notifyStaff(PartnerStorefrontProfit $profit): void
    {
        $permission = PermissionCatalogue::name(PermissionModule::Order, PermissionAction::Edit);

        $recipients = User::permission($permission)->get()
            ->merge(User::role(PlatformRole::SuperAdmin->value)->get())
            ->unique('id')
            ->filter(fn (User $user) => $user->hasPlatformAccess())
            ->values();

        if ($recipients->isEmpty()) {
            return;
        }

        $notification = new StorefrontProfitUpdated($profit, StorefrontProfitUpdated::REVIEW, $profit->profit_amount?->jsonSerialize());

        DB::afterCommit(fn () => Notification::send($recipients, $notification));
    }
}
