<?php

namespace App\Domain\Billing\Enums;

/**
 * Whose gateway a customer paid, frozen on the payment (D36).
 *
 * A payment with no ownership at all was recorded before this existed and keeps
 * the platform behaviour it always had; it is never read as either value.
 */
enum GatewayOwnership: string
{
    /** Feriwala's own gateway: Feriwala is merchant of record (Model A, D12). */
    case Platform = 'platform';

    /** The Partner's own gateway: the Partner is the payment recipient (Model B, D36). */
    case Partner = 'partner';
}
