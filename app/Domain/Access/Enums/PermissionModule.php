<?php

namespace App\Domain\Access\Enums;

use App\Domain\Access\PermissionCatalogue;

/**
 * The subjects a permission can apply to, one per core module (§3).
 *
 * Not every verb makes sense for every module — `adjust_wallet` on the CMS is
 * meaningless, and generating it would produce a permission list nobody can
 * audit. {@see PermissionCatalogue} holds the matrix of which
 * verbs each module actually accepts.
 */
enum PermissionModule: string
{
    case Cms = 'cms';
    case Account = 'account';
    case Kyc = 'kyc';
    case Package = 'package';
    case Payment = 'payment';
    case Wallet = 'wallet';
    case Ledger = 'ledger';
    case Catalog = 'catalog';
    case Inventory = 'inventory';
    case Wholesale = 'wholesale';
    case Dropshipping = 'dropshipping';
    case Website = 'website';
    case Order = 'order';
    case Fulfillment = 'fulfillment';
    case Courier = 'courier';
    case Commission = 'commission';
    case Referral = 'referral';
    case Withdrawal = 'withdrawal';
    case Settlement = 'settlement';
    case Notification = 'notification';
    case Sms = 'sms';
    case Report = 'report';
    case Access = 'access';
    case Seo = 'seo';
    case Backup = 'backup';
    case Audit = 'audit';
    case System = 'system';
    case Integration = 'integration';

    public function label(): string
    {
        return match ($this) {
            self::Cms => 'Landing page & CMS',
            self::Account => 'Accounts',
            self::Kyc => 'KYC',
            self::Package => 'Packages',
            self::Payment => 'Payments',
            self::Wallet => 'Wallets',
            self::Ledger => 'Financial ledger',
            self::Catalog => 'Product catalog',
            self::Inventory => 'Inventory',
            self::Wholesale => 'Wholesale',
            self::Dropshipping => 'Dropshipping',
            self::Website => 'Partner websites',
            self::Order => 'Orders',
            self::Fulfillment => 'Fulfillment',
            self::Courier => 'Couriers',
            self::Commission => 'Commissions',
            self::Referral => 'Referrals',
            self::Withdrawal => 'Withdrawals',
            self::Settlement => 'COD & settlement',
            self::Notification => 'Notifications',
            self::Sms => 'SMS',
            self::Report => 'Reports',
            self::Access => 'Roles & permissions',
            self::Seo => 'SEO',
            self::Backup => 'Backups',
            self::Audit => 'Audit logs',
            self::System => 'System & monitoring',
            self::Integration => 'Integrations',
        };
    }
}
