<?php

namespace App\Domain\Billing\Data;

use App\Domain\Billing\Models\Payment;

/**
 * What a manual settlement did.
 *
 * `changed` is false when the payment was already settled — a second press of
 * the button — so the caller can say so instead of claiming a new settlement.
 */
class ManualSettlement
{
    public const GATEWAY_VERIFIED = 'gateway_verified';

    public const MANUAL_OVERRIDE = 'manual_override';

    public const ALREADY_SETTLED = 'already_settled';

    public function __construct(
        public readonly Payment $payment,
        public readonly bool $changed,
        public readonly string $basis,
    ) {}
}
