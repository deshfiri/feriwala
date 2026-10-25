<?php

namespace App\Domain\Wallet\Enums;

use App\Domain\Wallet\WalletService;

/**
 * The twenty-six wallet transaction types §23.1 names.
 *
 * Listed in full, including the ones whose owning module has not been built
 * yet. A ledger that could only record what today's code happens to post would
 * have to be migrated every time a module lands, and every such migration is a
 * chance to renumber history.
 *
 * The direction and the bucket each type moves are declared in P2-6 alongside
 * this list, so nothing that posts money has to remember which way a courier
 * charge goes.
 */
enum LedgerTransactionType: string
{
    // Credits.
    case DepositCredit = 'deposit_credit';
    case TopUpCredit = 'top_up_credit';
    case SalesCredit = 'sales_credit';
    case CommissionCredit = 'commission_credit';
    case ReferralRewardCredit = 'referral_reward_credit';
    case JoiningRewardCredit = 'joining_reward_credit';
    case CodCollectionCredit = 'cod_collection_credit';
    case PromotionalCredit = 'promotional_credit';

    // Debits.
    case RegistrationFeeDebit = 'registration_fee_debit';
    case PackageFeeDebit = 'package_fee_debit';
    case WebsiteSetupDebit = 'website_setup_debit';
    case DomainChargeDebit = 'domain_charge_debit';
    case HostingChargeDebit = 'hosting_charge_debit';
    case RenewalFeeDebit = 'renewal_fee_debit';
    case MaintenanceChargeDebit = 'maintenance_charge_debit';
    case FulfillmentChargeDebit = 'fulfillment_charge_debit';
    case CourierChargeDebit = 'courier_charge_debit';
    case PaymentGatewayChargeDebit = 'payment_gateway_charge_debit';
    case PlatformFeeDebit = 'platform_fee_debit';
    case ServiceFeeDebit = 'service_fee_debit';
    case WithdrawalDebit = 'withdrawal_debit';
    case RefundDebit = 'refund_debit';

    /*
     * Corrections (§23.2). These are the only way a posted entry is ever put
     * right: the original stays exactly as it was and a new entry answers it.
     */
    case ReturnAdjustment = 'return_adjustment';
    case CommissionReversal = 'commission_reversal';
    case ReferralRewardReversal = 'referral_reward_reversal';
    case ManualAdjustment = 'manual_adjustment';

    /*
     * Converting platform credit into withdrawable money. The two halves of one
     * decision, each its own entry, sharing a conversion reference. The total
     * does not change: one kind of money becomes the other.
     */
    case BalanceConversionDebit = 'balance_conversion_debit';
    case BalanceConversionCredit = 'balance_conversion_credit';

    /**
     * An order paid out of the wallet, and the money coming back when it is
     * refunded. The platform's own, like the conversions: §23.1 predates paying
     * an order from the wallet.
     *
     * The refund credit has no default kind — it goes back as the kind that was
     * spent, which only the payment it answers knows.
     */
    case WholesaleOrderDebit = 'wholesale_order_debit';
    case OrderRefundCredit = 'order_refund_credit';

    /**
     * The kind of money a credit of this type brings in, where that is not a
     * decision somebody has to make (D-new).
     *
     * Money a customer paid into the wallet, and rewards, are platform credit:
     * spendable inside Banij and never withdrawable. Money the account or the
     * Supplier **earned** is withdrawable. The corrections return null, because
     * a person is deciding — and a decision about money that can leave the
     * platform is not one to default.
     *
     * Lives here, in the domain, so no screen or request can pick the type of a
     * credit it was not entitled to choose.
     */
    public function creditBalanceType(): ?BalanceType
    {
        return match ($this) {
            self::DepositCredit,
            self::TopUpCredit,
            self::ReferralRewardCredit,
            self::JoiningRewardCredit,
            self::PromotionalCredit => BalanceType::PlatformCredit,

            self::SalesCredit,
            self::CommissionCredit,
            self::CodCollectionCredit => BalanceType::Withdrawable,

            self::BalanceConversionCredit => BalanceType::Withdrawable,
            self::BalanceConversionDebit => BalanceType::PlatformCredit,

            default => null,
        };
    }

