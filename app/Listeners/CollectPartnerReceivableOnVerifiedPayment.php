<?php

namespace App\Listeners;

use App\Domain\Billing\Events\PartnerGatewayPaymentVerified;
use App\Domain\Billing\Models\Payment;
use App\Domain\Order\Actions\ReleaseCollectableReceivables;
use Illuminate\Log\LogManager;
use Throwable;

/**
 * A Partner's own gateway verified a customer's payment: collect Banij's share
 * from the Partner's Wallet (D36).
 *
 * The payment is already settled and committed when this runs, and nothing here
 * may undo it. A failure is logged with ids and the exception class only, and the
 * scheduled sweep collects it on its next pass — the same idempotent path.
 */
class CollectPartnerReceivableOnVerifiedPayment
{
    public function __construct(
        protected ReleaseCollectableReceivables $release,
        protected LogManager $log,
    ) {}

    public function handle(PartnerGatewayPaymentVerified $event): void
    {
        try {
            $payment = Payment::query()->find($event->paymentId);

            if ($payment !== null) {
                $this->release->forPayment($payment);
            }
        } catch (Throwable $throwable) {
            $this->log->channel('payment')->critical('Could not collect a Partner-gateway receivable after its payment was verified', [
                'payment_id' => $event->paymentId,
                'order_id' => $event->orderId,
                'error' => $throwable::class,
            ]);
        }
    }
}
