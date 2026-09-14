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

    /**
     * The goods subtotal of an ERP wholesale checkout (§14, P4-6).
     *
     * A scope of its own rather than folded into "all fees": a wholesale discount
     * comes off what the account buys, not off what it paid to join, and a code
     * meant for one must never quietly discount the other.
     */
    case WholesaleOrder = 'wholesale_order';

    public function label(): string
    {
        return match ($this) {
            self::Fees => 'All fees',
            self::RegistrationFee => 'Registration fee only',
            self::PackageFee => 'Package fee only',
            self::WholesaleOrder => 'Wholesale orders',
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
            // A wholesale discount comes off goods, not off any activation fee.
            self::WholesaleOrder => [],
        };
    }
}
