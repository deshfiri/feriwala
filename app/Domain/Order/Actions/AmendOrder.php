<?php

namespace App\Domain\Order\Actions;

use App\Domain\Access\Data\SensitiveActionRequest;
use App\Domain\Access\Enums\PermissionAction;
use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Access\Exceptions\SensitiveActionRefused;
use App\Domain\Access\PermissionCatalogue;
use App\Domain\Access\SensitiveActionGuard;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Billing\Data\QuoteLine;
use App\Domain\Billing\Enums\AllocationType;
use App\Domain\Billing\Enums\PaymentStatus;
use App\Domain\Billing\Enums\Refundability;
use App\Domain\Billing\Enums\RefundStatus;
use App\Domain\Billing\Models\Payment;
use App\Domain\Billing\Models\RefundRequest;
use App\Domain\Catalog\Models\ProductAttributeValue;
use App\Domain\Catalog\WholesalePriceResolver;
use App\Domain\Inventory\Data\MovementContext;
use App\Domain\Inventory\Enums\ReservationKind;
use App\Domain\Inventory\Enums\StockBucket;
use App\Domain\Inventory\Enums\StockMovementType;
use App\Domain\Inventory\Enums\StockReservationStatus;
use App\Domain\Inventory\Exceptions\InventoryRefused;
use App\Domain\Inventory\Models\StockItem;
use App\Domain\Inventory\Models\StockReservation;
use App\Domain\Inventory\PartnerLotWarehouseScope;
use App\Domain\Inventory\Queries\PartnerStockSingleWarehouse;
use App\Domain\Inventory\StockLedger;
use App\Domain\Inventory\StockReservations;
use App\Domain\Order\Data\OrderAmendment;
use App\Domain\Order\Data\RevisableOrderState;
use App\Domain\Order\Data\WebsiteOrderLine;
use App\Domain\Order\Data\WebsiteOrderPaymentQuote;
use App\Domain\Order\Data\WebsiteOrderQuote;
use App\Domain\Order\Enums\OrderRevisionSection;
use App\Domain\Order\Enums\OrderRevisionStatus;
use App\Domain\Order\Enums\OrderSource;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Enums\RevisionAdjustmentKind;
use App\Domain\Order\Enums\RevisionCourierConsequence;
use App\Domain\Order\Enums\RevisionLineChange;
use App\Domain\Order\Enums\RevisionPaymentConsequence;
use App\Domain\Order\Exceptions\OrderRevisionRefused;
use App\Domain\Order\Exceptions\WebsiteOrderRefused;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderHoldReservation;
use App\Domain\Order\Models\OrderItem;
use App\Domain\Order\Models\OrderRevision;
use App\Domain\Order\Models\OrderRevisionAdjustment;
use App\Domain\Order\Models\OrderRevisionLine;
use App\Domain\Order\Queries\OrderRevisionEligibility;
use App\Domain\Order\Queries\PriceWebsiteOrder;
use App\Domain\Order\Queries\StorefrontProfitCalculator;
use App\Domain\Package\Entitlements;
use App\Domain\Website\CodTerms;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsiteProduct;
use App\Domain\Website\Queries\ResolveWebsitePriceRule;
use App\Models\User;
use App\Support\Concurrency\DistributedLock;
use App\Support\Concurrency\Exceptions\LockTimeout;
use App\Support\Money\Currency;
use App\Support\Money\DecimalAmount;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * The Order Details editor's one save (D38, amended 2026-11-26): customer,
 * addresses, note and — for a Storefront order — lines, unit prices, the order
 * discount and the delivery charge, as one applied, append-only revision.
 *
 * Under a lock on the order and in one transaction:
 *
 *   - the order is locked, held to the revision staff saw, and checked against
 *     the state rules — nothing a courier already holds is changed here;
 *   - the order is priced again on the server ({@see PriceWebsiteOrder::requote()}):
 *     kept lines at their frozen price unless staff set another, added lines at
 *     the website's price today, the discount shared exactly, tax per line;
 *   - every changed line is superseded, never edited: its stock is released
 *     through {@see StockReservations}, the new line's stock is held the way
 *     placement holds it (the Partner's own units first, central stock for a
 *     Non-Conditional Partner), and both are recorded as revision lines;
 *   - the money follows: a payment nobody has paid yet is re-recorded at the new
 *     total, a settled payment that is now too much gets a refund request for
 *     exactly the difference; a settled payment never grows here, and a
 *     Partner-gateway order's money never changes here at all (D36);
 *   - the order's columns change only to the revision's final snapshot, which
 *     the database verifies, and the change is audited without customer data;
 *   - the Partner's Storefront profit is brought up to date once committed.
 *
 * {@see preview()} runs exactly this and rolls it back, so what staff are shown
 * before saving is what saving would do.
 */
