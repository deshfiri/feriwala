<?php

namespace App\Domain\Order\Actions;

use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Billing\Actions\RecordPaymentFromQuote;
use App\Domain\Billing\Actions\ReserveCoupon;
use App\Domain\Billing\Enums\PaymentPurpose;
use App\Domain\Billing\Models\Payment;
use App\Domain\Catalog\Models\ProductAttributeValue;
use App\Domain\Inventory\Enums\ReservationKind;
use App\Domain\Inventory\Exceptions\InventoryRefused;
use App\Domain\Inventory\Models\StockReservation;
use App\Domain\Kyc\KycRestrictions;
use App\Domain\Order\Enums\IntendedResaleChannel;
use App\Domain\Order\Enums\OrderSource;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Enums\OrderStatusChangeSource;
use App\Domain\Order\Exceptions\OrderRefused;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderItem;
use App\Domain\Supplier\Actions\AccrueSupplierPayable;
use App\Domain\Supplier\Actions\AllocateSupplierOrderLine;
use App\Domain\Supplier\Data\SupplierAllocation;
use App\Domain\Supplier\Exceptions\SupplierAllocationRefused;
use App\Domain\Wholesale\Actions\OpenCart;
use App\Domain\Wholesale\Data\CheckoutLineCharge;
use App\Domain\Wholesale\Data\CheckoutQuote;
use App\Domain\Wholesale\Data\WholesalePaymentQuote;
use App\Domain\Wholesale\Models\Cart;
use App\Domain\Wholesale\Queries\PriceCheckout;
use App\Integrations\Payment\Gateways\GatewayCredentials;
use App\Integrations\Payment\PaymentGatewayManager;
use App\Models\User;
use App\Support\Concurrency\Exceptions\LockTimeout;
use App\Support\Money\Money;
use App\Support\StatusHistory\StatusChange;
use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;

/**
 * Turn a confirmed wholesale checkout into an order waiting for its payment
 * (§14, §18.1, §19.1, P4-9, P4-10).
 *
 * The person sends the fingerprint of the summary they confirmed, an optional
 * note and an optional resale channel — never a price, a total, a tax or a
 * quantity. Under a lock on their cart, in one transaction:
 *
 *   1. the checkout is priced again and must still be the one confirmed: ready,
 *      addressed, payable through a gateway that is switched on, and with the
 *      same fingerprint;
 *   2. one payment is recorded for it, with a component per line, the discount,
 *      delivery and tax — and no invoice yet (P4-11 issues it once paid);
 *   3. the order is written with `source = erp_wholesale`, `payment_pending`, and
 *      a snapshot of every line as it was priced;
 *   4. every line's stock is reserved through the central reservation service
 *      for the ordering account — its allocation first, the whole quantity from
 *      one warehouse — in product order, so two orders never lock the same
 *      stock in opposite orders;
 *   5. the coupon's use is held against the payment.
 *
 * Any refusal rolls back all of it: no payment, no order, no reservation and no
 * coupon hold is left behind. **Idempotent per confirmation**: the same
 * confirmation submitted twice returns the order the first one placed, and the
 * database refuses a second order for the key, a second order waiting on the same
 * cart, or a second order for the payment.
 */
class PlaceWholesaleOrder
{
    /**
     * How long before the stock is released the payment window closes.
     *
     * A payment the gateway confirms inside the window has to find its stock still
     * held. Settling takes a lock held for at most thirty seconds, so closing the
     * window a minute before the reservations run out means a settlement that
     * started in time finishes before the reservation sweep can reach its stock —
     * and one that arrives after the window takes the reconciliation path instead.
     */
    public const SETTLEMENT_MARGIN_SECONDS = 60;

    public function __construct(
        protected OpenCart $carts,
        protected PriceCheckout $checkout,
        protected PaymentGatewayManager $gateways,
        protected RecordPaymentFromQuote $payments,
        protected ReserveCoupon $coupons,
        protected AllocateSupplierOrderLine $supplierAllocation,
        protected AccrueSupplierPayable $supplierPayables,
        protected KycRestrictions $kycRestrictions,
        protected DatabaseManager $database,
    ) {}

