<?php

namespace App\Domain\Supplier\Exceptions;

use RuntimeException;

/**
 * A Supplier-sourced order line that cannot be allocated to its Admin-chosen
 * preferred offer (D25, P13-21).
 *
 * The `reason` is for staff and logs. It never reaches a Client, a Partner or a
 * Storefront: they are told only that the item cannot be ordered right now,
 * because which Supplier is behind it, and why that offer failed, is
 * Feriwala's own business. And it is a refusal, never a redirect — no other
 * Supplier is ever silently tried in its place.
 */
class SupplierAllocationRefused extends RuntimeException
{
    public const NO_PREFERRED_OFFER = 'no_preferred_offer';

    public const OFFER_INACTIVE = 'offer_inactive';

    public const SUPPLIER_NOT_OPERATIONAL = 'supplier_not_operational';

    public const RATE_UNAVAILABLE = 'rate_unavailable';

    public const CURRENCY_MISMATCH = 'currency_mismatch';

    public const INSUFFICIENT_AVAILABILITY = 'insufficient_availability';

    public function __construct(public readonly string $reason, public readonly int $available = 0)
    {
        parent::__construct('A Supplier allocation was refused: '.$reason.'.');
    }

    public static function noPreferredOffer(): self
    {
        return new self(self::NO_PREFERRED_OFFER);
    }

    public static function offerInactive(): self
    {
        return new self(self::OFFER_INACTIVE);
    }

    public static function supplierNotOperational(): self
    {
        return new self(self::SUPPLIER_NOT_OPERATIONAL);
    }

    public static function rateUnavailable(): self
    {
        return new self(self::RATE_UNAVAILABLE);
    }

    public static function currencyMismatch(): self
    {
        return new self(self::CURRENCY_MISMATCH);
    }

    public static function insufficientAvailability(int $available): self
    {
        return new self(self::INSUFFICIENT_AVAILABILITY, $available);
    }

    /**
     * Whether the offer is fine and only the quantity is short — the one
     * refusal a customer can act on by ordering fewer.
     */
    public function isShortage(): bool
    {
        return $this->reason === self::INSUFFICIENT_AVAILABILITY;
    }
}
