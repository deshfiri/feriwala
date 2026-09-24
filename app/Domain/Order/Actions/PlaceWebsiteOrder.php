<?php

namespace App\Domain\Order\Actions;

use App\Domain\Billing\Actions\RecordPaymentFromQuote;
use App\Domain\Billing\Enums\PaymentPurpose;
use App\Domain\Billing\Models\Payment;
use App\Domain\Catalog\Models\ProductAttributeValue;
use App\Domain\Inventory\Enums\ReservationKind;
use App\Domain\Inventory\Exceptions\InventoryRefused;
use App\Domain\Inventory\Models\StockReservation;
use App\Domain\Inventory\Queries\StockAvailability;
use App\Domain\Order\Data\WebsiteOrderLine;
use App\Domain\Order\Data\WebsiteOrderPaymentQuote;
use App\Domain\Order\Data\WebsiteOrderQuote;
use App\Domain\Order\Data\WebsiteOrderSubmission;
use App\Domain\Order\Enums\OrderSource;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Enums\OrderStatusChangeSource;
use App\Domain\Order\Exceptions\WebsiteOrderRefused;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderItem;
use App\Domain\Order\Queries\PriceWebsiteOrder;
use App\Domain\Supplier\Actions\AccrueSupplierPayable;
use App\Domain\Supplier\Actions\AllocateSupplierOrderLine;
use App\Domain\Supplier\Data\SupplierAllocation;
use App\Domain\Supplier\Exceptions\SupplierAllocationRefused;
use App\Domain\Website\Actions\RecordWebsiteCustomer;
use App\Domain\Website\CodTerms;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsiteCustomer;
use App\Domain\Website\WebsiteAddresses;
use App\Integrations\Payment\Gateways\GatewayCredentials;
use App\Integrations\Payment\PaymentGatewayManager;
use App\Support\Concurrency\DistributedLock;
use App\Support\Concurrency\Exceptions\LockTimeout;
use App\Support\Money\Money;
use App\Support\StatusHistory\StatusChange;
use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

/**
 * Take an order a customer placed on a partner website (contract §6.1, §6.1.2,
 * §17.1, §17.3, P5-21, P5-23, D12).
 *
 * **Nothing the storefront sends is trusted but what it bought.** The ERP prices
 * the order itself and refuses a claim that differs by a single poisha; a
 * payment the storefront calls paid is refused outright, because Feriwala is
 * merchant of record and opens the payment itself (D12).
 *
 * Under a lock on the storefront's own reference, in one transaction:
 *
 *   1. the website's customer is found or recorded by their mobile number;
 *   2. one payment is recorded for the order, with a component per line, the
 *      delivery charge and the tax — and no invoice until it settles;
 *   3. the order is written with `source = website`, `payment_pending`, the
 *      customer and both addresses as they were, and every line snapshotted at
 *      the price it sold for;
 *   4. every line's stock is reserved for the website's owner, in product order,
 *      so two orders never take the same stock in opposite orders.
 *
 * Any refusal rolls back all of it. **The same order is never taken twice**: the
 * storefront's idempotency key and its own order reference are both unique in
 * the database, so a retry — or two requests at once — returns the order the
 * first one placed rather than a second one.
 */
class PlaceWebsiteOrder
{
    /**
     * How long before its stock is released the payment window closes: the same
     * margin a wholesale order leaves settlement (P4-9).
     */
    public const SETTLEMENT_MARGIN_SECONDS = PlaceWholesaleOrder::SETTLEMENT_MARGIN_SECONDS;

    public function __construct(
        protected PriceWebsiteOrder $pricing,
        protected CodTerms $cod,
        protected SendCodConfirmationCode $confirmations,
        protected RecordWebsiteCustomer $customers,
        protected RecordPaymentFromQuote $payments,
        protected StockAvailability $stock,
        protected PaymentGatewayManager $gateways,
        protected WebsiteAddresses $addresses,
        protected AllocateSupplierOrderLine $supplierAllocation,
        protected AccrueSupplierPayable $supplierPayables,
        protected DatabaseManager $database,
        protected DistributedLock $lock,
    ) {}

