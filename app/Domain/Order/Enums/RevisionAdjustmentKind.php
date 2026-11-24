<?php

namespace App\Domain\Order\Enums;

use App\Support\Status\HasTranslatedLabel;

/**
 * One compensating record an applied revision caused (D38). Each names the
 * record it is — a payment, a refund request, a reservation, a payable, a
 * ledger entry — which keeps its own state; the adjustment row never changes.
 */
enum RevisionAdjustmentKind: string
{
    use HasTranslatedLabel;

    case PaymentAmountUpdated = 'payment_amount_updated';
    case BalanceDuePayment = 'balance_due_payment';
    case RefundRequest = 'refund_request';
    case WalletDebit = 'wallet_debit';
    case WalletCredit = 'wallet_credit';
    case StockReleased = 'stock_released';
    case StockReserved = 'stock_reserved';
    case StockReturnedFromProcessing = 'stock_returned_from_processing';
    case AllocationStoodDown = 'allocation_stood_down';
    case SupplierPayableCancelled = 'supplier_payable_cancelled';
    case SupplierPayableAccrued = 'supplier_payable_accrued';
    case InvoiceAdjustment = 'invoice_adjustment';
    case CourierFollowUp = 'courier_follow_up';

    protected static function statusLabelGroup(): string
    {
        return 'revision_adjustment';
    }
}