    /**
     * Which way this type moves money, or null when only the caller knows.
     *
     * Most types have exactly one direction and nothing should have to
     * remember which — a courier charge is a debit whoever posts it, and a
     * commission is a credit. The exceptions are the corrections: an adjustment
     * that could only ever add would be no use for putting right an
     * overpayment, and a return adjustment goes whichever way the return went.
     * Those return null, and {@see WalletService} makes the
     * caller say.
     */
    public function direction(): ?LedgerDirection
    {
        return match ($this) {
            self::DepositCredit,
            self::TopUpCredit,
            self::SalesCredit,
            self::CommissionCredit,
            self::ReferralRewardCredit,
            self::JoiningRewardCredit,
            self::CodCollectionCredit,
            self::PromotionalCredit,
            self::BalanceConversionCredit,
            self::OrderRefundCredit => LedgerDirection::Credit,

            self::BalanceConversionDebit,
            self::WholesaleOrderDebit,
            self::RegistrationFeeDebit,
            self::PackageFeeDebit,
            self::WebsiteSetupDebit,
            self::DomainChargeDebit,
            self::HostingChargeDebit,
            self::RenewalFeeDebit,
            self::MaintenanceChargeDebit,
            self::FulfillmentChargeDebit,
            self::CourierChargeDebit,
            self::PaymentGatewayChargeDebit,
            self::PlatformFeeDebit,
            self::ServiceFeeDebit,
            self::WithdrawalDebit,
            self::RefundDebit => LedgerDirection::Debit,

            // A correction goes whichever way the thing it corrects went.
            self::ReturnAdjustment,
            self::CommissionReversal,
            self::ReferralRewardReversal,
            self::ManualAdjustment => null,
        };
    }

    /**
     * Whether this type exists to put an earlier entry right (§23.2).
     *
     * §23.2 allows corrections only as adjustment, reversal or corrective
     * entries. These are those four, and the posting service requires each of
     * them to carry a reason and — for a reversal — the entry it answers.
     */
    public function isCorrection(): bool
    {
        return $this->direction() === null;
    }

    /**
     * Whether a person has to say why.
     *
     * Every correction, because a figure that changed for no recorded reason is
     * the thing an auditor asks about first. Ordinary trading entries explain
     * themselves through the order or payment they point at.
     */
    public function requiresReason(): bool
    {
        return $this->isCorrection() || $this->isConversion();
    }

    /**
     * Whether this is one half of converting platform credit to withdrawable.
     */
    public function isConversion(): bool
    {
        return $this === self::BalanceConversionDebit || $this === self::BalanceConversionCredit;
    }

    /**
     * Whether posting this needs an administrator behind it.
     *
     * A manual adjustment is somebody deciding to move money by hand; the rest
     * are the system recording something that happened.
     */
    public function requiresActor(): bool
    {
        return $this === self::ManualAdjustment || $this->isConversion();
    }

    public function label(): string
    {
        return match ($this) {
            self::DepositCredit => 'Deposit',
            self::TopUpCredit => 'Top-up',
            self::SalesCredit => 'Sales earnings',
            self::CommissionCredit => 'Commission',
            self::ReferralRewardCredit => 'Partner Network reward',
            self::JoiningRewardCredit => 'Joining reward',
            self::CodCollectionCredit => 'COD collection',
            self::PromotionalCredit => 'Promotional credit',
            self::RegistrationFeeDebit => 'Registration fee',
            self::PackageFeeDebit => 'Package fee',
            self::WebsiteSetupDebit => 'Website setup',
            self::DomainChargeDebit => 'Domain charge',
            self::HostingChargeDebit => 'Hosting charge',
            self::RenewalFeeDebit => 'Renewal fee',
            self::MaintenanceChargeDebit => 'Maintenance charge',
            self::FulfillmentChargeDebit => 'Fulfillment charge',
            self::CourierChargeDebit => 'Courier charge',
            self::PaymentGatewayChargeDebit => 'Payment charge',
            self::PlatformFeeDebit => 'Platform fee',
            self::ServiceFeeDebit => 'Service fee',
            self::WithdrawalDebit => 'Withdrawal',
            self::RefundDebit => 'Refund',
            self::ReturnAdjustment => 'Return adjustment',
            self::CommissionReversal => 'Commission reversal',
            self::ReferralRewardReversal => 'Partner Network reward reversal',
            self::ManualAdjustment => 'Manual adjustment',
            self::BalanceConversionDebit => 'Converted from platform credit',
            self::BalanceConversionCredit => 'Converted to withdrawable',
            self::WholesaleOrderDebit => 'Wholesale order',
            self::OrderRefundCredit => 'Order refund',
        };
    }
}