    /**
     * @return array{0: Order, 1: bool} the order, and whether this call placed it
     *
     * @throws WebsiteOrderRefused
     */
    public function handle(Website $website, WebsiteOrderSubmission $submission): array
    {
        if (! $website->status->isLive()) {
            throw WebsiteOrderRefused::notAcceptingOrders();
        }

        $this->checkPayment($website, $submission);

        if ($submission->couponCode !== null && $submission->couponCode !== '') {
            throw WebsiteOrderRefused::couponNotApplicable($submission->couponCode);
        }

        try {
            return $this->lock->run(
                key: 'website-order:'.$website->id.':'.mb_strtolower($submission->reference),
                callback: fn () => $this->place($website, $submission),
                ttlSeconds: 30,
                waitSeconds: 10,
            );
        } catch (LockTimeout) {
            throw WebsiteOrderRefused::busy();
        }
    }

    /**
     * The order this website already has under that storefront reference.
     */
    public function existingFor(Website $website, string $reference): ?Order
    {
        /** @var Order|null $order */
        $order = Order::query()
            ->where('website_id', $website->id)
            ->where('storefront_order_reference', $reference)
            ->first();

        return $order;
    }

    /**
     * @return array{0: Order, 1: bool}
     *
     * @throws WebsiteOrderRefused
     */
    protected function place(Website $website, WebsiteOrderSubmission $submission): array
    {
        $key = $submission->orderKey($website->id);
        $existing = $this->existingFor($website, $submission->reference);

        if ($existing !== null) {
            // The same request again — its own order. A different request
            // reusing the reference is the duplicate the contract names.
            return $existing->idempotency_key === $key
                ? [$existing, false]
                : throw WebsiteOrderRefused::duplicate($existing);
        }

        /** @var Order|null $byKey */
        $byKey = Order::query()->where('idempotency_key', $key)->first();

        if ($byKey !== null) {
            throw WebsiteOrderRefused::keyReused();
        }

        $quote = $this->pricing->quote($website, $submission->items);

        if (! $quote->matches($submission->claimedUnitPrices, $submission->claimedTotals)) {
            throw WebsiteOrderRefused::priceMismatch(
                $quote->authoritative(),
                $submission->claimedTotals['grand_total'],
            );
        }

        if (! $quote->total->isPositive()) {
            throw WebsiteOrderRefused::priceMismatch($quote->authoritative(), $submission->claimedTotals['grand_total']);
        }

        try {
            $order = $this->database->transaction(fn () => $this->write($website, $submission, $quote, $key));

            // The customer is asked to confirm only once the order is real.
            $this->askForConfirmation($order, $submission);
        } catch (UniqueConstraintViolationException $exception) {
            // A racing submission of the same order won. Its order is the answer.
            $existing = $this->existingFor($website, $submission->reference);

            if ($existing === null) {
                throw $exception;
            }

            return $existing->idempotency_key === $key
                ? [$existing, false]
                : throw WebsiteOrderRefused::duplicate($existing);
        }

        return [$order, true];
    }

    /**
     * @throws WebsiteOrderRefused
     */
    protected function write(Website $website, WebsiteOrderSubmission $submission, WebsiteOrderQuote $quote, string $key): Order
    {
        $account = $website->businessAccount;
        $customer = $this->customers->findOrRecord($website, $submission->customer);

        // What this shop may take on delivery, against what this order comes to.
        if ($submission->isCashOnDelivery()) {
            $maximum = $this->cod->maximumFor($website, $quote->currency);

            if ($maximum !== null && $quote->total->greaterThan($maximum)) {
                throw WebsiteOrderRefused::codLimitExceeded($maximum);
            }
        }

        $payment = $this->payments->handle(
            account: $account,
            quote: WebsiteOrderPaymentQuote::fromQuote($quote),
            purpose: PaymentPurpose::WebsiteOrder,
            idempotencyKey: 'website-payment:'.Str::after($key, 'website-order:'),
        );

        if (! $payment->amount->equals($quote->total)) {
            throw new LogicException('A website order payment must come to the order total.');
        }

        $order = $this->createOrder($website, $customer, $submission, $quote, $payment, $key);

        $payment->payable()->associate($order);

        [$reservations, $allocations] = $this->reserve($order, $website, $quote, $submission->reservationKind());

        $items = $this->writeLines($order, $quote, $reservations, $allocations);

        foreach ($items as $item) {
            if ($item->isSupplierBacked()) {
                $this->supplierPayables->handle($item);
            }
        }

        /*
         * A cash-on-delivery payment names no gateway: the money is collected
         * on delivery and settled through §28, which is a later phase. It is
         * recorded all the same, so the order has the one payment every order
         * has and §28 has something to settle against.
         */
        $payment->forceFill($submission->isCashOnDelivery()
            ? ['expires_at' => $this->paymentDeadline($reservations)]
            : [
                'gateway' => $gateway = $this->gateway($submission, $quote),
                'gateway_mode' => $this->gateways->driver($gateway)->isSandbox()
                    ? GatewayCredentials::SANDBOX
                    : GatewayCredentials::LIVE,
                'expires_at' => $this->paymentDeadline($reservations),
            ])->save();

        $order->recordPlacement(
            new StatusChange(
                reason: 'Placed on '.$website->name.' ('.$submission->reference.').',
                publicNote: $submission->isCashOnDelivery()
                    ? 'orders.notes.cod_placed'
                    : 'orders.notes.placed_on_website',
            ),
            OrderStatusChangeSource::Storefront,
        );

        $this->customers->noteOrder($customer, $order->placed_at);

        // Read back what the database holds: the columns it fills in itself —
        // fulfilment, courier and delivery status — are part of the order the
        // caller is handed.
        return $order->refresh()->load(['items', 'payment']);
    }

