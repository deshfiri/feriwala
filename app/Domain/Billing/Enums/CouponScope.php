<?php

namespace App\Domain\Billing\Enums;

/**
 * Which part of the bill a coupon comes off (§9).
 *
 * §9 lists the registration fee and the package fee as separate amounts stored
 * separately for reports, invoices, reconciliation, the ledger, refunds and
 * revenue analysis. A discount that could only come off "the total" would break
 * that the moment somebody asked how much of a promotion was registration
 * revenue foregone.
 */
enum CouponScope: string
{
    /** Both fees, apportioned across them at their own tax rates (D19). */
    case Fees = 'fees';

    case RegistrationFee = 'registration_fee';

    case PackageFee = 'package_fee';

    public function label(): string
    {
        return match ($this) {
            self::Fees => 'All fees',
            self::RegistrationFee => 'Registration fee only',
            self::PackageFee => 'Package fee only',
        };
    }

    /**
     * The allocation types this scope discounts.
     *
     * @return array<int, AllocationType>
     */
    public function allocationTypes(): array
    {
        return match ($this) {
            self::Fees => [AllocationType::RegistrationFee, AllocationType::PackageFee],
            self::RegistrationFee => [AllocationType::RegistrationFee],
            self::PackageFee => [AllocationType::PackageFee],
        };
    }
}
