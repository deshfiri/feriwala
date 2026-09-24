<?php

namespace App\Domain\Wholesale\Actions;

use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Wholesale\Exceptions\CheckoutRefused;
use App\Domain\Wholesale\Models\Cart;
use App\Domain\Wholesale\Queries\PriceCheckout;
use App\Integrations\Payment\PaymentGatewayManager;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;

/**
 * Confirm a wholesale checkout (§14, P4-8).
 *
 * The person sends the payment method they chose and the fingerprint of the
 * summary they were shown — never a total. Under a lock on the cart the checkout
 * is priced again, and the confirmation is recorded only when:
 *
 *   - the cart is still ready: every line available, in stock and at a price
 *     already accepted;
 *   - both addresses are given;
 *   - the payment method is a gateway that can take this payment now;
 *   - the summary priced now is the one they saw.
 *
 * Confirming the same summary with the same method again leaves the confirmation
 * as it was. Nothing here takes a payment, places an order or reserves stock.
 */
class ConfirmCheckout
{
    public function __construct(
        protected OpenCart $carts,
        protected PriceCheckout $checkout,
        protected PaymentGatewayManager $gateways,
        protected DatabaseManager $database,
    ) {}

    /**
     * @throws CheckoutRefused
     */
    public function handle(User $user, BusinessAccount $account, string $paymentMethod, string $fingerprintSeen): Cart
    {
        return $this->database->transaction(function () use ($user, $account, $paymentMethod, $fingerprintSeen) {
            $cart = $this->lockedCart($user, $account);

            if ($cart === null) {
                throw CheckoutRefused::cartNotReady();
            }

            $quote = $this->checkout->quote($cart, $account);

            if (! $quote->cart->isReadyForCheckout()) {
                throw CheckoutRefused::cartNotReady();
            }

            if (! $quote->hasAddresses()) {
                throw CheckoutRefused::addressesMissing();
            }

            if (! in_array($paymentMethod, $this->gateways->availableFor($quote->total->currency), true)) {
                throw CheckoutRefused::paymentMethodUnavailable();
            }

            $fingerprint = $quote->fingerprint();

            if (! hash_equals($fingerprint, $fingerprintSeen)) {
                throw CheckoutRefused::changed();
            }

            if ($cart->confirmed_at !== null
                && $cart->confirmed_fingerprint === $fingerprint
                && $cart->payment_method === $paymentMethod) {
                return $cart;
            }

            $cart->forceFill([
                'payment_method' => $paymentMethod,
                'confirmed_at' => CarbonImmutable::now(),
                'confirmed_fingerprint' => $fingerprint,
                'currency_code' => $quote->total->currency->value,
                'confirmed_total' => $quote->total,
            ])->save();

            return $cart;
        });
    }

    /**
     * Withdraw a confirmation, so the order can be changed and confirmed again.
     */
    public function withdraw(User $user, BusinessAccount $account): void
    {
        $this->database->transaction(function () use ($user, $account) {
            $this->lockedCart($user, $account)?->forceFill([
                'payment_method' => null,
                'confirmed_at' => null,
                'confirmed_fingerprint' => null,
                'confirmed_total' => null,
            ])->save();
        });
    }

    protected function lockedCart(User $user, BusinessAccount $account): ?Cart
    {
        $cart = $this->carts->find($user, $account);

        if ($cart === null) {
            return null;
        }

        /** @var Cart|null $locked */
        $locked = Cart::query()->whereKey($cart->id)->lockForUpdate()->first();

        return $locked;
    }
}
