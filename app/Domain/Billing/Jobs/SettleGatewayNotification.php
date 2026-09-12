<?php

namespace App\Domain\Billing\Jobs;

use App\Domain\Billing\Actions\RecordPaymentLog;
use App\Domain\Billing\Actions\SettlePayment;
use App\Domain\Billing\Models\Payment;
use App\Domain\Billing\Models\PaymentLog;
use App\Integrations\Payment\Exceptions\GatewayUnavailable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Log\LogManager;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Settle one payment a gateway has told us about (§26.4).
 *
 * The slow half of a notification. A webhook endpoint has to answer quickly —
 * providers time out and then retry, and a slow acknowledgement turns one
 * notification into several — so the endpoint verifies the signature, writes the
 * message down, and hands the actual work here.
 *
 * **Retries are ours, not the provider's.** Asking a gateway to resend by
 * answering 503 makes settlement depend on somebody else's retry policy, which
 * differs per provider and ends after a few days. A job retries on a schedule we
 * chose, and says so in the payment log when it finally gives up.
 *
 * Safe to run twice, in either order, at the same time as itself:
 *
 *   - {@see SettlePayment} holds a distributed lock and re-reads the payment
 *     inside it, so two notifications for the same payment cannot both settle.
 *   - It returns early when the payment is already settled, so a duplicate
 *     notification costs one status check.
 *   - {@see WithoutOverlapping} keeps two jobs for the same payment off the
 *     workers at once rather than having the second wait on the lock.
 *
 * Nothing here decides anything about the payment. The notification said which
 * payment to look at; the gateway is asked directly what happened to it.
 */
class SettleGatewayNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Five attempts, spread over an hour and a half.
     *
     * A gateway's verification endpoint having a bad afternoon is the case this
     * exists for, and it is usually over long before the last attempt. What
     * matters more is that the attempts are spaced: a payment that cannot be
     * verified is not helped by asking again immediately.
     */
    public int $tries = 5;

    /** @var array<int, int> */
    public $backoff = [30, 120, 600, 1800];

    public function __construct(
        public readonly int $paymentId,
        public readonly string $gatewayReference,
        /** What told us to look: an ipn, a browser return, a reconciliation sweep. */
        public readonly string $source = 'ipn',
    ) {
        $this->onQueue((string) config('payment.queue', 'payments'));
    }

    /**
     * Only one job per payment on the workers at a time.
     *
     * `dontRelease()` is deliberate: an overlapping job is a **duplicate**
     * notification, and the one already running will settle the payment. Putting
     * it back on the queue would have it wake up, find the payment settled, and
     * do nothing — later, and having occupied a worker twice.
     *
     * The key is this job's own, deliberately **not** the one
     * {@see SettlePayment} holds. They guard different things — this keeps
     * duplicate notifications off the workers, that one serialises the
     * settlement itself — and sharing a name would have the job take the lock
     * its own work then waits for.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('payment:notification:'.$this->paymentId))
                ->dontRelease()
                ->expireAfter(120),
        ];
    }

    public function handle(SettlePayment $settle, RecordPaymentLog $logs, LogManager $log): void
    {
        $payment = Payment::query()->find($this->paymentId);

        if ($payment === null) {
            return;
        }

        // Already done. A gateway will send the same notification several times,
        // and this is the cheapest possible answer to the second one.
        if ($payment->status->isSettled()) {
            return;
        }

        try {
            $settle->handle($payment, $this->gatewayReference);
        } catch (GatewayUnavailable $exception) {
            /*
             * Could not ask, so nothing is concluded — least of all that the
             * payment failed. Thrown on so the queue retries; the payment is
             * left exactly as it was.
             */
            $log->channel('payment')->warning('Could not verify a notified payment', [
                'payment' => $payment->reference,
                'gateway' => $payment->gateway,
                'attempt' => $this->attempts(),
                'error' => $exception->getMessage(),
            ]);

            $this->recordExhaustion($payment, $logs, $exception->getMessage());

            throw $exception;
        }
    }

    /**
     * The queue has given up on a payment somebody may have paid.
     *
     * Written to the payment log rather than only to a failed-jobs table,
     * because "a gateway told us about this and we never managed to check"
     * is exactly what a reconciliation sweep and a human both need to find.
     */
    public function failed(?Throwable $exception): void
    {
        $payment = Payment::query()->find($this->paymentId);

        if ($payment === null) {
            return;
        }

        app(RecordPaymentLog::class)->handle(
            gateway: (string) $payment->gateway,
            direction: PaymentLog::OUTBOUND,
            event: 'verify',
            payment: $payment,
            gatewayReference: $this->gatewayReference,
            outcome: 'verification_abandoned',
            context: ['source' => $this->source, 'error' => $exception?->getMessage()],
        );

        app(LogManager::class)->channel('payment')->critical('Gave up verifying a notified payment', [
            'payment' => $payment->reference,
            'gateway' => $payment->gateway,
            'gateway_reference' => $this->gatewayReference,
        ]);
    }

    /**
     * Record the give-up on the attempt that will not be retried.
     *
     * `failed()` covers the queue's own accounting, but a synchronous
     * connection — tests, and a small deployment running `sync` — never calls
     * it.
     */
    protected function recordExhaustion(Payment $payment, RecordPaymentLog $logs, string $error): void
    {
        if ($this->attempts() < $this->tries) {
            return;
        }

        $logs->handle(
            gateway: (string) $payment->gateway,
            direction: PaymentLog::OUTBOUND,
            event: 'verify',
            payment: $payment,
            gatewayReference: $this->gatewayReference,
            outcome: 'verification_abandoned',
            context: ['source' => $this->source, 'error' => $error],
        );
    }
}