class AmendOrder
{
    /** Order statuses in which the customer has not yet confirmed or paid. */
    private const UNCONFIRMED = [
        OrderStatus::Draft, OrderStatus::New, OrderStatus::PendingConfirmation,
        OrderStatus::CustomerVerificationPending, OrderStatus::PaymentPending,
    ];

    public function __construct(
        protected DatabaseManager $database,
        protected DistributedLock $lock,
        protected OrderRevisionEligibility $eligibility,
        protected RecordAuditLog $audit,
        protected ReviseOrderDetails $details,
        protected PriceWebsiteOrder $pricer,
        protected StockReservations $reservations,
        protected PartnerStockSingleWarehouse $singleWarehouse,
        protected PartnerLotWarehouseScope $lotScope,
        protected WholesalePriceResolver $wholesalePrices,
        protected ResolveWebsitePriceRule $priceRules,
        protected Entitlements $entitlements,
        protected CodTerms $codTerms,
        protected SettleStorefrontProfit $settle,
        protected StorefrontProfitCalculator $calculator,
        protected SensitiveActionGuard $guard,
        protected StockLedger $ledger,
    ) {}

    /**
     * @throws OrderRevisionRefused
     * @throws AuthorizationException
     */
    public function handle(User $actor, Order $order, OrderAmendment $request): OrderRevision
    {
        if (mb_strlen(trim($request->details->reason)) < ReviseOrderDetails::MINIMUM_REASON) {
            throw OrderRevisionRefused::because('reason_required', 'reason');
        }

        try {
            return $this->lock->run(
                key: 'order-revision:'.$order->id,
                callback: fn () => $this->database->transaction(fn () => $this->apply($actor, $order, $request, preview: false)),
                ttlSeconds: 60,
                waitSeconds: 10,
            );
        } catch (LockTimeout) {
            throw OrderRevisionRefused::busy();
        } catch (UniqueConstraintViolationException) {
            return OrderRevision::query()->where('idempotency_key', $this->key($order, $request))->firstOr(
                fn () => throw OrderRevisionRefused::changed(),
            );
        }
    }

    /**
     * What saving would do, worked out by doing it and rolling it back.
     *
     * @return array<string, mixed>
     */
    public function preview(User $actor, Order $order, OrderAmendment $request): array
    {
        $connection = $this->database->connection();
        $connection->beginTransaction();

        try {
            $revision = $this->apply($actor, $order, $request, preview: true);

            return ['ok' => true, ...$this->summary($revision, Order::query()->findOrFail($order->id))];
        } catch (OrderRevisionRefused $refused) {
            return ['ok' => false, 'refusal' => $refused->refusal, 'field' => $refused->field, 'message' => $refused->getMessage()];
        } catch (AuthorizationException) {
            return ['ok' => false, 'refusal' => 'no_permission', 'field' => 'revision', 'message' => __('orders.revision.no_permission')];
        } finally {
            $connection->rollBack();
        }
    }

