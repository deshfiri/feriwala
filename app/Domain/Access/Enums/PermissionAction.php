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
     * Putting a suspended account back into operation.
     *
     * Its own verb rather than a reuse of `approve`: approval is the decision
     * to let someone in for the first time, and this is the decision to undo a
     * decision — different evidence, different question, and worth being
     * separately grantable even though the default roles hold both.
     */
    case Reactivate = 'reactivate';

    /**
     * Marking a payment paid by hand when the gateway's own confirmation did not
     * land. Its own verb, not `approve` or `reverse_transaction`: it brings money
     * onto the books rather than sending it back, and who may do it is worth
     * granting separately.
     */
    case SettleManually = 'settle_manually';

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
            self::Reactivate => 'Reactivate',
            self::SettleManually => 'Settle manually',
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
            /*
             * And putting one back. Restoring the ability to trade is the
             * decision an attacker would actually want — a suspension that
             * can be quietly lifted protects nothing — so it escalates on the
             * same terms as imposing one.
             */
            self::Reactivate,
            self::SettleManually,
            self::Delete => true,
            default => false,
        };
    }
}
