<?php

namespace App\Domain\Order\Enums;

use App\Support\Status\HasTranslatedLabel;

/**
 * What an applied revision meant for the order's money (D38 "Payments").
 *
 *   - **None** — the total did not change.
 *   - **PaymentAmountUpdated** — nobody had paid yet; the waiting payment was
 *     re-recorded at the new total and the old gateway attempt discarded.
 *   - **CodAmountUpdated** — the same, for an amount collected on delivery.
 *   - **BalanceDue** — paid already and now more: a separate payment for the
 *     difference, which dispatch waits for.
 *   - **RefundDue** — paid already and now less: a refund request for the
 *     difference, refunded only once it is processed.
 */
enum RevisionPaymentConsequence: string
{
    use HasTranslatedLabel;

    case None = 'none';
    case PaymentAmountUpdated = 'payment_amount_updated';
    case CodAmountUpdated = 'cod_amount_updated';
    case BalanceDue = 'balance_due';
    case RefundDue = 'refund_due';

    protected static function statusLabelGroup(): string
    {
        return 'revision_payment';
    }

    /** Whether someone still has to act before the money matches the order. */
    public function needsFollowUp(): bool
    {
        return in_array($this, [self::BalanceDue, self::RefundDue], true);
    }
}