    /**
     * @throws OrderRefused
     */
    public function handle(
        User $user,
        BusinessAccount $account,
        string $fingerprintSeen,
        ?string $customerNote = null,
        ?IntendedResaleChannel $resaleChannel = null,
    ): Order {
        /*
         * An outstanding KYC re-verification can stop new wholesale orders
         * (§7.4). Checked here, in the action, rather than by hiding a button:
         * the route and the cart are still reachable, and the person most
         * likely to find them is the one the restriction is aimed at.
         *
         * Only *new* orders. Nothing about this touches an order already
         * placed, its reservations, its payment or its invoice — those still
         * have to be settled, and a business asked to re-verify has not been
         * found guilty of anything.
         */
        if ($this->kycRestrictions->blocksNewOrders($account)) {
            throw OrderRefused::kycReverificationOutstanding(
                $this->kycRestrictions->refusalReason($account),
            );
        }

        $cart = $this->carts->find($user, $account);

        if ($cart === null) {
            throw OrderRefused::cartNotReady();
        }

        try {
            return $this->database->transaction(
                fn () => $this->place($user, $account, $cart, $fingerprintSeen, $customerNote, $resaleChannel)
            );
        } catch (UniqueConstraintViolationException $exception) {
            // A racing submission of the same confirmation won. Its order is the answer.
            $existing = $this->existingFor($cart->refresh());

            if ($existing === null) {
                throw $exception;
            }

            return $existing;
        }
    }

    /**
     * The order a cart's current confirmation already placed, if it did.
     */
    public function existingFor(Cart $cart): ?Order
    {
        $key = $this->idempotencyKey($cart);

        return $key === null ? null : Order::query()->where('idempotency_key', $key)->first();
    }

    /**
     * The order this cart is waiting to be paid for, if there is one.
     */
    public function awaitingPaymentFor(Cart $cart): ?Order
    {
        return Order::query()
            ->where('cart_id', $cart->id)
            ->where('status', OrderStatus::PaymentPending)
            ->first();
    }

    protected function place(
        User $user,
        BusinessAccount $account,
        Cart $cart,
        string $fingerprintSeen,
        ?string $customerNote,
        ?IntendedResaleChannel $resaleChannel,
    ): Order {
        /** @var Cart $cart */
        $cart = Cart::query()->whereKey($cart->id)->lockForUpdate()->firstOrFail();

        $key = $this->idempotencyKey($cart);

        if ($key === null || $cart->payment_method === null) {
            throw OrderRefused::checkoutNotConfirmed();
        }

        if (($existing = Order::query()->where('idempotency_key', $key)->first()) !== null) {
            return $existing;
        }

        if ($this->awaitingPaymentFor($cart) !== null) {
            throw OrderRefused::awaitingPayment();
        }

        $quote = $this->checkout->quote($cart, $account);

        if (! $quote->cart->isReadyForCheckout()) {
            throw OrderRefused::cartNotReady();
        }

        if (! $quote->hasAddresses()) {
            throw OrderRefused::addressesMissing();
        }

        $fingerprint = $quote->fingerprint();

        if (! hash_equals((string) $cart->confirmed_fingerprint, $fingerprint) || ! hash_equals($fingerprint, $fingerprintSeen)) {
            throw OrderRefused::checkoutChanged();
        }

        if (! in_array($cart->payment_method, $this->gateways->availableFor($quote->total->currency), true)) {
            throw OrderRefused::paymentMethodUnavailable();
        }

        if (! $quote->total->isPositive()) {
            throw OrderRefused::nothingToPay();
        }

        $deliveryOnly = $account->isNonConditional();

        if ($deliveryOnly) {
            foreach ($quote->lineCharges as $charge) {
                if ($charge->line->item->resale_amount === null) {
                    // Most likely the line was added before the account's
                    // type last changed to Non-Conditional — re-pricing alone
                    // cannot fix a missing declaration, so the cart is sent
                    // back rather than the order being placed without one.
                    throw OrderRefused::resaleAmountRequired();
                }
            }
        }

        $paymentQuote = WholesalePaymentQuote::fromCheckout($quote, $deliveryOnly);

        $expectedTotal = $deliveryOnly ? $quote->delivery : $quote->total;

        if (! $paymentQuote->total()->equals($expectedTotal)) {
            throw new LogicException(
                $deliveryOnly
                    ? 'A Non-Conditional wholesale payment must come to the delivery charge alone.'
                    : 'A wholesale payment must come to the checkout total.'
            );
        }

        $payment = $this->payments->handle(
            account: $account,
            quote: $paymentQuote,
            purpose: PaymentPurpose::WholesaleOrder,
            idempotencyKey: 'wholesale-payment:'.Str::after($key, 'wholesale-order:'),
        );

        $order = $this->createOrder($user, $account, $cart, $quote, $payment, $key, $fingerprint, $customerNote, $resaleChannel);

        $payment->payable()->associate($order);

        [$reservations, $allocations] = $this->reserve($order, $account, $quote);

        $items = $this->writeLines($order, $quote, $reservations, $allocations);

        foreach ($items as $item) {
            if ($item->isSupplierBacked()) {
                $this->supplierPayables->handle($item);
            }
        }

        $payment->forceFill([
            'gateway' => $cart->payment_method,
            // Stamped now and never recalculated, as every checkout does (§26.4).
            'gateway_mode' => $this->gateways->driver($cart->payment_method)->isSandbox()
                ? GatewayCredentials::SANDBOX
                : GatewayCredentials::LIVE,
            'expires_at' => $this->paymentDeadline($reservations),
        ])->save();

        $this->holdCoupon($account, $payment, $quote);

        $order->recordPlacement(
            new StatusChange(
                actorId: $user->id,
                reason: 'Placed from the wholesale checkout.',
                publicNote: 'orders.notes.placed',
            ),
            OrderStatusChangeSource::Checkout,
        );

        return $order->load(['items', 'payment']);
    }

