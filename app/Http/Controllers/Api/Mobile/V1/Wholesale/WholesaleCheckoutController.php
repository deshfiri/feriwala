<?php

namespace App\Http\Controllers\Api\Mobile\V1\Wholesale;

use App\Concerns\ResolvesBusinessAccount;
use App\Domain\Account\Enums\AddressType;
use App\Domain\Account\Models\UserAddress;
use App\Domain\Address\Enums\AddressOwnerType;
use App\Domain\Address\Models\SharedAddress;
use App\Domain\Address\Queries\SharedAddressesForOwner;
use App\Domain\Catalog\ProductEligibility;
use App\Domain\Order\Actions\PlaceWholesaleOrder;
use App\Domain\Order\Enums\IntendedResaleChannel;
use App\Domain\Wallet\WalletPaymentMethod;
use App\Domain\Wholesale\Actions\ApplyCheckoutCoupon;
use App\Domain\Wholesale\Actions\ConfirmCheckout;
use App\Domain\Wholesale\Actions\OpenCart;
use App\Domain\Wholesale\Actions\SaveCheckoutAddress;
use App\Domain\Wholesale\Actions\UseSavedAddressForCheckout;
use App\Domain\Wholesale\Exceptions\CheckoutRefused;
use App\Domain\Wholesale\Models\Cart;
use App\Domain\Wholesale\Queries\PriceCheckout;
use App\Http\Controllers\Api\Mobile\V1\MobileController;
use App\Http\Controllers\Api\Mobile\V1\Wholesale\Concerns\GuardsWholesaleFacility;
use App\Http\Requests\Mobile\Wholesale\CheckoutAddressRequest;
use App\Http\Requests\Mobile\Wholesale\CheckoutCouponRequest;
use App\Http\Requests\Mobile\Wholesale\CheckoutSavedAddressRequest;
use App\Http\Requests\Mobile\Wholesale\ConfirmCheckoutRequest;
use App\Http\Resources\Mobile\Wholesale\CheckoutResource;
use App\Integrations\Payment\PaymentGatewayManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Wholesale checkout (§14).
 *
 * Self-scoped through the signed-in person, like the cart it checks out: there
 * is no cart or address identifier in any URL. Nothing priced by the client is
 * used — a coupon is sent as a code, an address as the address, and the payment
 * as a method and the fingerprint of the summary the person saw.
 *
 * A cart that is not ready — a line with a problem, a price not yet accepted,
 * nothing in it — answers `409` with `code: cart_not_ready`; the cart says why.
 * Every mutation answers with the checkout re-priced.
 */
class WholesaleCheckoutController extends MobileController
{
    use GuardsWholesaleFacility;
    use ResolvesBusinessAccount;

    public function __construct(
        protected OpenCart $carts,
        protected PriceCheckout $checkout,
        protected ProductEligibility $eligibility,
        protected PaymentGatewayManager $gateways,
    ) {}

    /**
     * Show the checkout
     *
     * The caller's cart priced for checkout: lines, coupon, discount, delivery,
     * tax, total, both addresses, the account's saved addresses and the ways to
     * pay (each gateway, and the wallet with its balance). `409` `cart_not_ready`
     * when the cart has a problem, an unaccepted price change, or is empty;
     * `403` `wholesale_not_allowed` when the package excludes wholesale.
     */
    public function show(Request $request): CheckoutResource
    {
        $this->requireWholesale($this->businessAccountFor($request));

        return $this->view($request);
    }

    /**
     * Apply a coupon
     *
     * Validates the code against this cart as the server prices it. `422` with
     * the reason when the code is refused. Answers with the checkout re-priced.
     */
    public function applyCoupon(CheckoutCouponRequest $request, ApplyCheckoutCoupon $apply): CheckoutResource
    {
        $account = $this->businessAccountFor($request);
        $this->requireWholesale($account);

        try {
            $outcome = $apply->handle($this->person($request), $account, $request->validated('code'));
        } catch (CheckoutRefused $refused) {
            throw ValidationException::withMessages([$refused->field => $refused->getMessage()]);
        }

        if (! $outcome->isAccepted) {
            throw ValidationException::withMessages(['code' => $outcome->message()]);
        }

        return $this->view($request);
    }

