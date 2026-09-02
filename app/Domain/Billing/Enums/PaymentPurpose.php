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
}
