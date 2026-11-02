<?php

namespace App\Domain\Order\Enums;

use App\Domain\Billing\Enums\GatewayOwnership;

/**
 * How the money for an order line was collected (D36).
 *
 * Cash on delivery and a verified online payment are different facts: one is
 * entered by staff against courier cash, the other is proven by a payment the
 * gateway has confirmed. A settlement row with no channel is from before this
 * existed and is cash on delivery.
 */
enum CollectionChannel: string
{
    /** Staff confirmed the cash collected on delivery. */
    case Cod = 'cod';

    /** The customer paid Feriwala's gateway and the payment was verified. */
    case PlatformGateway = 'platform_gateway';

    /** The customer paid the Partner's own gateway: the Partner holds the money. */
    case PartnerGatewayExternal = 'partner_gateway_external';

    /**
     * The channel a verified payment collects through, from the ownership frozen
     * on it — or null for a payment recorded before ownership existed, which is
     * never reclassified.
     */
    public static function forOwnership(?GatewayOwnership $ownership): ?self
    {
        return match ($ownership) {
            GatewayOwnership::Platform => self::PlatformGateway,
            GatewayOwnership::Partner => self::PartnerGatewayExternal,
            null => null,
        };
    }

    /**
     * Whether Feriwala holds the money, so a Partner's profit can be paid out of it.
     */
    public function feriwalaHoldsTheMoney(): bool
    {
        return $this !== self::PartnerGatewayExternal;
    }
}
