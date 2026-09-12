<?php

namespace App\Domain\Billing\Actions;

use App\Domain\Billing\Enums\RefundStatus;
use App\Domain\Billing\Models\Payment;
use App\Domain\Billing\Models\RefundRequest;
use App\Integrations\Payment\Data\GatewayCapability;
use App\Integrations\Payment\Data\RefundResult;
use App\Integrations\Payment\Exceptions\GatewayUnavailable;
use App\Integrations\Payment\PaymentGatewayManager;
use Illuminate\Log\LogManager;

/**
 * Ask a provider what became of a refund it accepted (§26.3).
 *
 * The other half of "provider confirmation is required before a reversal is
 * treated as completed". A refund frequently comes back accepted rather than
 * settled, and something has to go and ask later — otherwise an approved refund
 * holds its claim on the payment's remaining amount for ever and never reverses
 * anything.
 *
 * Only moves a refund **forward**. A provider that cannot be reached, or answers
 * with something the driver has no documented meaning for, leaves the refund
 * exactly where it was: still approved, still claiming its amount, still checked
 * on the next sweep.
 */
class SettleRefund
{
    public function __construct(
        protected PaymentGatewayManager $gateways,
        protected ProcessRefund $refunds,
        protected LogManager $log,
    ) {}

    /**
     * Every refund a provider accepted and has not confirmed.
     *
     * An approved request that carries a provider reference has been sent and
     * not settled — which is precisely the set worth asking about. One that was
     * never sent has no reference and is not swept; it waits for somebody to
     * send it.
     *
     * @return array<int, RefundRequest>
     */
    public function pending(): array
    {
        /** @var array<int, RefundRequest> $pending */
        $pending = RefundRequest::query()
            ->where('status', RefundStatus::Approved)
            ->whereNotNull('gateway_refund_reference')
            ->with('payment')
            ->orderBy('id')
            ->get()
            ->all();

        return $pending;
    }

    /**
     * Check one refund, and apply the answer if there is one.
     *
     * Returns the refund as it now stands, whether or not anything changed.
     */
    public function handle(RefundRequest $refund): RefundRequest
    {
        if ($refund->status !== RefundStatus::Approved) {
            return $refund;
        }

        $reference = $refund->gateway_refund_reference;

        if ($reference === null || $reference === '') {
            // Never sent. Nothing to ask about.
            return $refund;
        }

        $gateway = (string) $refund->gateway;

        if ($gateway === '') {
            return $refund;
        }

        $driver = $this->gateways->driver($gateway);

        if (! $driver->supports(GatewayCapability::RefundStatus)) {
            /*
             * The provider tells you once and never again. There is nothing to
             * sweep, and a refund here waits for a person rather than being
             * guessed into completion.
             */
            return $refund;
        }

        try {
            $result = $driver->refundStatus($reference);
        } catch (GatewayUnavailable $exception) {
            $this->log->channel('payment')->warning('Could not check a refund', [
                'refund' => $refund->public_id,
                'gateway' => $gateway,
                'error' => $exception->getMessage(),
            ]);

            return $refund;
        }

        // Still in flight. Nothing to record beyond what is already there.
        if ($result->isPending()) {
            return $refund;
        }

        /** @var Payment $payment */
        $payment = $refund->payment()->firstOrFail();

        return $this->refunds->apply($payment, $refund, $this->withReference($result, $reference));
    }

    /**
     * Keep the reference we asked with when the provider does not echo it.
     */
    protected function withReference(RefundResult $result, string $reference): RefundResult
    {
        if ($result->gatewayRefundReference !== null) {
            return $result;
        }

        return $result->isSucceeded()
            ? RefundResult::succeeded($reference, $result->amount, $result->raw)
            : RefundResult::failed(
                error: $result->error ?? 'The refund did not complete.',
                errorCode: $result->errorCode,
                raw: $result->raw,
                gatewayRefundReference: $reference,
            );
    }
}