    protected function createOrder(
        Website $website,
        WebsiteCustomer $customer,
        WebsiteOrderSubmission $submission,
        WebsiteOrderQuote $quote,
        Payment $payment,
        string $key,
    ): Order {
        $note = $submission->customerNote === null ? null : trim($submission->customerNote);

        return Order::create([
            'source' => OrderSource::Website,
            /*
             * A cash-on-delivery order waits for its customer to confirm it
             * with the code they are sent; an online one waits for the money
             * (§18.2, contract §6.1.2).
             */
            'status' => $submission->isCashOnDelivery()
                ? OrderStatus::CustomerVerificationPending
                : OrderStatus::PaymentPending,
            'business_account_id' => $website->business_account_id,
            'website_id' => $website->id,
            'website_customer_id' => $customer->id,
            'storefront_order_reference' => $submission->reference,
            'storefront_return_url' => $submission->returnUrl,
            'payment_id' => $payment->id,
            'idempotency_key' => $key,
            'customer' => $submission->customer->toSnapshot($customer->public_id),
            'billing_address' => $submission->billingAddress,
            'shipping_address' => $submission->shippingAddress,
            'currency_code' => $quote->currency->value,
            'subtotal' => $quote->subtotal,
            'discount' => $quote->discount,
            'delivery' => $quote->delivery,
            'tax' => $quote->tax->addedTotal(),
            'tax_included' => $quote->tax->includedTotal(),
            'cod_fee' => Money::zero($quote->currency),
            'total' => $quote->total,
            'customer_note' => $note === '' ? null : $note,
            'placed_at' => CarbonImmutable::now(),
        ]);
    }

    /**
     * Hold every line's stock for the website's owner, in product order — from
     * a Supplier's preferred offer where the variation is Supplier-sourced
     * (D25, P13-21), and from central stock otherwise.
     *
     * @return array{0: array<int, StockReservation>, 1: array<int, SupplierAllocation|null>} both keyed by the line's position
     *
     * @throws WebsiteOrderRefused
     */
    protected function reserve(Order $order, Website $website, WebsiteOrderQuote $quote, ReservationKind $kind): array
    {
        $lines = $quote->lines;
        $positions = array_keys($lines);

        usort($positions, fn (int $a, int $b) => [
            $lines[$a]->product->id, $lines[$a]->variant->id ?? 0,
        ] <=> [
            $lines[$b]->product->id, $lines[$b]->variant->id ?? 0,
        ]);

        $reservations = [];
        $allocations = [];

        foreach ($positions as $position) {
            $line = $lines[$position];

            try {
                [$reservations[$position], $allocations[$position]] = $this->supplierAllocation->handle(
                    $line->product,
                    $line->variant,
                    $line->quantity,
                    $quote->currency,
                    $kind,
                    $order->reference.'-L'.($position + 1),
                    $website->businessAccount,
                );
            } catch (SupplierAllocationRefused) {
                // Never named to the storefront's customer: which Supplier, or
                // why its offer could not serve, is Feriwala's own business (D25).
                throw WebsiteOrderRefused::insufficientStock($line->sku, $line->quantity, $this->available($line, $website));
            } catch (InventoryRefused) {
                throw WebsiteOrderRefused::insufficientStock($line->sku, $line->quantity, $this->available($line, $website));
            } catch (LockTimeout) {
                throw WebsiteOrderRefused::busy();
            }
        }

        return [$reservations, $allocations];
    }

    /**
     * What is left of a SKU for this website's owner — availability only, as the
     * inventory endpoint reports it.
     */
    protected function available(WebsiteOrderLine $line, Website $website): int
    {
        $availability = $this->stock->forSkus([$line->sku], $website->businessAccount);

        return (int) ($availability[$line->sku]['quantity'] ?? 0);
    }

