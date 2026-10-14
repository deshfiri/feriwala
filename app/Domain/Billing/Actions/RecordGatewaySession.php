<?php

namespace App\Domain\Billing\Actions;

use App\Domain\Billing\Models\Payment;

/**
 * Remember the provider's own identifier for the hosted session a payment was
 * sent to, so a support conversation can quote it.
 *
 * Evidence only: nothing settles, matches or trusts a payment by this value.
 */
class RecordGatewaySession
{
    public function handle(string $paymentReference, string $sessionReference): void
    {
        Payment::query()
            ->where('reference', $paymentReference)
            ->update(['gateway_session_reference' => $sessionReference]);
    }
}
