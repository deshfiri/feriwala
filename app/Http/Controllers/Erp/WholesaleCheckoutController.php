<?php

namespace App\Http\Controllers\Erp;

use App\Concerns\ResolvesBusinessAccount;
use App\Domain\Account\Enums\AddressType;
use App\Domain\Account\Models\UserAddress;
use App\Domain\Catalog\Enums\SalesChannel;
use App\Domain\Catalog\Models\ProductAttributeValue;
use App\Domain\Catalog\ProductEligibility;
use App\Domain\Order\Actions\PlaceWholesaleOrder;
use App\Domain\Order\Enums\IntendedResaleChannel;
use App\Domain\Wholesale\Actions\ApplyCheckoutCoupon;
use App\Domain\Wholesale\Actions\ConfirmCheckout;
use App\Domain\Wholesale\Actions\OpenCart;
use App\Domain\Wholesale\Actions\SaveCheckoutAddress;
use App\Domain\Wholesale\Data\CartLineQuote;
use App\Domain\Wholesale\Exceptions\CheckoutRefused;
use App\Domain\Wholesale\Models\Cart;
use App\Domain\Wholesale\Queries\PriceCheckout;
use App\Http\Controllers\Controller;
use App\Integrations\Payment\PaymentGatewayManager;
use App\Models\User;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * ERP wholesale checkout (§14).
 *
 * **Self-scoped through the signed-in person**, like the cart it checks out: there
 * is no cart or address identifier in any URL or form. **Nothing priced by the
 * browser is used** — the page shows what the server priced on this request, a
 * coupon is sent as a code, and an address as the address and nothing else.
 *
 * A cart that is not ready — a line with a problem, a price not yet accepted,
 * nothing in it — is sent back to the cart, where the reason is shown.
 */
class WholesaleCheckoutController extends Controller
{
    use ResolvesBusinessAccount;

    public function __construct(
        protected OpenCart $carts,
        protected PriceCheckout $checkout,
        protected ProductEligibility $eligibility,
        protected PaymentGatewayManager $gateways,
    ) {}

    public function show(Request $request, PlaceWholesaleOrder $orders): Response|RedirectResponse
    {
        $account = $this->businessAccountFor($request);

        if (! $this->eligibility->allowsChannel($account, SalesChannel::Wholesale)) {
            return redirect()->route('wholesale.cart.show');
        }

        $cart = $this->carts->find($this->person($request), $account);
        $quote = $this->checkout->quote($cart, $account);

        if ($cart === null || ! $quote->cart->isReadyForCheckout()) {
            Inertia::flash('toast', ['type' => 'warning', 'message' => __('wholesale.checkout.cart_not_ready')]);

            return redirect()->route('wholesale.cart.show');
        }

        $methods = $this->gateways->availableFor($quote->total->currency);
        $fingerprint = $quote->fingerprint();
        $pending = $orders->awaitingPaymentFor($cart);

        return Inertia::render('wholesale/checkout', [
            'checkout' => [
                'fingerprint' => $fingerprint,
                'payment_methods' => array_map(fn (string $name) => [
                    'name' => $name,
                    'label' => $this->gatewayLabel($name),
                ], $methods),
                'confirmation' => $this->confirmation($cart, $fingerprint, $methods),
                'lines' => array_map(fn (CartLineQuote $line) => $this->line($line), $quote->cart->lines),
                'subtotal' => $quote->cart->subtotal->jsonSerialize(),
                'coupon' => $cart->coupon_code === null ? null : [
                    'entered' => $cart->coupon_code,
                    ...($quote->coupon?->toArray() ?? []),
                ],
                'discount' => $quote->discount->jsonSerialize(),
                'delivery' => $quote->delivery->jsonSerialize(),
                'tax' => $quote->tax->toArray(),
                'tax_added' => $quote->tax->addedTotal()->jsonSerialize(),
                'total' => $quote->total->jsonSerialize(),
                'addresses' => [
                    'billing' => $quote->billingAddress?->toSnapshot(),
                    'shipping' => $quote->shippingAddress?->toSnapshot(),
                ],
                'ready_to_confirm' => $quote->isReadyToConfirm(),
                // An order from this cart already waiting for payment (P4-9).
                'pending_order' => $pending === null ? null : [
                    'id' => $pending->public_id,
                    'reference' => $pending->reference,
                ],
                'resale_channels' => IntendedResaleChannel::values(),
            ],
        ]);
    }