    protected function apply(User $actor, Order $order, OrderAmendment $request, bool $preview): OrderRevision
    {
        /** @var Order $locked */
        $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
        $key = $this->key($locked, $request);

        if (! $preview) {
            $existing = OrderRevision::query()->where('idempotency_key', $key)->first();

            if ($existing !== null) {
                return $existing;
            }
        }

        if ($locked->revision_number !== $request->details->baseRevision) {
            throw OrderRevisionRefused::changed();
        }

        $blocker = $this->eligibility->blocker($locked);

        if ($blocker !== null) {
            throw OrderRevisionRefused::because($blocker);
        }

        $before = RevisableOrderState::current($locked);
        $details = $request->details;

        $changes = array_filter([
            'customer' => $this->details->corrected($before['customer'] ?? null, $details->customer, ReviseOrderDetails::CUSTOMER_FIELDS),
            'shipping_address' => $this->details->corrected($before['shipping_address'] ?? null, $details->shippingAddress, ReviseOrderDetails::ADDRESS_FIELDS),
            'billing_address' => $this->details->corrected($before['billing_address'] ?? null, $details->billingAddress, ReviseOrderDetails::ADDRESS_FIELDS),
        ], fn (?array $value) => $value !== null);

        $plan = $request->changesMoney() ? $this->plan($locked, $request) : null;

        if ($plan !== null) {
            $quote = $plan['quote'];
            $changes = [
                ...$changes,
                'subtotal' => $quote->subtotal,
                'discount' => $quote->discount,
                'delivery' => $quote->delivery,
                'delivery_tax' => $quote->deliveryTax->tax,
                'delivery_tax_mode' => $quote->deliveryTax->mode->value,
                'tax' => $quote->tax->addedTotal(),
                'tax_included' => $quote->tax->includedTotal(),
                'total' => $quote->total,
            ];
        }

        $after = RevisableOrderState::with($before, $changes);
        $changed = RevisableOrderState::changedColumns($before, $after);
        $noteBefore = $locked->customer_note;
        $noteAfter = $details->noteGiven ? $this->details->clean($details->customerNote) : $noteBefore;
        $noteChanged = $noteAfter !== $noteBefore;
        $lineChanges = $plan === null ? [] : array_values(array_filter($plan['entries'], fn (array $entry) => $entry['change'] !== null));

        if ($changed === [] && ! $noteChanged && $lineChanges === []) {
            throw OrderRevisionRefused::nothingChanged();
        }

        $sections = $this->sections($changed, $noteChanged, $lineChanges, $before, $after);
        $this->authorize($actor, $sections, $request, $preview);

        $currency = Currency::from($locked->currency_code);
        $totalBefore = $locked->total;
        $totalAfter = Money::fromDecimal((string) $after['total'], $currency);
        $consequence = $this->paymentConsequence($locked, $totalBefore, $totalAfter);
        $now = CarbonImmutable::now();

        $revision = OrderRevision::create([
            'order_id' => $locked->id,
            'revision_number' => $locked->revision_number + 1,
            'status' => OrderRevisionStatus::Applied,
            'sections' => array_map(fn (OrderRevisionSection $section) => $section->value, $sections),
            'requested_by' => $actor->id,
            'applied_by' => $actor->id,
            'reason' => $preview && mb_strlen(trim($details->reason)) < ReviseOrderDetails::MINIMUM_REASON ? 'Preview of an amendment' : trim($details->reason),
            'internal_note' => $this->details->clean($details->internalNote),
            'before_snapshot' => ['order' => $before, 'customer_note' => $noteBefore, 'lines' => $this->lineSnapshot($locked)],
            'requested_snapshot' => $request->toSnapshot(),
            'final_snapshot' => ['order' => $after, 'customer_note' => $noteAfter, 'lines' => []],
            'currency_code' => $currency->value,
            'total_before' => $totalBefore,
            'total_after' => $totalAfter,
            'total_difference' => $totalAfter->minus($totalBefore),
            'stock_difference' => $plan === null ? [] : $this->stockDifference($plan['entries']),
            'payment_consequence' => $consequence,
            'courier_consequence' => array_intersect(['shipping_address', 'billing_address'], $changed) !== [] || $lineChanges !== []
                ? RevisionCourierConsequence::NotBooked
                : RevisionCourierConsequence::None,
            'fingerprint' => hash('sha256', json_encode([$after, $noteAfter, $request->lines], JSON_THROW_ON_ERROR)),
            'idempotency_key' => $preview ? 'preview:'.Str::uuid() : $key,
            'requested_at' => $now,
            'applied_at' => $now,
        ]);

        if ($plan !== null && $lineChanges !== []) {
            $this->replaceLines($locked, $revision, $plan['entries'], $plan['website']);
        }

        if ($consequence !== RevisionPaymentConsequence::None && $plan !== null) {
            $this->settleMoney($actor, $locked, $revision, $consequence, $plan['quote'], $totalBefore, $totalAfter);
        }

        $attributes = RevisableOrderState::attributes($after, $currency->value);

        $locked->forceFill([
            ...array_intersect_key($attributes, array_flip($changed)),
            'customer_note' => $noteAfter,
            'revision_number' => $revision->revision_number,
        ])->save();

        if (! $preview) {
            $this->audit->handle(new AuditEntry(
                action: 'order.revised',
                actorId: $actor->id,
                auditableType: Order::class,
                auditableId: $locked->id,
                // References, fields and amounts — never the customer's name,
                // phone, email or address themselves.
                before: ['reference' => $locked->reference, 'revision_number' => $revision->revision_number - 1, 'total' => $totalBefore->toDecimal()],
                after: [
                    'reference' => $locked->reference,
                    'revision' => $revision->public_id,
                    'revision_number' => $revision->revision_number,
                    'sections' => $revision->sections,
                    'fields' => [...$changed, ...($noteChanged ? ['customer_note'] : [])],
                    'lines' => array_map(fn (array $entry) => ['sku' => $entry['sku'], 'change' => $entry['change']->value], $lineChanges),
                    'total' => $totalAfter->toDecimal(),
                    'total_difference' => $totalAfter->minus($totalBefore)->toDecimal(),
                    'payment_consequence' => $revision->payment_consequence->value,
                    'courier_consequence' => $revision->courier_consequence->value,
                ],
                reason: $revision->reason,
                accountId: $locked->business_account_id,
                module: 'order',
                isSensitive: true,
            ));

            $this->settle->syncAfterCommit($locked);
        }

        return $revision;
    }

