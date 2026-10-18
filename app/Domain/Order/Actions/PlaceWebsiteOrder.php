<?php

namespace App\Domain\Order\Actions;

use App\Domain\Billing\Actions\RecordPaymentFromQuote;
use App\Domain\Billing\Enums\PaymentPurpose;
use App\Domain\Billing\Models\Payment;
use App\Domain\Catalog\Models\ProductAttributeValue;
use App\Domain\Catalog\WholesalePriceResolver;
use App\Domain\Inventory\Enums\ReservationKind;
use App\Domain\Inventory\Exceptions\InventoryRefused;
use App\Domain\Inventory\Models\StockReservation;
use App\Domain\Inventory\Queries\PartnerStockAvailability;
use App\Domain\Inventory\ReservationWindows;
use App\Domain\Inventory\StockReservations;
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
use App\Domain\Order\WebsiteCheckoutRules;
use App\Domain\Website\Actions\RecordWebsiteCustomer;
use App\Domain\Website\CheckoutVerification;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsiteCustomer;
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
        protected WebsiteCheckoutRules $rules,
        protected SendCodConfirmationCode $confirmations,
        protected RecordWebsiteCustomer $customers,
        protected RecordPaymentFromQuote $payments,
        protected PartnerStockAvailability $stock,
        protected PaymentGatewayManager $gateways,
        protected StockReservations $reservations,
        protected CheckoutVerification $verification,
        protected ConfirmCodOrder $codConfirmation,
        protected DatabaseManager $database,
        protected DistributedLock $lock,
        protected ReservationWindows $windows,
        protected WholesalePriceResolver $wholesalePrices,
    ) {}

    /**
     * @return array{0: Order, 1: bool} the order, and whether this call placed it
     *
     * @throws WebsiteOrderRefused
     */
    public function handle(Website $website, WebsiteOrderSubmission $submission): array
    {
        /*
         * The checkout rules every storefront path shares — the advisory quote
         * asks the same ones (P2): the website takes orders (an outstanding KYC
         * re-verification answers exactly as a paused shop does, §7.4), what
         * it may say about payment (D12), and no coupons.
         */
        $this->rules->assertAcceptingOrders($website);
        $this->rules->assertPaymentRequest(
            $website,
            $submission->paymentMethod,
            $submission->claimedPaymentStatus,
            $submission->returnUrl,
        );
        $this->rules->assertNoCoupon($submission->couponCode);

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

            // The customer is asked to confirm only once the order is real —
            // and only where the shop (or the platform) asks for a code at all.
            if ($submission->isCashOnDelivery() && ! $this->verification->requiredFor($website)) {
                $order = $this->codConfirmation->withoutCode($order)->refresh()->load(['items', 'payment']);
            } else {
                $this->askForConfirmation($order, $submission);
            }
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
            $this->rules->assertWithinCodLimit($website, $quote->total);
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

        $reservations = $this->reserve($order, $website, $quote, $submission->reservationKind());

        $this->writeLines($order, $quote, $reservations, $website->businessAccount->isNonConditional());

        /*
         * A cash-on-delivery payment names no gateway: the money is collected
         * on delivery and settled through §28, which is a later phase. It is
         * recorded all the same, so the order has the one payment every order
         * has and §28 has something to settle against.
         */
        $payment->forceFill($submission->isCashOnDelivery()
            ? ['expires_at' => $this->paymentDeadline($reservations, $submission->reservationKind())]
            : [
                'gateway' => $gateway = $this->rules->gatewayFor($submission->gateway, $quote->currency),
                'gateway_mode' => $this->gateways->driver($gateway)->isSandbox()
                    ? GatewayCredentials::SANDBOX
                    : GatewayCredentials::LIVE,
                'expires_at' => $this->paymentDeadline($reservations, $submission->reservationKind()),
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
     * the units that owner bought (D34). Supplier offers are never drawn on for a
     * storefront order, and a line the owner has too few of refuses the order:
     * stock always blocks here. The one exception is a Non-Conditional account,
     * which sells without having bought: a line it owns too few of is filled
     * from central stock instead.
     *
     * @return array<int, StockReservation> keyed by the line's position
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

        foreach ($positions as $position) {
            $line = $lines[$position];

            $reference = $order->reference.'-L'.($position + 1);

            try {
                try {
                    $reservations[$position] = $this->reservations->reserveFromPartner(
                        $website->businessAccount,
                        $line->product,
                        $line->variant,
                        $line->quantity,
                        $kind,
                        $reference,
                    );
                } catch (InventoryRefused $refused) {
                    // A Non-Conditional account sells what it never bought: the
                    // line is filled from central stock, and Feriwala recovers
                    // its cost from the sale's proceeds.
                    if (! $website->businessAccount->isNonConditional()) {
                        throw $refused;
                    }

                    $reservations[$position] = $this->reservations->reserve(
                        $line->product,
                        $line->variant,
                        $line->quantity,
                        $kind,
                        $reference,
                    );
                }
            } catch (InventoryRefused) {
                throw WebsiteOrderRefused::insufficientStock($line->sku, $line->quantity, $this->available($line, $website));
            } catch (LockTimeout) {
                throw WebsiteOrderRefused::busy();
            }
        }

        return $reservations;
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
     * the rate in force, the website selection it came from, and the stock held.
     *
     * A Non-Conditional account's line filled from central stock also snapshots
     * what Feriwala is owed for it: the wholesale price that account would have
     * paid, at this quantity.
     *
     * @param  array<int, StockReservation>  $reservations
     * @return Collection<int, OrderItem>
     */
    protected function writeLines(Order $order, WebsiteOrderQuote $quote, array $reservations, bool $nonConditional): Collection
    {
        $items = collect();

        foreach ($quote->lines as $position => $line) {
            $taxed = ! $line->tax->isZero();
            $fromCentral = $nonConditional && ! $reservations[$position]->isPartnerSourced();

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
                'product_cost' => $fromCentral
                    ? $this->wholesalePrices->unitPrice($line->product, $line->variant, $line->quantity)->multipliedBy($line->quantity)
                    : null,
                'tax_code' => $taxed ? $line->tax->code : null,
                'tax_rate_basis_points' => $taxed ? $line->tax->rateBasisPoints : null,
                'tax_mode' => $taxed ? $line->tax->mode->value : null,
                'stock_reservation_id' => $reservations[$position]->id,
            ]));
        }

        return $items;
    }

    /**
     * The payment window: closed a margin before the first reservation runs out.
     *
     * With no stock held at all, the window is the reservation window for the
     * order's kind.
     *
     * @param  array<int, StockReservation>  $reservations
     */
    protected function paymentDeadline(array $reservations, ReservationKind $kind): CarbonImmutable
    {
        $first = null;

        foreach ($reservations as $reservation) {
            if ($first === null || $reservation->expires_at->lessThan($first)) {
                $first = $reservation->expires_at;
            }
        }

        $first ??= $this->windows->expiryFor($kind);

        return $first->subSeconds(self::SETTLEMENT_MARGIN_SECONDS);
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