    public function applyCoupon(Request $request, ApplyCheckoutCoupon $apply): RedirectResponse
    {
        $account = $this->businessAccountFor($request);

        abort_unless($this->eligibility->allowsChannel($account, SalesChannel::Wholesale), 403);

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:64'],
        ]);

        try {
            $outcome = $apply->handle($this->person($request), $account, $validated['code']);
        } catch (CheckoutRefused $refused) {
            throw ValidationException::withMessages([$refused->field => $refused->getMessage()]);
        }

        if (! $outcome->isAccepted) {
            throw ValidationException::withMessages(['code' => $outcome->message()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('wholesale.checkout.coupon_applied', ['code' => $outcome->coupon?->code])]);

        return back();
    }

    public function removeCoupon(Request $request): RedirectResponse
    {
        $account = $this->businessAccountFor($request);
        $cart = $this->carts->find($this->person($request), $account);

        if ($cart !== null) {
            Cart::query()->whereKey($cart->id)->update(['coupon_code' => null]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('wholesale.checkout.coupon_removed')]);

        return back();
    }

    /**
     * Save the billing or shipping address (P4-7).
     *
     * Each form has its own error bag, so a mistake in one never shows against the
     * other. Shipping can copy the billing address as it stands.
     */
    public function updateAddress(Request $request, string $type, SaveCheckoutAddress $save): RedirectResponse
    {
        $account = $this->businessAccountFor($request);

        abort_unless($this->eligibility->allowsChannel($account, SalesChannel::Wholesale), 403);

        $addressType = AddressType::from($type);
        $person = $this->person($request);

        if ($addressType === AddressType::Shipping && $request->boolean('same_as_billing')) {
            $billing = UserAddress::query()->currentFor($person->id, AddressType::Billing)->first();

            if ($billing === null) {
                throw ValidationException::withMessages([
                    'same_as_billing' => __('wholesale.checkout.address.billing_first'),
                ])->errorBag($addressType->value);
            }

            $fields = $billing->toSnapshot();
        } else {
            $fields = $request->validateWithBag($addressType->value, [
                'contact_name' => ['required', 'string', 'max:255'],
                'contact_mobile' => ['required', 'string', 'max:20', 'regex:/^\+?[0-9][0-9 -]{5,18}$/'],
                'line_1' => ['required', 'string', 'max:255'],
                'line_2' => ['nullable', 'string', 'max:255'],
                'area' => ['nullable', 'string', 'max:255'],
                'city' => ['required', 'string', 'max:255'],
                'district' => ['nullable', 'string', 'max:255'],
                'postcode' => ['nullable', 'string', 'max:16'],
            ], [
                'contact_mobile.regex' => __('wholesale.checkout.address.mobile_invalid'),
            ]);
        }

        $save->handle($person, $addressType, $fields);

        Inertia::flash('toast', ['type' => 'success', 'message' => __("wholesale.checkout.address.saved_{$addressType->value}")]);

        return back();
    }

    /**
     * Confirm the order summary with a payment method (P4-8).
     *
     * Only the method and the fingerprint of the summary on the page are read; a
     * total, discount or tax sent alongside is ignored.
     */
    public function confirm(Request $request, ConfirmCheckout $confirm): RedirectResponse
    {
        $account = $this->businessAccountFor($request);

        abort_unless($this->eligibility->allowsChannel($account, SalesChannel::Wholesale), 403);

        $validated = $request->validate([
            'payment_method' => ['required', 'string', 'max:32'],
            'fingerprint' => ['required', 'string', 'size:64'],
        ]);

        try {
            $confirm->handle($this->person($request), $account, $validated['payment_method'], $validated['fingerprint']);
        } catch (CheckoutRefused $refused) {
            throw ValidationException::withMessages([$refused->field => $refused->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('wholesale.checkout.confirmation.confirmed_toast')]);

        return back();
    }

    public function withdrawConfirmation(Request $request, ConfirmCheckout $confirm): RedirectResponse
    {
        $account = $this->businessAccountFor($request);

        $confirm->withdraw($this->person($request), $account);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('wholesale.checkout.confirmation.withdrawn_toast')]);

        return back();
    }

    /**
     * The confirmation on the cart, and whether it still stands: the summary
     * priced now has the fingerprint it was given, and its payment method can
     * still take the payment.
     *
     * @param  array<int, string>  $methods
     * @return array<string, mixed>|null
     */
    protected function confirmation(Cart $cart, string $fingerprint, array $methods): ?array
    {
        if ($cart->confirmed_at === null
            || $cart->payment_method === null
            || $cart->confirmed_total_minor === null) {
            return null;
        }

        $stands = $cart->confirmed_fingerprint !== null
            && hash_equals($cart->confirmed_fingerprint, $fingerprint)
            && in_array($cart->payment_method, $methods, true);

        return [
            'status' => $stands ? 'confirmed' : 'stale',
            'payment_method' => [
                'name' => $cart->payment_method,
                'label' => $this->gatewayLabel($cart->payment_method),
            ],
            'confirmed_at' => $cart->confirmed_at->toIso8601String(),
            'total' => Money::of($cart->confirmed_total_minor, Currency::from($cart->currency_code))->jsonSerialize(),
        ];
    }

    protected function gatewayLabel(string $name): string
    {
        $label = config("payment.gateways.{$name}.label");

        return is_string($label) && $label !== '' ? $label : $name;
    }

    /**
     * @return array<string, mixed>
     */
    protected function line(CartLineQuote $line): array
    {
        $item = $line->item;
        $variant = $item->variant;

        return [
            'id' => $item->public_id,
            'name' => $item->product->name,
            'sku' => $variant !== null ? $variant->sku : $item->product->sku,
            'variant' => $variant?->values
                ->sortBy(fn (ProductAttributeValue $value) => $value->attribute->sort_order)
                ->pluck('value')
                ->implode(' / '),
            'quantity' => $item->quantity,
            'unit_price' => $line->unitPrice?->jsonSerialize(),
            'line_total' => $line->lineTotal?->jsonSerialize(),
        ];
    }

    protected function person(Request $request): User
    {
        $user = $request->user();

        abort_if(! $user instanceof User, 403);

        return $user;
    }
}
