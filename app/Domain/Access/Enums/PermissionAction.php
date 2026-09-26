<?php

namespace App\Domain\Access\Enums;

/**
 * The permission verbs from requirements.txt §32.2 — the original twenty, and
 * View settings added by D24.
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

    /** Seeing a configuration without the right to change it (§32.2 as amended by D24). */
    case ViewSettings = 'view_settings';

    /** Taking a Supplier out of operation, or a listing out of the queue, without deciding it (D25). */
    case Suspend = 'suspend';

    /** Working a queue item — requesting a correction or refusing it — short of the stronger approval verb (D25). */
    case Review = 'review';

    /**
     * Browsing the CMS media library to pick an existing asset for a
     * section or an SEO image — separate from `Edit`, so an SEO manager
     * can source an Open Graph image without holding ordinary CMS edit
     * rights, and separate from `MediaManage`, so browsing never implies
     * upload rights (Stage 7 addendum).
     */
    case MediaView = 'media.view';

    /**
     * Uploading, editing or removing a CMS media asset — its own
     * authority from ordinary CMS content editing, so the person who
     * writes section copy is not automatically the person who may
     * introduce new files onto the server (Stage 7 addendum).
     */
    case MediaManage = 'media.manage';

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
            self::ViewSettings => 'View settings',
            self::Suspend => 'Suspend',
            self::Review => 'Review',
            self::MediaView => 'View media library',
            self::MediaManage => 'Manage media library',
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
            // Taking a Supplier out of operation needs a recorded reason and
            // is the kind of decision a second factor should stand behind
            // (D25). Holding `supplier.suspend` is what makes Supplier Manager
            // a two-factor role.
            self::Suspend,
            self::Delete => true,
            default => false,
        };
    }
}
