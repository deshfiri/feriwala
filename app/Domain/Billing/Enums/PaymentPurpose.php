<?php

namespace App\Domain\Billing\Enums;

/**
 * What a payment is for (§26.3).
 */
enum PaymentPurpose: string
{
    case Activation = 'activation';
    case PackageRenewal = 'package_renewal';
    case PackageUpgrade = 'package_upgrade';

    /**
     * Moving to a smaller package (§8.3).
     *
     * Its own purpose rather than folded into renewal or upgrade: a downgrade
     * takes effect at the end of the term rather than at once, so the payment
     * unlocks something different, and a report separating "moved up" from
     * "moved down" is reading revenue that means different things.
     */
    case PackageDowngrade = 'package_downgrade';
    case WholesaleOrder = 'wholesale_order';
    case WebsiteOrder = 'website_order';
    case WalletDeposit = 'wallet_deposit';
    case WalletTopUp = 'wallet_top_up';
    case WebsiteSetup = 'website_setup';
    case DomainCharge = 'domain_charge';
    case HostingCharge = 'hosting_charge';
    case MaintenanceCharge = 'maintenance_charge';

    public function label(): string
    {
        return match ($this) {
            self::Activation => 'Account activation',
            self::PackageRenewal => 'Package renewal',
            self::PackageUpgrade => 'Package upgrade',
            self::PackageDowngrade => 'Package downgrade',
            self::WholesaleOrder => 'Wholesale order',
            self::WebsiteOrder => 'Website order',
            self::WalletDeposit => 'Wallet deposit',
            self::WalletTopUp => 'Wallet top-up',
            self::WebsiteSetup => 'Website setup',
            self::DomainCharge => 'Domain',
            self::HostingCharge => 'Hosting',
            self::MaintenanceCharge => 'Maintenance',
        };
    }

    /**
     * Whether the module this payment unlocks has been built.
     *
     * §26.3 lists twelve purposes and the platform is being built in phases, so
     * for a while some of these are real payments with nothing on the other side
     * yet. Saying which is which out loud is what stops a settled payment for an
     * unbuilt module looking like a payment that did nothing wrong by accident.
     *
     * A settlement for one of these still records everything — the money, the
     * verification, the receipt — and flags itself for a person rather than
     * quietly succeeding.
     */
    public function isDeliverable(): bool
    {
        return match ($this) {
            // Built, with a consequence wired to settlement.
            self::Activation,
            self::PackageRenewal,
            self::PackageUpgrade,
            self::PackageDowngrade,
            // Settling confirms the ERP wholesale order it pays for (P4-9).
            self::WholesaleOrder,
            self::WalletDeposit,
            self::WalletTopUp => true,

            /*
             * Not built yet. Website orders (§16–§17), websites (§18–§20) and
             * the recurring charges that hang off them are later phases, and a
             * payment cannot be taken for one through any route that exists
             * today — which is asserted rather than assumed.
             */
            self::WebsiteOrder,
            self::WebsiteSetup,
            self::DomainCharge,
            self::HostingCharge,
            self::MaintenanceCharge => false,
        };
    }

    /**
     * Whether the invoice is issued when the payment is recorded (§8.2).
     *
     * An activation, renewal or top-up invoice is what somebody is asked to pay,
     * so it exists before the money does. A wholesale order's invoice is a record
     * of a sale, issued once the payment has settled and the order is paid
     * (P4-11) — an order nobody paid for never carries one.
     */
    public function issuesInvoiceWhenRecorded(): bool
    {
        return $this !== self::WholesaleOrder;
    }

    /**
     * Whether settling this puts money **into** the account's wallet (§23.1).
     *
     * Two of the twelve do. Everything else is money paid *to* Feriwala, and
     * crediting a wallet for one of those would hand back what was just charged.
     */
    public function creditsWallet(): bool
    {
        return $this === self::WalletDeposit || $this === self::WalletTopUp;
    }
}
