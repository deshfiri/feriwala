<?php

namespace App\Domain\Referral\Enums;

use App\Domain\Billing\Enums\AllocationType;

/**
 * What a percentage reward is a percentage **of** (§25.4 "Calculation base", D24).
 *
 * Taken from the qualifying payment's own allocations — what was actually
 * charged and paid — and never including tax or a deposit: tax is not
 * Feriwala's revenue, and a deposit is the account's own money held for it. A
 * discount on the payment is shared across its revenue lines in proportion,
 * and the share is rounded **up**, so the base rounds down and a commission is
 * never calculated on money nobody paid.
 */
enum CommissionBase: string
{
    /** Registration fee and package fee together. */
    case ActivationFees = 'activation_fees';

    case PackageFee = 'package_fee';

    case RegistrationFee = 'registration_fee';

    /**
     * The payment lines this base counts.
     *
     * @return list<AllocationType>
     */
    public function allocations(): array
    {
        return match ($this) {
            self::ActivationFees => [AllocationType::RegistrationFee, AllocationType::PackageFee],
            self::PackageFee => [AllocationType::PackageFee],
            self::RegistrationFee => [AllocationType::RegistrationFee],
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $base) => $base->value, self::cases());
    }
}
