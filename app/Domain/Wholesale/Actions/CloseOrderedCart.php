<?php

namespace App\Domain\Wholesale\Actions;

use App\Domain\Order\Models\Order;
use App\Domain\Wholesale\Models\Cart;

/**
 * What an order's outcome does to the cart it was checked out from (§14, P4-9).
 *
 * Only while the cart still holds the confirmation the order was placed from. A
 * person who has changed the cart since is building another order, and neither
 * outcome touches that.
 *
 *   - **Paid, or paid and held for review** — the cart was bought: its lines, its
 *     coupon and its confirmation go.
 *   - **Cancelled unpaid** — the lines stay for another try, and the confirmation
 *     goes, so the next attempt is confirmed against prices as they are then.
 *
 * Called inside the order's own transaction, after the order row is locked.
 */
class CloseOrderedCart
{
    public function afterPayment(Order $order): void
    {
        $cart = $this->lockedCartFor($order);

        if ($cart === null) {
            return;
        }

        $cart->items()->delete();

        $cart->forceFill([...$this->withoutConfirmation(), 'coupon_code' => null])->save();
    }

    public function afterCancellation(Order $order): void
    {
        $this->lockedCartFor($order)?->forceFill($this->withoutConfirmation())->save();
    }

    protected function lockedCartFor(Order $order): ?Cart
    {
        if ($order->cart_id === null || $order->checkout_fingerprint === null) {
            return null;
        }

        /** @var Cart|null $cart */
        $cart = Cart::query()->whereKey($order->cart_id)->lockForUpdate()->first();

        if ($cart === null || $cart->confirmed_fingerprint === null
            || ! hash_equals($cart->confirmed_fingerprint, $order->checkout_fingerprint)) {
            return null;
        }

        return $cart;
    }

    /**
     * @return array<string, null>
     */
    protected function withoutConfirmation(): array
    {
        return [
            'payment_method' => null,
            'confirmed_at' => null,
            'confirmed_fingerprint' => null,
            'confirmed_total_minor' => null,
        ];
    }
}