    /**
     * The order's lines and amounts as staff asked for them, priced on the
     * server, and what that means for each line.
     *
     * @return array{website: Website, quote: WebsiteOrderQuote, entries: list<array{item: OrderItem|null, line: WebsiteOrderLine|null, change: RevisionLineChange|null, sku: string}>}
     */
    protected function plan(Order $order, OrderAmendment $request): array
    {
        if ($order->source !== OrderSource::Website || $order->website_id === null) {
            throw OrderRevisionRefused::because('items_unsupported', 'lines');
        }

        $payment = $order->payment;

        if ($payment?->isPartnerOwned() === true
            || OrderHoldReservation::query()->whereIn('order_item_id', $order->items()->select('id'))->exists()) {
            throw OrderRevisionRefused::because('partner_gateway', 'lines');
        }

        /** @var Website $website */
        $website = Website::query()->with('businessAccount')->findOrFail($order->website_id);
        $currency = Currency::from($order->currency_code);
        $items = $order->items()->with(['product.category.parent', 'variant', 'stockReservation'])->orderBy('line_number')->get()->keyBy('public_id');

        $requested = $request->lines ?? $items->map(fn (OrderItem $item) => [
            'id' => $item->public_id, 'sku' => null, 'quantity' => $item->quantity, 'unit_price' => null,
        ])->values()->all();

        $wanted = [];
        $seen = [];

        foreach ($requested as $line) {
            if ($line['quantity'] < 1) {
                throw OrderRevisionRefused::because('quantity_invalid', 'lines');
            }

            $unitPrice = $this->amount($line['unit_price'], $currency, 'lines');

            if ($line['id'] !== null) {
                /** @var OrderItem|null $item */
                $item = $items->get($line['id']);

                if ($item === null || isset($seen[$line['id']])) {
                    throw OrderRevisionRefused::because('line_unknown', 'lines');
                }

                $seen[$line['id']] = true;
                $selection = $item->website_product_id === null ? null : WebsiteProduct::query()->find($item->website_product_id);

                if ($selection === null || $item->product === null) {
                    throw OrderRevisionRefused::because('line_unknown', 'lines');
                }

                $wanted[] = [
                    'item' => $item,
                    'request' => ['sku' => $item->sku, 'quantity' => $line['quantity'], 'unit_price' => $unitPrice ?? $item->unit_price, 'kept' => ['selection' => $selection, 'product' => $item->product, 'variant' => $item->variant]],
                ];

                continue;
            }

            $sku = trim((string) $line['sku']);

            if ($sku === '') {
                throw OrderRevisionRefused::because('line_unknown', 'lines');
            }

            $wanted[] = ['item' => null, 'request' => ['sku' => $sku, 'quantity' => $line['quantity'], 'unit_price' => $unitPrice, 'kept' => null]];
        }

        if ($wanted === []) {
            throw OrderRevisionRefused::because('no_lines', 'lines');
        }

        $discount = $this->amount($request->discount, $currency, 'discount') ?? $order->discount;
        $delivery = $this->amount($request->delivery, $currency, 'delivery') ?? $order->delivery;

        try {
            $quote = $this->pricer->requote(
                $website,
                array_map(fn (array $entry) => $entry['request'], $wanted),
                $discount,
                $delivery,
                taxAt: $order->placed_at ?? CarbonImmutable::now(),
            );
        } catch (WebsiteOrderRefused $refused) {
            throw new OrderRevisionRefused('pricing_refused', __('orders.revision.refused.pricing_refused', ['message' => $refused->getMessage()]), 'lines');
        }

        $entries = [];

        foreach ($wanted as $position => $entry) {
            $line = $quote->lines[$position];
            $item = $entry['item'];

            $entries[] = [
                'item' => $item,
                'line' => $line,
                'sku' => $line->sku,
                'change' => match (true) {
                    $item === null => RevisionLineChange::Added,
                    $item->quantity !== $line->quantity => RevisionLineChange::QuantityChanged,
                    ! $this->sameLine($item, $line) => RevisionLineChange::PriceChanged,
                    default => null,
                },
            ];
        }

        foreach ($items as $publicId => $item) {
            if (! isset($seen[$publicId])) {
                $entries[] = ['item' => $item, 'line' => null, 'sku' => $item->sku, 'change' => RevisionLineChange::Removed];
            }
        }

        return ['website' => $website, 'quote' => $quote, 'entries' => $entries];
    }

    protected function sameLine(OrderItem $item, WebsiteOrderLine $line): bool
    {
        return $item->unit_price->equals($line->unitPrice)
            && $item->line_subtotal->equals($line->subtotal)
            && $item->discount->equals($line->discount)
            && $item->tax->equals($line->addedTax())
            && $item->tax_included->equals($line->includedTax())
            && $item->line_total->equals($line->total());
    }

