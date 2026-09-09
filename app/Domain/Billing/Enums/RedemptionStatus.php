<?php

namespace App\Domain\Billing\Enums;

/**
 * Where one use of a coupon has got to (§9).
 *
 * Three states, not a boolean, because the interesting one is the middle.
 * **Reserved** is a slot held by a checkout that has not been paid for: it
 * counts against the usage limit, so two people cannot both spend the last one,
 * and it is given back if the payment never arrives.
 */
enum RedemptionStatus: string
{
    /** Held by an unpaid checkout. Counts against the limits. */
    case Reserved = 'reserved';

    /** The payment settled. Permanent. */
    case Redeemed = 'redeemed';

    /** The checkout expired or failed; the slot went back. */
    case Released = 'released';

    public function label(): string
    {
        return match ($this) {
            self::Reserved => 'Held',
            self::Redeemed => 'Used',
            self::Released => 'Released',
        };
    }

    /**
     * Whether this use occupies a slot against the coupon's limits.
     *
     * @return array<int, self>
     */
    public static function counting(): array
    {
        return [self::Reserved, self::Redeemed];
    }
}