    /**
     * Remove the coupon
     *
     * Clears any coupon from the cart. Answers with the checkout re-priced.
     */
    public function removeCoupon(Request $request): CheckoutResource
    {
        $account = $this->businessAccountFor($request);
        $cart = $this->carts->find($this->person($request), $account);

        if ($cart !== null) {
            Cart::query()->whereKey($cart->id)->update(['coupon_code' => null]);
        }

        return $this->view($request);
    }

    /**
     * Use a saved address
     *
     * Uses one of the account's saved addresses for this checkout; it sets both
     * the billing and the delivery address. `422` when it is not one of the
     * account's own. Answers with the checkout re-priced.
     */
    public function useAddress(CheckoutSavedAddressRequest $request, UseSavedAddressForCheckout $use): CheckoutResource
    {
        $account = $this->businessAccountFor($request);
        $this->requireWholesale($account);

        try {
            $use->handle($this->person($request), $account, $request->validated('address'));
        } catch (CheckoutRefused $refused) {
            throw ValidationException::withMessages([$refused->field => $refused->getMessage()]);
        }

        return $this->view($request);
    }

    /**
     * Save the billing or shipping address
     *
     * Saves the address typed for `billing` or `shipping`. Shipping may copy the
     * billing address as it stands with `same_as_billing` (`422` when there is no
     * billing address yet). Answers with the checkout re-priced.
     */
    public function updateAddress(CheckoutAddressRequest $request, string $type, SaveCheckoutAddress $save): CheckoutResource
    {
        $account = $this->businessAccountFor($request);
        $this->requireWholesale($account);

        $addressType = AddressType::from($type);
        $person = $this->person($request);

        if ($request->copiesBilling()) {
            $billing = UserAddress::query()->currentFor($person->id, AddressType::Billing)->first();

            if ($billing === null) {
                throw ValidationException::withMessages([
                    'same_as_billing' => __('wholesale.checkout.address.billing_first'),
                ]);
            }

            $fields = $billing->toSnapshot();
        } else {
            $fields = $request->safe()->except('same_as_billing');
        }

        $save->handle($person, $addressType, $fields);

        return $this->view($request);
    }

    /**
     * Confirm the order summary
     *
     * Confirms the summary the person saw with a payment method. Only the method
     * and the summary's `fingerprint` are read; a total, discount or tax sent
     * alongside is ignored. `422` when the summary changed (stale fingerprint),
     * the method is unavailable, or the wallet cannot cover the total. Answers
     * with the checkout, `confirmation` now set.
     */
    public function confirm(ConfirmCheckoutRequest $request, ConfirmCheckout $confirm): CheckoutResource
    {
        $account = $this->businessAccountFor($request);
        $this->requireWholesale($account);

        $validated = $request->validated();

        try {
            $confirm->handle($this->person($request), $account, $validated['payment_method'], $validated['fingerprint']);
        } catch (CheckoutRefused $refused) {
            throw ValidationException::withMessages([$refused->field => $refused->getMessage()]);
        }

        return $this->view($request);
    }

    /**
     * Withdraw the confirmation
     *
     * Drops the held confirmation so the person can change the cart, the coupon,
     * an address or the payment method. Answers with the checkout.
     */
    public function withdrawConfirmation(Request $request, ConfirmCheckout $confirm): CheckoutResource
    {
        $account = $this->businessAccountFor($request);

        $confirm->withdraw($this->person($request), $account);

        return $this->view($request);
    }