    /**
     * Supersede every changed line: release what it held, hold stock for what
     * replaces it, write the new lines, and record both sides.
     *
     * @param  list<array{item: OrderItem|null, line: WebsiteOrderLine|null, change: RevisionLineChange|null, sku: string}>  $entries
     */
    protected function replaceLines(Order $order, OrderRevision $revision, array $entries, Website $website): void
    {
        $changed = array_values(array_filter($entries, fn (array $entry) => $entry['change'] !== null));
        $account = $website->businessAccount;
        $confirmed = ! in_array($order->status, self::UNCONFIRMED, true);
        $kind = $order->isCashOnDelivery() ? ReservationKind::CashOnDelivery : ReservationKind::OnlinePayment;

        foreach ($changed as $entry) {
            $reservation = $entry['item']?->stockReservation;

            if ($reservation === null) {
                continue;
            }

            if ($reservation->status === StockReservationStatus::Committed) {
                // Central units the confirmed order moved into processing, not
                // yet dispatched (the state rules guarantee that): they go back
                // to sale through the stock ledger, once, under a key. A
                // Partner's units are only committed at dispatch, so a
                // committed Partner hold means something left already.
                if ($reservation->isPartnerSourced() || $reservation->stock_item_id === null) {
                    throw OrderRevisionRefused::because('stock_committed', 'lines');
                }

                /** @var StockItem $stockItem */
                $stockItem = StockItem::query()->findOrFail($reservation->stock_item_id);

                $this->ledger->move(
                    $stockItem,
                    StockBucket::Processing,
                    StockBucket::Available,
                    $reservation->quantity,
                    StockMovementType::Adjustment,
                    new MovementContext(
                        reason: $order->reference.' revision '.$revision->revision_number.': line amended before dispatch',
                        sourceType: 'stock_reservation',
                        sourceId: $reservation->id,
                        idempotencyKey: 'order-revision:'.$revision->public_id.':returned:'.$reservation->id,
                    ),
                );

                $this->adjust($revision, RevisionAdjustmentKind::StockReturnedFromProcessing, null, null, $reservation, ['sku' => $entry['sku'], 'quantity' => $reservation->quantity]);

                continue;
            }

            if ($reservation->status === StockReservationStatus::Active) {
                $this->reservations->release($reservation, $order->reference.' revision '.$revision->revision_number.': line amended');

                $this->adjust($revision, RevisionAdjustmentKind::StockReleased, null, null, $reservation, ['sku' => $entry['sku'], 'quantity' => $reservation->quantity]);
            }
        }

        $introduced = array_values(array_filter($changed, fn (array $entry) => $entry['line'] !== null));
        $warehouse = $this->singleWarehouse->for($account, array_map(fn (array $entry) => [
            'product_id' => $entry['line']->product->id,
            'product_variant_id' => $entry['line']->variant?->id,
            'quantity' => $entry['line']->quantity,
        ], $introduced));

        $lineNumber = (int) $order->allItems()->max('line_number');
        $package = $this->entitlements->activePackage($account)?->package;

        foreach ($introduced as $entry) {
            /** @var WebsiteOrderLine $line */
            $line = $entry['line'];
            $lineNumber++;
            $reservation = $this->lotScope->within($warehouse, fn () => $this->hold($account, $line, $kind, $order->reference.'-R'.$revision->revision_number.'-L'.$lineNumber));

            if ($confirmed) {
                $reservation->isPartnerSourced()
                    ? $this->reservations->holdUntilDispatch($reservation)
                    : $this->reservations->commit($reservation);
            }

            $this->adjust($revision, RevisionAdjustmentKind::StockReserved, null, null, $reservation, ['sku' => $line->sku, 'quantity' => $line->quantity]);

            $previous = $entry['item'];
            $fromCentral = $account->isNonConditional() && ! $reservation->isPartnerSourced();
            $suggested = $previous->suggested_unit_price ?? $this->priceRules->for($line->product, $package)->suggested;
            $suggested = $suggested?->isPositive() === true ? $suggested : null;
            $taxed = ! $line->tax->isZero();

            /** @var OrderItem $item */
            $item = $order->allItems()->create([
                'line_number' => $lineNumber,
                'product_id' => $line->product->id,
                'product_variant_id' => $line->variant?->id,
                'website_product_id' => $line->selection->id,
                'sku' => $line->sku,
                'product_name' => $previous->product_name ?? Str::limit($line->product->name, 157),
                'variant_label' => $previous !== null ? $previous->variant_label : $line->variant?->values
                    ->sortBy(fn (ProductAttributeValue $value) => $value->attribute->sort_order)
                    ->pluck('value')
                    ->implode(' / '),
                'quantity' => $line->quantity,
                'currency_code' => $line->subtotal->currency->value,
                'unit_price' => $line->unitPrice,
                'line_subtotal' => $line->subtotal,
                'discount' => $line->discount,
                'tax' => $line->addedTax(),
                'tax_included' => $line->includedTax(),
                'line_total' => $line->total(),
                'product_cost' => $fromCentral ? $this->wholesalePrices->unitPrice($line->product, $line->variant, $line->quantity)->multipliedBy($line->quantity) : null,
                // The Product Base Price per unit, frozen at placement, carried
                // to the line that replaces it (D40).
                'platform_retained_amount' => $fromCentral && $suggested !== null ? $suggested->multipliedBy($line->quantity) : null,
                'suggested_unit_price' => $suggested,
                'tax_code' => $taxed ? $line->tax->code : null,
                'tax_rate_basis_points' => $taxed ? $line->tax->rateBasisPoints : null,
                'tax_mode' => $taxed ? $line->tax->mode->value : null,
                'stock_reservation_id' => $reservation->id,
                'introduced_by_revision_id' => $revision->id,
            ]);

            $this->recordLine($revision, $entry, $item);
        }

        foreach ($changed as $entry) {
            if ($entry['line'] === null) {
                $this->recordLine($revision, $entry, null);
            }
        }

        foreach ($changed as $entry) {
            $entry['item']?->forceFill(['superseded_by_revision_id' => $revision->id])->save();
        }
    }

