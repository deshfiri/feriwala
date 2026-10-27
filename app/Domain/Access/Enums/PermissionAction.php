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

    /**
     * Turning a wallet's platform credit into withdrawable money (D-new).
     *
     * Its own verb, not `adjust_wallet`: an adjustment decides a balance should
     * change, and this decides that money which could only ever be spent inside
     * the platform may now leave it. Who may do that is worth granting on its own.
     */
    case ConvertBalance = 'convert_balance';
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

    /**
     * Taking a configuration live or off: publishing a KYC batch, switching it on,
     * and choosing which one is the default. Apart from `edit`, because building a
     * batch and deciding that every new applicant is asked for it are different
     * decisions (D30).
     */
    case Activate = 'activate';

    /** Choosing which template a KYC round is opened against (D30). */
    case Assign = 'assign';

    /**
     * Marking a parcel as having arrived (D35). Its own verb: confirming that goods
     * physically reached the warehouse is a different job, and a different person's,
     * from deciding whether they are fit to restock.
     */
    case Receive = 'receive';

    /** Judging goods that came back — counting them and deciding where each unit goes (D35). */
    case Inspect = 'inspect';

    /**
     * Posting the money a case settles: the refund, the charges and the loss (D35).
     * Apart from `inspect` so the person who decides the goods are fine is not the
     * person who decides what is paid for them.
     */
    case Refund = 'refund';

    /** Deciding who bears a damage loss, and for how much (D35). */
    case AssignLiability = 'assign_liability';

    /** Sending a damaged item out of stock to the Partner who owns it (D35). */
    case Dispatch = 'dispatch';

    /**
     * Posting an approved damage loss (D35). Apart from `assign_liability` so the
     * person who decides who bears a loss is not the person who posts it.
     */
    case Finalize = 'finalize';

    /**
     * A Partner reading their own damaged items (D35). Named here so the verb is
     * in the catalogue; a Partner is a business identity and is authorized by
     * ownership of the case, not by holding this permission.
     */
    case ViewSelf = 'view_self';

    /** A Partner asking for their own damaged item to be delivered, and paying for it (D35). Authorized as `view_self` is. */
    case RequestDelivery = 'request_delivery';

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
            self::ConvertBalance => 'Convert balance',
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
            self::Activate => 'Activate',
            self::Assign => 'Assign',
            self::Receive => 'Receive',
            self::Inspect => 'Inspect',
            self::Refund => 'Refund',
            self::AssignLiability => 'Assign liability',
            self::Dispatch => 'Dispatch',
            self::Finalize => 'Finalize',
            self::ViewSelf => 'View own',
            self::RequestDelivery => 'Request delivery',
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
            self::ConvertBalance,
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