    /**
     * One key per confirmation: the cart, the summary agreed to and when.
     *
     * Confirming again — after a change, or after an order for the previous
     * confirmation was cancelled — is a new confirmation with a new key.
     */
    protected function idempotencyKey(Cart $cart): ?string
    {
        if ($cart->confirmed_at === null || $cart->confirmed_fingerprint === null) {
            return null;
        }

        return 'wholesale-order:'.hash('sha256', implode('|', [
            $cart->public_id,
            $cart->confirmed_fingerprint,
            $cart->confirmed_at->format('Uu'),
        ]));
    }

    protected function createOrder(
        User $user,
        BusinessAccount $account,
        Cart $cart,
        CheckoutQuote $quote,
        Payment $payment,
        string $key,
        string $fingerprint,
        ?string $customerNote,
        ?IntendedResaleChannel $resaleChannel,
    ): Order {
        $note = $customerNote === null ? null : trim($customerNote);

        return Order::create([
            'source' => OrderSource::ErpWholesale,
            'status' => OrderStatus::PaymentPending,
            'business_account_id' => $account->id,
            'account_type' => $account->account_type,
            'placed_by' => $user->id,
            'cart_id' => $cart->id,
            'payment_id' => $payment->id,
            'idempotency_key' => $key,
            'checkout_fingerprint' => $fingerprint,
            'customer' => [
                'business_name' => $account->name,
                'contact_name' => $user->name,
                'email' => $user->email,
                'mobile' => $user->mobile,
            ],
            'billing_address' => $quote->billingAddress?->toSnapshot(),
            'shipping_address' => $quote->shippingAddress?->toSnapshot(),
            'currency_code' => $quote->total->currency->value,
            'subtotal' => $quote->cart->subtotal,
            'discount' => $quote->discount,
            'delivery' => $quote->delivery,
            'tax' => $quote->tax->addedTotal(),
            'tax_included' => $quote->tax->includedTotal(),
            'cod_fee' => Money::zero($quote->total->currency),
            'total' => $quote->total,
            'coupon_code' => $quote->discount->isPositive() ? $quote->coupon?->coupon?->code : null,
            'customer_note' => $note === '' ? null : $note,
            'intended_resale_channel' => $resaleChannel,
            'placed_at' => CarbonImmutable::now(),
        ]);
    }