    /**
     * Hold a line's stock exactly as placement does: the Partner's own units,
     * or central stock for a Non-Conditional Partner who has too few.
     */
    protected function hold(BusinessAccount $account, WebsiteOrderLine $line, ReservationKind $kind, string $reference): StockReservation
    {
        try {
            try {
                return $this->reservations->reserveFromPartner($account, $line->product, $line->variant, $line->quantity, $kind, $reference);
            } catch (InventoryRefused $refused) {
                if (! $account->isNonConditional()) {
                    throw $refused;
                }

                return $this->reservations->reserve($line->product, $line->variant, $line->quantity, $kind, $reference, $account);
            }
        } catch (InventoryRefused) {
            throw new OrderRevisionRefused('insufficient_stock', __('orders.revision.refused.insufficient_stock', ['sku' => $line->sku, 'quantity' => $line->quantity]), 'lines');
        } catch (LockTimeout) {
            throw OrderRevisionRefused::busy();
        }
    }

    /**
     * @param  array{item: OrderItem|null, line: WebsiteOrderLine|null, change: RevisionLineChange|null, sku: string}  $entry
     */
    protected function recordLine(OrderRevision $revision, array $entry, ?OrderItem $new): void
    {
        $previous = $entry['item'];
        $line = $entry['line'];

        OrderRevisionLine::create([
            'order_revision_id' => $revision->id,
            'change' => $entry['change'],
            'previous_order_item_id' => $previous?->id,
            'new_order_item_id' => $new?->id,
            'sku' => Str::limit($entry['sku'], 61),
            'product_name' => Str::limit($previous->product_name ?? $line?->product->name ?? $entry['sku'], 157),
            'variant_label' => $previous->variant_label ?? $new->variant_label ?? null,
            'quantity_before' => $previous?->quantity,
            'quantity_after' => $line?->quantity,
            'currency_code' => $revision->currency_code,
            'unit_price_before' => $previous?->unit_price,
            'unit_price_after' => $line?->unitPrice,
            'line_total_before' => $previous?->line_total,
            'line_total_after' => $line?->total(),
        ]);
    }

    /**
     * What the new total means for the money, decided before anything is
     * written — and refused when it cannot be followed safely.
     */
    protected function paymentConsequence(Order $order, Money $before, Money $after): RevisionPaymentConsequence
    {
        if ($before->equals($after)) {
            return RevisionPaymentConsequence::None;
        }

        $payment = $order->payment;

        if ($payment === null) {
            throw OrderRevisionRefused::because('payment_missing', 'lines');
        }

        if ($payment->isPartnerOwned()) {
            throw OrderRevisionRefused::because('partner_gateway', 'lines');
        }

        if ($payment->status === PaymentStatus::Draft) {
            if ($order->isCashOnDelivery()) {
                /** @var Website $website */
                $website = Website::query()->findOrFail($order->website_id);

                if (! $this->codTerms->allows($website, $after)) {
                    throw OrderRevisionRefused::because('cod_limit', 'lines');
                }

                return RevisionPaymentConsequence::CodAmountUpdated;
            }

            return RevisionPaymentConsequence::PaymentAmountUpdated;
        }

        if ($payment->status->isSettled()) {
            if ($before->lessThan($after)) {
                throw OrderRevisionRefused::because('paid_total_cannot_increase', 'lines');
            }

            $open = RefundRequest::query()
                ->where('payment_id', $payment->id)
                ->whereIn('status', [RefundStatus::Requested->value, RefundStatus::Approved->value])
                ->exists();

            if ($open) {
                throw OrderRevisionRefused::because('refund_open', 'lines');
            }

            return RevisionPaymentConsequence::RefundDue;
        }

        throw OrderRevisionRefused::because('payment_in_progress', 'lines');
    }