    protected function view(Request $request): CheckoutResource
    {
        $account = $this->businessAccountFor($request);
        $cart = $this->carts->find($this->person($request), $account);
        $quote = $this->checkout->quote($cart, $account);

        if ($cart === null || ! $quote->cart->isReadyForCheckout()) {
            abort(response()->json([
                'code' => 'cart_not_ready',
                'message' => __('wholesale.checkout.cart_not_ready'),
            ], 409));
        }

        $methods = $this->gateways->availableFor($quote->total->currency);
        $fingerprint = $quote->fingerprint();

        // The wallet is always on offer, with its balance, and counts as a
        // standing choice only while it can cover the whole total (D34).
        $wallet = WalletPaymentMethod::offer($account, $quote->total);
        $standing = $wallet['sufficient'] ? [...$methods, WalletPaymentMethod::NAME] : $methods;
        $book = $this->addressBook($account->id, $quote->billingAddress);
        $pending = app(PlaceWholesaleOrder::class)->awaitingPaymentFor($cart);

        return new CheckoutResource($quote, [
            'fingerprint' => $fingerprint,
            'payment_methods' => [
                ...array_map(fn (string $name) => [
                    'name' => $name,
                    'label' => $this->gatewayLabel($name),
                    'fx' => $this->gateways->conversionNotice($name, $quote->total->currency, $quote->total),
                    'balance' => null,
                    'sufficient' => true,
                ], $methods),
                $wallet,
            ],
            'confirmation' => $this->confirmation($cart, $fingerprint, $standing),
            'coupon_code' => $cart->coupon_code,
            'address_book' => $book['rows'],
            'selected_address_id' => $book['selected'],
            'pending_order' => $pending === null ? null : ['id' => $pending->public_id, 'reference' => $pending->reference],
            'resale_channels' => IntendedResaleChannel::values(),
        ]);
    }

    /**
     * The account's active saved addresses, and which one this checkout holds.
     *
     * @return array{rows: list<array<string, mixed>>, selected: string|null}
     */
    protected function addressBook(int $accountId, ?UserAddress $current): array
    {
        $use = app(UseSavedAddressForCheckout::class);
        $rows = [];
        $selected = null;

        foreach (app(SharedAddressesForOwner::class)->forOwner(AddressOwnerType::BusinessAccount, $accountId) as $address) {
            /** @var SharedAddress $address */
            if ($address->status->value !== 'active') {
                continue;
            }

            $fields = $use->fieldsFor($address);

            if ($current !== null
                && $current->line_1 === $fields['line_1']
                && $current->postcode === $fields['postcode']
                && $current->contact_name === $fields['contact_name']) {
                $selected = $address->public_id;
            }

            $rows[] = [
                'id' => $address->public_id,
                'is_default' => $address->is_default,
                'contact_name' => $address->contact_name,
                'contact_mobile' => $address->contact_mobile,
                'line' => $address->detailed_address,
                'place' => implode(', ', array_filter([$fields['area'], $fields['city'], $fields['district'], $address->postcode])),
                'has_postcode' => $address->postcode !== null && $address->postcode !== '',
            ];
        }

        return ['rows' => $rows, 'selected' => $selected];
    }

    /**
     * The confirmation on the cart, and whether it still stands: the summary
     * priced now has the fingerprint it was given, and its payment method can
     * still take the payment.
     *
     * @param  array<int, string>  $methods
     * @return array{status: string, payment_method: array{name: string, label: string}, confirmed_at: string, total: \App\Support\Money\Money}|null
     */
    protected function confirmation(Cart $cart, string $fingerprint, array $methods): ?array
    {
        if ($cart->confirmed_at === null
            || $cart->payment_method === null
            || $cart->confirmed_total === null) {
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
            'total' => $cart->confirmed_total,
        ];
    }

    protected function gatewayLabel(string $name): string
    {
        if (WalletPaymentMethod::is($name)) {
            return WalletPaymentMethod::label();
        }

        $label = config("payment.gateways.{$name}.label");

        return is_string($label) && $label !== '' ? $label : $name;
    }
}