    /**
     * Hold every line's stock, in product order, for the ordering account —
     * from a Supplier's preferred offer where the variation is Supplier-sourced
     * (D25, P13-21), and from central stock otherwise.
     *
     * @return array{0: array<int, StockReservation>, 1: array<int, SupplierAllocation|null>} both keyed by the line's position in the checkout
     *
     * @throws OrderRefused
     */
    protected function reserve(Order $order, BusinessAccount $account, CheckoutQuote $quote): array
    {
        $charges = $quote->lineCharges;

        // A consistent order across every checkout, so two orders for the same
        // products queue behind each other instead of deadlocking.
        $positions = array_keys($charges);
        usort($positions, fn (int $a, int $b) => [
            $charges[$a]->line->item->product_id,
            $charges[$a]->line->item->product_variant_id ?? 0,
        ] <=> [
            $charges[$b]->line->item->product_id,
            $charges[$b]->line->item->product_variant_id ?? 0,
        ]);

        $reservations = [];
        $allocations = [];

        foreach ($positions as $position) {
            $item = $charges[$position]->line->item;

            try {
                [$reservations[$position], $allocations[$position]] = $this->supplierAllocation->handle(
                    $item->product,
                    $item->variant,
                    $item->quantity,
                    $quote->total->currency,
                    ReservationKind::OnlinePayment,
                    $order->reference.'-L'.($position + 1),
                    $account,
                );
            } catch (SupplierAllocationRefused) {
                // Never named to the buyer: which Supplier, or why its offer
                // could not serve, is Feriwala's own business (D25).
                throw OrderRefused::stockUnavailable();
            } catch (InventoryRefused) {
                throw OrderRefused::stockUnavailable();
            } catch (LockTimeout) {
                throw OrderRefused::busy();
            }
        }

        return [$reservations, $allocations];
    }

    /**
     * Snapshot every line as it was priced: what, how many, at what price, its
     * share of the discount, its tax at the rate in force, the stock held, and
     * — for a Supplier-sourced line — the Supplier allocation (D25, P13-21).
     *
     * @param  array<int, StockReservation>  $reservations
     * @param  array<int, SupplierAllocation|null>  $allocations
     * @return Collection<int, OrderItem>
     */
    protected function writeLines(Order $order, CheckoutQuote $quote, array $reservations, array $allocations): Collection
    {
        return collect($quote->lineCharges)->map(
            fn (CheckoutLineCharge $charge, int $position) => $order->items()->create(
                $this->line($charge, $position, $reservations[$position], $allocations[$position]),
            ),
        )->values();
    }

    /**
     * @return array<string, mixed>
     */
    protected function line(CheckoutLineCharge $charge, int $position, StockReservation $reservation, ?SupplierAllocation $allocation = null): array
    {
        $item = $charge->line->item;
        $variant = $item->variant;
        $currency = $charge->discount->currency;

        $unitPrice = $charge->line->unitPrice ?? throw new LogicException('A purchasable line has a price.');
        $subtotal = $charge->line->lineTotal ?? $unitPrice->multipliedBy($item->quantity);
        $discount = $charge->discount->greaterThan($subtotal) ? $subtotal : $charge->discount;

        $inclusive = $charge->tax->mode->isInclusive();
        $added = $inclusive ? Money::zero($currency) : $charge->tax->tax;
        $taxed = ! $charge->tax->isZero();

        return [
            'line_number' => $position + 1,
            'product_id' => $item->product_id,
            'product_variant_id' => $item->product_variant_id,
            'sku' => $variant !== null ? $variant->sku : $item->product->sku,
            'product_name' => Str::limit($item->product->name, 157),
            'variant_label' => $variant?->values
                ->sortBy(fn (ProductAttributeValue $value) => $value->attribute->sort_order)
                ->pluck('value')
                ->implode(' / '),
            'quantity' => $item->quantity,
            'currency_code' => $currency->value,
            'unit_price' => $unitPrice,
            'line_subtotal' => $subtotal,
            'discount' => $discount,
            'tax' => $added,
            'tax_included' => $inclusive ? $charge->tax->tax : Money::zero($currency),
            'line_total' => $subtotal->minus($discount)->plus($added),
            'resale_amount' => $item->resale_amount,
            'tax_code' => $taxed ? $charge->tax->code : null,
            'tax_rate_basis_points' => $taxed ? $charge->tax->rateBasisPoints : null,
            'tax_mode' => $taxed ? $charge->tax->mode->value : null,
            'stock_reservation_id' => $reservation->id,
            ...($allocation?->lineSnapshot() ?? []),
        ];
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
     * Hold the coupon's use against the payment, now that somebody is paying (§9).
     *
     * @throws OrderRefused
     */
    protected function holdCoupon(BusinessAccount $account, Payment $payment, CheckoutQuote $quote): void
    {
        $coupon = $quote->coupon;

        if ($coupon === null || ! $coupon->isAccepted || $coupon->coupon === null || ! $quote->discount->isPositive()) {
            return;
        }

        try {
            $this->coupons->handle($coupon->coupon, $account, $payment, $quote->discount);
        } catch (RuntimeException $exception) {
            throw OrderRefused::couponUnavailable($exception->getMessage());
        }
    }
}