    protected function settleMoney(User $actor, Order $order, OrderRevision $revision, RevisionPaymentConsequence $consequence, WebsiteOrderQuote $quote, Money $before, Money $after): void
    {
        /** @var Payment $payment */
        $payment = Payment::query()->lockForUpdate()->findOrFail($order->payment_id);
        $difference = $before->lessThan($after) ? $after->minus($before) : $before->minus($after);
        $direction = $before->lessThan($after) ? OrderRevisionAdjustment::DIRECTION_IN : OrderRevisionAdjustment::DIRECTION_OUT;

        if ($consequence === RevisionPaymentConsequence::RefundDue) {
            /** @var RefundRequest $refund */
            $refund = RefundRequest::create([
                'payment_id' => $payment->id,
                'business_account_id' => $payment->business_account_id,
                'allocation_type' => AllocationType::WebsiteGoods,
                'amount' => $difference,
                'currency_code' => $difference->currency->value,
                'refundability' => Refundability::Always,
                'status' => RefundStatus::Requested,
                'reason' => sprintf('Order %s revised (revision %d): the total fell by %s.', $order->reference, $revision->revision_number, $difference->toDecimal()),
                'requested_by' => $actor->id,
            ]);

            $this->adjust($revision, RevisionAdjustmentKind::RefundRequest, $difference, $direction, $refund, ['payment' => $payment->public_id]);

            return;
        }

        // Nobody has paid yet: the payment is re-recorded at the new total,
        // component by component, exactly as placement records it.
        $paymentQuote = WebsiteOrderPaymentQuote::fromQuote($quote);
        $payment->allocations()->delete();
        $payment->taxLines()->delete();

        foreach ($paymentQuote->paymentLines() as $index => $line) {
            /** @var QuoteLine $line */
            $payment->allocations()->create([
                'type' => $line->type,
                'currency_code' => $line->amount->currency->value,
                'amount' => $line->amount,
                'description' => $line->description,
                'sort_order' => $index,
            ]);
        }

        foreach ($paymentQuote->taxBreakdown()->charges as $charge) {
            $payment->taxLines()->create($charge->toPaymentLine());
        }

        $payment->forceFill(['amount' => $paymentQuote->total(), 'revenue' => $paymentQuote->revenue()])->save();

        if (! $payment->amount->equals($after)) {
            throw new InvalidArgumentException('An amended order payment must come to the order total.');
        }

        $this->adjust($revision, RevisionAdjustmentKind::PaymentAmountUpdated, $difference, $direction, $payment, [
            'payment' => $payment->public_id, 'before' => $before->toDecimal(), 'after' => $after->toDecimal(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $detail
     */
    protected function adjust(OrderRevision $revision, RevisionAdjustmentKind $kind, ?Money $amount, ?string $direction, ?Model $subject, array $detail): void
    {
        OrderRevisionAdjustment::create([
            'order_revision_id' => $revision->id,
            'kind' => $kind,
            'currency_code' => $amount?->currency->value,
            'amount' => $amount,
            'direction' => $direction,
            'subject_type' => $subject === null ? null : $subject::class,
            'subject_id' => $subject?->getKey(),
            'reference' => $subject?->getAttribute('public_id'),
            'detail' => $detail,
        ]);
    }

    /**
     * @param  list<string>  $changed
     * @param  list<array{item: OrderItem|null, line: WebsiteOrderLine|null, change: RevisionLineChange|null, sku: string}>  $lineChanges
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return list<OrderRevisionSection>
     */
    protected function sections(array $changed, bool $noteChanged, array $lineChanges, array $before, array $after): array
    {
        $sections = [];

        if (in_array('customer', $changed, true)) {
            $sections[] = OrderRevisionSection::Customer;
        }

        if (array_intersect(['shipping_address', 'billing_address'], $changed) !== []) {
            $sections[] = OrderRevisionSection::Addresses;
        }

        $kinds = array_map(fn (array $entry) => $entry['change'], $lineChanges);

        if (array_intersect(array_map(fn (?RevisionLineChange $change) => $change?->value, $kinds), ['added', 'removed', 'quantity_changed']) !== []) {
            $sections[] = OrderRevisionSection::Items;
        }

        $repriced = array_filter($lineChanges, fn (array $entry) => $entry['item'] !== null && $entry['line'] !== null && ! $entry['item']->unit_price->equals($entry['line']->unitPrice));

        if ($repriced !== [] || ($before['discount'] ?? null) !== ($after['discount'] ?? null)) {
            $sections[] = OrderRevisionSection::Pricing;
        }

        if (($before['delivery'] ?? null) !== ($after['delivery'] ?? null)) {
            $sections[] = OrderRevisionSection::Delivery;
        }

        if ($noteChanged) {
            $sections[] = OrderRevisionSection::Note;
        }

        if ($sections === [] && $lineChanges !== []) {
            // Only a line's tax or discount share moved, from another change.
            $sections[] = OrderRevisionSection::Pricing;
        }

        return $sections;
    }

    /**
     * Each part changed needs its own permission. A change to what something
     * costs is a financial correction: a confirmed password and two-factor.
     *
     * @param  list<OrderRevisionSection>  $sections
     *
     * @throws AuthorizationException
     */
    protected function authorize(User $actor, array $sections, OrderAmendment $request, bool $preview): void
    {
        foreach ($sections as $section) {
            Gate::forUser($actor)->authorize(PermissionCatalogue::name(PermissionModule::Order, $section->permission() ?? PermissionAction::EditCustomer));
        }

        $financial = array_intersect(array_map(fn (OrderRevisionSection $section) => $section->value, $sections), ['pricing', 'delivery', 'payment_method']) !== [];

        if (! $financial || $preview) {
            return;
        }

        try {
            $this->guard->authorize($actor, new SensitiveActionRequest(
                module: PermissionModule::Order,
                action: PermissionAction::ReviseFinancials,
                reason: trim($request->details->reason),
                passwordConfirmed: $request->passwordConfirmed,
                twoFactorEnabled: $request->twoFactorEnabled,
            ));
        } catch (SensitiveActionRefused $refused) {
            throw new OrderRevisionRefused('confirmation_required', $refused->getMessage(), 'password');
        }
    }

    /**
     * What the editor shows: the lines, the totals, the money, the stock and
     * the Partner's profit — all as the server worked them out.
     *
     * @return array<string, mixed>
     */
    protected function summary(OrderRevision $revision, Order $order): array
    {
        $profit = null;

        if ($order->source === OrderSource::Website) {
            $order->unsetRelation('items');
            $lines = $this->calculator->lines($order);
            $currency = Currency::from($order->currency_code);
            $selling = Money::zero($currency);
            $base = Money::zero($currency);
            $unproven = false;

            foreach ($lines as $line) {
                $selling = $selling->plus($line['selling']);

                if ($line['cost'] === null) {
                    $unproven = true;

                    continue;
                }

                $base = $base->plus($line['cost']);
            }

            $profit = [
                'net_selling' => $selling->jsonSerialize(),
                'product_base_price' => $unproven ? null : $base->jsonSerialize(),
                'partner_profit' => $unproven ? null : $selling->minus($base)->jsonSerialize(),
                'banij_receivable' => $unproven ? null : $base->plus($order->delivery)->jsonSerialize(),
                'unproven' => $unproven,
                'negative' => ! $unproven && $selling->minus($base)->isNegative(),
            ];
        }

        return [
            'lines' => $order->items()->orderBy('line_number')->get()->map(fn (OrderItem $item) => [
                'sku' => $item->sku,
                'name' => $item->product_name,
                'quantity' => $item->quantity,
                'unit_price' => $item->unit_price->jsonSerialize(),
                'discount' => $item->discount->jsonSerialize(),
                'line_total' => $item->line_total->jsonSerialize(),
            ])->values()->all(),
            'totals' => [
                'subtotal' => $order->subtotal->jsonSerialize(),
                'discount' => $order->discount->jsonSerialize(),
                'delivery' => $order->delivery->jsonSerialize(),
                'tax' => $order->tax->jsonSerialize(),
                'total' => $order->total->jsonSerialize(),
                'total_before' => $revision->total_before->jsonSerialize(),
                'difference' => $revision->total_difference->jsonSerialize(),
            ],
            'sections' => $revision->sections,
            'payment_consequence' => $revision->payment_consequence->value,
            'payment_consequence_label' => $revision->payment_consequence->label(),
            'stock' => $revision->stock_difference,
            'profit' => $profit,
        ];
    }

    /**
     * @param  list<array{item: OrderItem|null, line: WebsiteOrderLine|null, change: RevisionLineChange|null, sku: string}>  $entries
     * @return list<array{sku: string, before: int, after: int}>
     */
    protected function stockDifference(array $entries): array
    {
        $difference = [];

        foreach ($entries as $entry) {
            if ($entry['change'] === null) {
                continue;
            }

            $before = $entry['item']->quantity ?? 0;
            $after = $entry['line']->quantity ?? 0;

            if ($before !== $after) {
                $difference[] = ['sku' => $entry['sku'], 'before' => $before, 'after' => $after];
            }
        }

        return $difference;
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function lineSnapshot(Order $order): array
    {
        return array_values($order->items()->get()->map(fn (OrderItem $item) => [
            'id' => $item->public_id,
            'sku' => $item->sku,
            'quantity' => $item->quantity,
            'unit_price' => $item->unit_price->toDecimal(),
            'line_total' => $item->line_total->toDecimal(),
        ])->all());
    }

    protected function amount(?string $value, Currency $currency, string $field): ?Money
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        try {
            return DecimalAmount::parse($value, $currency);
        } catch (Throwable) {
            throw OrderRevisionRefused::because('amount_invalid', $field);
        }
    }

    protected function key(Order $order, OrderAmendment $request): string
    {
        return 'order-revision:'.$order->public_id.':'.$request->details->idempotencyKey;
    }
}
