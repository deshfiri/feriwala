<?php

namespace App\Domain\Access\Enums;

use App\Domain\Access\Enums\PermissionAction as Action;
use App\Domain\Access\Enums\PermissionModule as Module;
use App\Domain\Access\PermissionCatalogue;

/**
 * The twenty administrative roles from requirements.txt §32.1.
 *
 * These are **platform** roles. They are held against the whole system, not
 * against a partner account, and are stored with a null `account_id` (D2). Staff
 * roles inside a partner account are a separate scope and never appear here —
 * mixing the two is how a partner's bookkeeper ends up able to approve
 * withdrawals for everyone.
 *
 * Each role is deliberately narrow. §32.2 pairs permissions with escalation for
 * sensitive actions, so a role granting `withdrawal.release_payment` still
 * requires confirmation at the moment of use.
 */
enum PlatformRole: string
{
    case SuperAdmin = 'super_admin';
    case Admin = 'admin';
    case ContentManager = 'content_manager';
    case KycManager = 'kyc_manager';
    case PackageManager = 'package_manager';
    case ProductManager = 'product_manager';
    case InventoryManager = 'inventory_manager';
    case OrderManager = 'order_manager';
    case FulfillmentManager = 'fulfillment_manager';
    case CourierManager = 'courier_manager';
    case FinanceManager = 'finance_manager';
    case WalletManager = 'wallet_manager';
    case WithdrawalApprover = 'withdrawal_approver';
    case PaymentManager = 'payment_manager';
    case ReferralManager = 'referral_manager';
    case SmsManager = 'sms_manager';
    case SeoManager = 'seo_manager';
    case ReportViewer = 'report_viewer';
    case SystemAdministrator = 'system_administrator';
    case BackupManager = 'backup_manager';

    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Super Admin',
            self::Admin => 'Admin',
            self::ContentManager => 'Content Manager',
            self::KycManager => 'KYC Manager',
            self::PackageManager => 'Package Manager',
            self::ProductManager => 'Product Manager',
            self::InventoryManager => 'Inventory Manager',
            self::OrderManager => 'Order Manager',
            self::FulfillmentManager => 'Fulfillment Manager',
            self::CourierManager => 'Courier Manager',
            self::FinanceManager => 'Finance Manager',
            self::WalletManager => 'Wallet Manager',
            self::WithdrawalApprover => 'Withdrawal Approver',
            self::PaymentManager => 'Payment Manager',
            self::ReferralManager => 'Referral Manager',
            self::SmsManager => 'SMS Manager',
            self::SeoManager => 'SEO Manager',
            self::ReportViewer => 'Report Viewer',
            self::SystemAdministrator => 'System Administrator',
            self::BackupManager => 'Backup Manager',
        };
    }

    /**
     * Super Admin is granted everything implicitly via a Gate::before rule
     * rather than by holding 200-odd permission rows, so the grant cannot drift
     * out of step with the catalogue as modules are added.
     */
    public function grantsEverything(): bool
    {
        return $this === self::SuperAdmin;
    }

    /**
     * The permission names this role holds.
     *
     * @return array<int, string>
     */
    public function permissions(): array
    {
        if ($this->grantsEverything()) {
            return PermissionCatalogue::all();
        }

        $permissions = [];

        foreach ($this->grants() as $module => $actions) {
            foreach ($actions as $action) {
                $name = $module.'.'.$action->value;

                if (PermissionCatalogue::exists($name)) {
                    $permissions[] = $name;
                }
            }
        }

        sort($permissions);

        return array_values(array_unique($permissions));
    }

    /**
     * Module-to-verb grants for this role, keyed by module value.
     *
     * A verb listed here that the module does not accept is dropped by
     * {@see permissions()} rather than inventing a permission — the catalogue
     * stays the single source of truth.
     *
     * @return array<string, array<int, Action>>
     */
    protected function grants(): array
    {
        $read = [Action::View, Action::Export];
        $manage = [Action::View, Action::Create, Action::Edit, Action::Delete, Action::Export];
        $review = [Action::Approve, Action::Reject, Action::Verify];

        return match ($this) {
            self::SuperAdmin => [],

            // Broad operational oversight, but deliberately not money movement,
            // KYC documents, backups, or permissions.
            self::Admin => [
                Module::Account->value => [...$manage, ...$review],
                Module::Package->value => $manage,
                Module::Catalog->value => $manage,
                Module::Order->value => [...$manage, ...$review],
                Module::Inventory->value => $read,
                Module::Website->value => $manage,
                Module::Report->value => $read,
                Module::Notification->value => [Action::View, Action::Create, Action::Edit],
                Module::Audit->value => [Action::ViewAuditLogs],
            ],

            self::ContentManager => [
                Module::Cms->value => [...$manage, Action::Publish, Action::Unpublish, Action::Archive],
                Module::Seo->value => [Action::View, Action::Edit],
            ],

            self::KycManager => [
                Module::Kyc->value => [
                    Action::View, Action::Edit, Action::Export,
                    ...$review, Action::ViewKycDocuments, Action::ManageSettings,
                ],
                Module::Account->value => [Action::View, Action::Export],
            ],

            self::PackageManager => [
                Module::Package->value => [...$manage, Action::Archive, Action::Approve, Action::ManageSettings],
                Module::Account->value => [Action::View],
            ],

            self::ProductManager => [
                Module::Catalog->value => [
                    ...$manage, Action::Archive, Action::Publish, Action::Unpublish,
                ],
                Module::Inventory->value => [Action::View],
                Module::Dropshipping->value => [Action::View, Action::Publish, Action::Unpublish],
            ],

            self::InventoryManager => [
                Module::Inventory->value => [Action::View, Action::Edit, Action::Approve, Action::Export],
                Module::Catalog->value => [Action::View],
                Module::Fulfillment->value => [Action::View],
            ],

            self::OrderManager => [
                Module::Order->value => [...$manage, ...$review, Action::Archive, Action::ManageSettings],
                Module::Inventory->value => [Action::View],
                Module::Fulfillment->value => [Action::View, Action::Create],
                Module::Courier->value => [Action::View],
            ],

            self::FulfillmentManager => [
                Module::Fulfillment->value => [Action::View, Action::Create, Action::Edit, Action::Approve, Action::Export],
                Module::Order->value => [Action::View, Action::Edit],
                Module::Inventory->value => [Action::View],
                Module::Courier->value => [Action::View, Action::Create],
            ],

            self::CourierManager => [
                Module::Courier->value => [...$manage, Action::Approve, Action::ManageIntegrations],
                Module::Order->value => [Action::View],
                Module::Settlement->value => [Action::View, Action::Export],
            ],

            // Sees the whole financial picture and may reverse, but releasing a
            // payout is a separate role so the person who books an amount is not
            // the person who pays it out.
            self::FinanceManager => [
                Module::Ledger->value => [Action::View, Action::Export, Action::ViewSensitiveData],
                Module::Payment->value => [Action::View, Action::Verify, Action::Export, ...$review],
                Module::Wallet->value => [Action::View, Action::Export, Action::Approve],
                Module::Commission->value => [Action::View, Action::Export, ...$review, Action::ReverseTransaction],
                Module::Settlement->value => [Action::View, Action::Create, Action::Approve, Action::Export],
                Module::Withdrawal->value => [Action::View, Action::Export],
                Module::Report->value => [Action::View, Action::Export, Action::ViewSensitiveData],
            ],

            self::WalletManager => [
                Module::Wallet->value => [
                    Action::View, Action::AdjustWallet, Action::ReverseTransaction,
                    Action::Export, Action::ManageSettings,
                ],
                Module::Ledger->value => [Action::View, Action::Export],
            ],

            // Exists to release payouts and nothing else.
            self::WithdrawalApprover => [
                Module::Withdrawal->value => [
                    Action::View, Action::Edit, Action::Approve, Action::Reject,
                    Action::ReleasePayment, Action::Export,
                ],
                Module::Wallet->value => [Action::View],
                Module::Kyc->value => [Action::View],
            ],

            self::PaymentManager => [
                Module::Payment->value => [
                    Action::View, Action::Create, Action::Verify, Action::Export,
                    ...$review, Action::ManageSettings, Action::ManageIntegrations,
                ],
                Module::Ledger->value => [Action::View],
            ],

            self::ReferralManager => [
                Module::Referral->value => [
                    Action::View, Action::Create, Action::Edit, Action::Export,
                    ...$review, Action::ManageSettings,
                ],
                Module::Commission->value => [Action::View],
            ],

            self::SmsManager => [
                Module::Sms->value => [...$manage, Action::ManageSettings, Action::ManageIntegrations],
                Module::Notification->value => [Action::View, Action::Create, Action::Edit],
            ],

            self::SeoManager => [
                Module::Seo->value => [Action::View, Action::Edit, Action::ManageSettings],
                Module::Cms->value => [Action::View, Action::Edit],
            ],

            // Reads reports but not the sensitive columns inside them.
            self::ReportViewer => [
                Module::Report->value => [Action::View, Action::Export],
            ],

            self::SystemAdministrator => [
                Module::System->value => [Action::View, Action::ManageSettings, Action::ManageIntegrations],
                Module::Integration->value => [Action::View, Action::ManageIntegrations, Action::ManageSettings],
                Module::Access->value => [...$manage, Action::ManageSettings],
                Module::Audit->value => [Action::ViewAuditLogs, Action::Export],
            ],

            self::BackupManager => [
                Module::Backup->value => [Action::View, Action::Create, Action::ManageBackups],
                Module::System->value => [Action::View],
            ],
        };
    }
}