    /**
     * Snapshot every line as it sold: what, how many, at what price, its tax at
     * the rate in force, the website selection it came from, the stock held,
     * and — for a Supplier-sourced line — the Supplier allocation (D25, P13-21).
     *
     * @param  array<int, StockReservation>  $reservations
     * @param  array<int, SupplierAllocation|null>  $allocations
     * @return Collection<int, OrderItem>
     */
    protected function writeLines(Order $order, WebsiteOrderQuote $quote, array $reservations, array $allocations): Collection
    {
        $items = collect();

        foreach ($quote->lines as $position => $line) {
            $taxed = ! $line->tax->isZero();

            $items->push($order->items()->create([
                'line_number' => $position + 1,
                'product_id' => $line->product->id,
                'product_variant_id' => $line->variant?->id,
                'website_product_id' => $line->selection->id,
                'sku' => $line->sku,
                'product_name' => Str::limit($line->product->name, 157),
                'variant_label' => $line->variant?->values
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
                'tax_code' => $taxed ? $line->tax->code : null,
                'tax_rate_basis_points' => $taxed ? $line->tax->rateBasisPoints : null,
                'tax_mode' => $taxed ? $line->tax->mode->value : null,
                'stock_reservation_id' => $reservations[$position]->id,
                ...($allocations[$position]?->lineSnapshot() ?? []),
            ]));
        }

        return $items;
    }

    /**
     * The payment window: closed a margin before the first reservation runs out.
     *
     * @param  array<int, StockReservation>  $reservations
     */
    protected function paymentDeadline(array $reservations): CarbonImmutable
    {
        $first = null;

        foreach ($reservations as $reservation) {
            if ($first === null || $reservation->expires_at->lessThan($first)) {
                $first = $reservation->expires_at;
            }
        }

        if ($first === null) {
            throw new LogicException('An order holds stock for at least one line.');
        }

        return $first->subSeconds(self::SETTLEMENT_MARGIN_SECONDS);
    }

    /**
     * What the storefront may say about payment, and what it may not.
     *
     * Feriwala owns the gateway relationship (D12): a storefront cannot hold a
     * Feriwala payment for an order that does not exist yet, so a claim that one
     * is already paid cannot be verified and is refused. Cash on delivery waits
     * for the confirmation and collection flow that owns it.
     *
     * @throws WebsiteOrderRefused
     */
    protected function checkPayment(Website $website, WebsiteOrderSubmission $submission): void
    {
        if (! in_array($submission->paymentMethod, ['online', 'cod'], true)) {
            throw WebsiteOrderRefused::paymentMethodUnavailable($submission->paymentMethod);
        }

        // Whether this shop takes cash on delivery is the ERP's answer (§28).
        if ($submission->isCashOnDelivery() && ! $this->cod->enabledFor($website)) {
            throw WebsiteOrderRefused::codNotAvailable();
        }

        if ($submission->claimedPaymentStatus !== null && $submission->claimedPaymentStatus !== 'pending') {
            throw WebsiteOrderRefused::paymentUnverified();
        }

        if ($submission->returnUrl !== null && ! $this->addresses->allows($website, $submission->returnUrl)) {
            throw WebsiteOrderRefused::returnUrlNotAllowed();
        }
    }

    /**
     * The gateway the order will be paid through: the one asked for, if it is
     * switched on, and otherwise the first that is.
     *
     * @throws WebsiteOrderRefused
     */
    protected function gateway(WebsiteOrderSubmission $submission, WebsiteOrderQuote $quote): string
    {
        $available = $this->gateways->availableFor($quote->currency);

        if ($submission->gateway !== null) {
            if (! in_array($submission->gateway, $available, true)) {
                throw WebsiteOrderRefused::paymentMethodUnavailable($submission->gateway);
            }

            return $submission->gateway;
        }

        return $available[0] ?? throw WebsiteOrderRefused::paymentMethodUnavailable('online');
    }

    /**
     * Send a cash-on-delivery customer the code that confirms their order.
     *
     * Never allowed to fail an order that has been taken: the stock is held and
     * the window is open, and the storefront can ask for another code.
     */
    protected function askForConfirmation(Order $order, WebsiteOrderSubmission $submission): void
    {
        if (! $submission->isCashOnDelivery()) {
            return;
        }

        try {
            $this->confirmations->handle($order);
        } catch (Throwable) {
            // A provider having a bad afternoon is not a reason to lose an order.
        }
    }
}
