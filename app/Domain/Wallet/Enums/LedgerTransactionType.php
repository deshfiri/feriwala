<?php

namespace App\Domain\Wallet\Enums;

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

    public function label(): string
    {
        return match ($this) {
            self::DepositCredit => 'Deposit',
            self::TopUpCredit => 'Top-up',
            self::SalesCredit => 'Sales earnings',
            self::CommissionCredit => 'Commission',
            self::ReferralRewardCredit => 'Referral reward',
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
            self::ReferralRewardReversal => 'Referral reward reversal',
            self::ManualAdjustment => 'Manual adjustment',
        };
    }
}
