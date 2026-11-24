<?php

namespace App\Domain\Order\Enums;

use App\Support\Status\HasTranslatedLabel;

/**
 * Where one order revision stands (D38).
 *
 * A revision row is written only when it is applied, and it never changes
 * afterwards: what follows from it — a balance-due payment, a refund request,
 * a courier reconciliation — is its own record with its own state. The column
 * exists so a later state (an approval step, say) can be added by decision
 * without reshaping the table; the database accepts `applied` alone today.
 */
enum OrderRevisionStatus: string
{
    use HasTranslatedLabel;

    case Applied = 'applied';

    protected static function statusLabelGroup(): string
    {
        return 'order_revision';
    }
}
