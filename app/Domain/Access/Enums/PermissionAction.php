<?php

namespace App\Domain\Access\Enums;

/**
 * The twenty permission verbs from requirements.txt §32.2.
 *
 * Permissions are named `<module>.<action>` — `withdrawal.approve`,
 * `kyc.view_kyc_documents`. Keeping the verb list closed means a new module
 * inherits a vocabulary that is already understood, rather than inventing
 * `canApproveWithdrawals` in one place and `withdrawal_approval` in another.
 */
enum PermissionAction: string
{
    case View = 'view';
    case Create = 'create';
    case Edit = 'edit';
    case Delete = 'delete';
    case Archive = 'archive';
    case Approve = 'approve';
    case Reject = 'reject';
    case Verify = 'verify';
    case Export = 'export';
    case Publish = 'publish';
    case Unpublish = 'unpublish';
    case ReleasePayment = 'release_payment';
    case AdjustWallet = 'adjust_wallet';
    case ReverseTransaction = 'reverse_transaction';
    case ManageSettings = 'manage_settings';
    case ViewSensitiveData = 'view_sensitive_data';
    case ViewKycDocuments = 'view_kyc_documents';
    case ViewAuditLogs = 'view_audit_logs';
    case ManageBackups = 'manage_backups';
    case ManageIntegrations = 'manage_integrations';

    public function label(): string
    {
        return match ($this) {
            self::View => 'View',
            self::Create => 'Create',
            self::Edit => 'Edit',
            self::Delete => 'Delete',
            self::Archive => 'Archive',
            self::Approve => 'Approve',
            self::Reject => 'Reject',
            self::Verify => 'Verify',
            self::Export => 'Export',
            self::Publish => 'Publish',
            self::Unpublish => 'Unpublish',
            self::ReleasePayment => 'Release payment',
            self::AdjustWallet => 'Adjust wallet',
            self::ReverseTransaction => 'Reverse transaction',
            self::ManageSettings => 'Manage settings',
            self::ViewSensitiveData => 'View sensitive data',
            self::ViewKycDocuments => 'View KYC documents',
            self::ViewAuditLogs => 'View audit logs',
            self::ManageBackups => 'Manage backups',
            self::ManageIntegrations => 'Manage integrations',
        };
    }

    /**
     * Actions that require the §32.2 escalation — password confirmation, two
     * factor, a second approver, or a mandatory reason — on top of holding the
     * permission itself.
     *
     * Holding a permission answers "may this person do it". Escalation answers
     * "is it really them, right now, and do they mean it". Money movement and
     * access to personal documents need both.
     */
    public function isSensitive(): bool
    {
        return match ($this) {
            self::ReleasePayment,
            self::AdjustWallet,
            self::ReverseTransaction,
            self::ViewSensitiveData,
            self::ViewKycDocuments,
            self::ManageBackups,
            self::ManageIntegrations,
            self::Delete => true,
            default => false,
        };
    }
}
