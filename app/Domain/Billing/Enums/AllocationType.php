<?php

namespace App\Domain\Billing\Enums;

/**
 * The components one payment is split into (§5.1, §9).
 *
 * §5.1 is explicit: the registration fee and the package fee are paid together
 * but must appear separately in the payment breakdown, the invoice, and the
 * financial ledger. §9 extends that to discount, tax, gateway charge, and any
 * wallet deposit collected in the same transaction.
 *
 * Storing only a total would make every one of those impossible to report on,
 * refund individually, or reconcile — so the split is the data model, not a
 * presentation detail.
 */
enum AllocationType: string
{
    case RegistrationFee = 'registration_fee';
    case PackageFee = 'package_fee';
    case RenewalFee = 'renewal_fee';
    case WalletDeposit = 'wallet_deposit';
    case WebsiteSetup = 'website_setup';
    case DomainCharge = 'domain_charge';
    case HostingCharge = 'hosting_charge';
    case MaintenanceCharge = 'maintenance_charge';
    case Discount = 'discount';
    case Tax = 'tax';
    case GatewayCharge = 'gateway_charge';

    public function label(): string
    {
        return match ($this) {
            self::RegistrationFee => 'Registration fee',
            self::PackageFee => 'Package fee',
            self::RenewalFee => 'Renewal fee',
            self::WalletDeposit => 'Wallet deposit',
            self::WebsiteSetup => 'Website setup',
            self::DomainCharge => 'Domain',
            self::HostingCharge => 'Hosting',
            self::MaintenanceCharge => 'Maintenance',
            self::Discount => 'Discount',
            self::Tax => 'VAT',
            self::GatewayCharge => 'Payment charge',
        };
    }

    /**
     * Whether this component reduces the total rather than adding to it.
     *
     * Discount is stored as a positive amount with this flag rather than as a
     * negative number, so a report summing "total discount given" does not have
     * to remember to flip the sign.
     */
    public function isDeduction(): bool
    {
        return $this === self::Discount;
    }

    /**
     * Whether this component is revenue Feriwala earns.
     *
     * A wallet deposit is not: it remains the partner's money, held on their
     * behalf, and counting it as revenue would overstate earnings and
     * understate liabilities.
     */
    public function isRevenue(): bool
    {
        return match ($this) {
            self::WalletDeposit, self::Tax, self::GatewayCharge, self::Discount => false,
            default => true,
        };
    }

    /**
     * Whether tax applies to this component.
     *
     * A deposit is the partner's own money being placed on account, not a sale,
     * so taxing it would be charging VAT on someone's savings.
     */
    public function isTaxable(): bool
    {
        return match ($this) {
            self::RegistrationFee,
            self::PackageFee,
            self::RenewalFee,
            self::WebsiteSetup,
            self::DomainCharge,
            self::HostingCharge,
            self::MaintenanceCharge => true,
            default => false,
        };
    }
}
