<?php

namespace App\Domain\Damage\Queries;

use App\Domain\Billing\CalculateDeliveryCharge;
use App\Domain\Damage\Models\OrderDamage;
use App\Domain\Wallet\WalletPaymentMethod;
use App\Support\Money\Currency;

/**
 * What delivering a Partner's damaged item to them would cost, before they ask (D35).
 *
 * The same figure {@see RequestDamageDelivery} will charge, from the same
 * delivery-charge service and the same inputs — the item's own logistics and the
 * damaged quantity — so what a Partner confirms is what is debited. It also says
 * whether their wallet can cover it now, so a dialog can warn before the request
 * is refused. It posts nothing and reserves nothing.
 */
class DamageDeliveryQuote
{
    public function __construct(
        protected CalculateDeliveryCharge $delivery,
    ) {}

    /**
     * @return array{charge: array<string, mixed>, quantity: int, wallet_can_cover: bool}|null null when the item cannot be priced
     */
    public function for(OrderDamage $damage): ?array
    {
        $damage->loadMissing(['orderItem.variant', 'orderItem.product', 'businessAccount']);

        $item = $damage->orderItem;
        $sent = $item->variant ?? $item->product;
        $quantity = $damage->damagedQuantity();

        if ($sent === null || $quantity < 1) {
            return null;
        }

        $charge = $this->delivery->handle(
            [['logistics' => $sent->logistics(), 'quantity' => $quantity]],
            Currency::from($damage->currency_code),
        )->finalCharge;

        return [
            'charge' => $charge->jsonSerialize(),
            'quantity' => $quantity,
            'wallet_can_cover' => $charge->isZero() || WalletPaymentMethod::canCover($damage->businessAccount, $charge),
        ];
    }
}
