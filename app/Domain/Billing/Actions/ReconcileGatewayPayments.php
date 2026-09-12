<?php

namespace App\Domain\Billing\Actions;

use App\Domain\Billing\Enums\PaymentStatus;
use App\Domain\Billing\Models\Payment;
use App\Domain\Billing\Models\PaymentLog;
use App\Domain\Billing\PaymentLogRedactor;
use App\Integrations\Payment\Data\GatewayCapability;
use App\Integrations\Payment\Data\GatewayResult;
use App\Integrations\Payment\Exceptions\GatewayUnavailable;
use App\Integrations\Payment\PaymentGatewayManager;
use Illuminate\Log\LogManager;
use Throwable;

/**
 * Ask the providers what they think happened (§28.1).
 *
 * Notifications go missing. A payer closes the tab, an IPN is posted at a server
 * that was restarting, a webhook endpoint is misconfigured for an afternoon —
 * and a payment sits open while the money is sitting at the provider. This is
 * the pass that finds those.
 *
 * Five rules, and every one of them is about **not** making things worse:
 *
 *   1. **Only providers that can be asked.** A status lookup is a capability
 *      some providers do not have; bKash publishes none, and calling something
 *      else instead would be inventing a protocol. Those payments are skipped
 *      rather than guessed at.
 *   2. **Never downgrade a settled payment.** A stale provider answer must not
 *      un-pay something. Settled payments are checked for *mismatches* and
 *      nothing else — a disagreement is reported, never applied.
 *   3. **An outage changes nothing.** A provider that cannot be reached leaves
 *      every payment exactly as it was. "We could not ask" is not an answer.
 *   4. **Settling goes the normal way.** A payment the provider says is paid is
 *      handed to {@see SettlePayment}, which verifies it again server-to-server
 *      under its lock. The sweep is a trigger, never a shortcut.
 *   5. **Safe to run beside itself.** Every write is idempotent, the sweep takes
 *      no locks of its own, and a webhook arriving mid-sweep settles the payment
 *      first — after which this finds it settled and moves on.
 */
class ReconcileGatewayPayments
{
    /**
     * How long a payment is left alone before it is worth asking about.
     *
     * Long enough that an ordinary checkout has finished one way or the other,
     * short enough that money sitting at a provider is found the same day.
     */
    public const SETTLE_AFTER_MINUTES = 30;

    /** How many payments one pass will look at. */
    public const BATCH = 200;

    public function __construct(
        protected PaymentGatewayManager $gateways,
        protected SettlePayment $settle,
        protected RecordPaymentLog $logs,
        protected PaymentLogRedactor $redactor,
        protected LogManager $log,
    ) {}

    /**
     * One pass.
     *
     * @return array{checked: int, settled: int, mismatched: int, unreachable: int}
     */
    public function handle(): array
    {
        $summary = ['checked' => 0, 'settled' => 0, 'mismatched' => 0, 'unreachable' => 0];

        foreach ($this->due() as $payment) {
            try {
                $outcome = $this->check($payment);
            } catch (Throwable $throwable) {
                /*
                 * One payment's failure never stops the sweep. A single
                 * unreachable provider or a malformed row must not leave every
                 * payment after it unchecked.
                 */
                $this->log->channel('payment')->error('Reconciliation failed for a payment', [
                    'payment' => $payment->reference,
                    'gateway' => $payment->gateway,
                    'error' => $throwable->getMessage(),
                ]);

                $outcome = 'unreachable';
            }

            $summary['checked']++;

            if (array_key_exists($outcome, $summary)) {
                $summary[$outcome]++;
            }
        }

        return $summary;
    }

    /**
     * The payments worth asking about.
     *
     * Open ones old enough to have finished, and settled ones that have never
     * been confirmed by a sweep — the second group is how a mismatch is found
     * at all, since nothing else ever re-reads a payment the provider already
     * confirmed.
     *
     * @return iterable<int, Payment>
     */
    public function due(): iterable
    {
        return Payment::query()
            ->whereNotNull('gateway')
            ->whereIn('status', [
                PaymentStatus::Initiated,
                PaymentStatus::Pending,
                PaymentStatus::Paid,
            ])
            ->where(fn ($query) => $query
                ->whereIn('status', [PaymentStatus::Initiated, PaymentStatus::Pending])
                ->where('created_at', '<=', now()->subMinutes(self::SETTLE_AFTER_MINUTES))
                ->orWhere(fn ($settled) => $settled
                    ->where('status', PaymentStatus::Paid)
                    ->whereNull('reconciliation_matched_at')))

            // Oldest-checked first, so nothing is starved by a busy day.
            ->orderByRaw('reconciliation_checked_at NULLS FIRST')
            ->orderBy('id')
            ->limit(self::BATCH)
            ->get()
            ->all();
    }

    /**
     * Ask about one payment.
     *
     * @return string one of `settled`, `mismatched`, `unreachable`, `unchanged`
     */
    public function check(Payment $payment): string
    {
        $gateway = (string) $payment->gateway;

        if ($gateway === '' || ! $this->gateways->isImplemented($gateway)) {
            return 'unchanged';
        }

        $driver = $this->gateways->driver($gateway);

        /*
         * Only providers that publish a status lookup. bKash has none — its
         * execute call is both the completion and the answer, and a payment id
         * executes once — so calling something else instead would be inventing
         * a protocol for somebody else's system.
         */
        if (! $driver->supports(GatewayCapability::StatusQuery)) {
            return 'unchanged';
        }

        try {
            $result = $driver->status($payment->reference);
        } catch (GatewayUnavailable $exception) {
            /*
             * Could not ask. Nothing is recorded against the payment and
             * nothing about it changes — not even the checked timestamp, so the
             * next pass tries again rather than treating an outage as a look.
             */
            $this->log->channel('payment')->warning('Could not reconcile a payment', [
                'payment' => $payment->reference,
                'gateway' => $gateway,
                'error' => $exception->getMessage(),
            ]);

            return 'unreachable';
        }

        $this->record($payment, $result);

        return $payment->status->isSettled()
            ? $this->checkSettled($payment, $result)
            : $this->checkOpen($payment, $result);
    }

    /**
     * A payment we already believe is paid.
     *
     * **Nothing here changes it.** A provider disagreeing with a settled payment
     * is a discrepancy for a person, and a stale answer that un-paid something
     * would be far worse than one that was never acted on.
     */
    protected function checkSettled(Payment $payment, GatewayResult $result): string
    {
        if ($result->isPaid() && $result->matchesAmount($payment->amount_minor)) {
            // Agreed. Recorded so the sweep stops asking about this one.
            $payment->forceFill([
                'reconciliation_checked_at' => now(),
                'reconciliation_matched_at' => now(),
            ])->save();

            return 'unchanged';
        }

        $this->log->channel('payment')->critical('A settled payment does not match its provider', [
            'payment' => $payment->reference,
            'gateway' => $payment->gateway,
            'our_status' => $payment->status->value,
            'their_status' => $result->outcome->value,
            'our_amount_minor' => $payment->amount_minor->minorUnits,
            'their_amount_minor' => $result->amount?->minorUnits,
            'their_currency' => $result->amount?->currency->value,
        ]);

        $payment->forceFill([
            'reconciliation_checked_at' => now(),

            /*
             * Deliberately not `matched_at`. The mismatch stays visible to the
             * next sweep and to anybody querying for payments the provider has
             * never agreed with — and the status is untouched, because §28.1
             * asks for an alert, not a correction.
             */
        ])->save();

        return 'mismatched';
    }

    /**
     * A payment still open at our end.
     */
    protected function checkOpen(Payment $payment, GatewayResult $result): string
    {
        $payment->forceFill(['reconciliation_checked_at' => now()])->save();

        if (! $result->isPaid()) {
            /*
             * The provider agrees it is not paid. Nothing to do — closing it is
             * the payment deadline's job (§9), which knows about grace periods
             * and coupon holds that this sweep has no business in.
             */
            return 'unchanged';
        }

        $reference = $result->gatewayReference;

        if ($reference === null || $reference === '') {
            $this->log->channel('payment')->critical('Provider reports a payment paid with no transaction to verify', [
                'payment' => $payment->reference,
                'gateway' => $payment->gateway,
            ]);

            return 'mismatched';
        }

        /*
         * Handed to the normal settlement path, which verifies it again
         * server-to-server under its lock, checks the amount and the identity,
         * and refuses if another payment already holds that transaction. The
         * sweep is a trigger, never a shortcut.
         */
        $settled = $this->settle->handle($payment, $reference);

        if ($settled->isPaid()) {
            $payment->forceFill(['reconciliation_matched_at' => now()])->save();

            return 'settled';
        }

        return 'unchanged';
    }

    /**
     * Keep what the provider said, redacted (§28.1, §42).
     */
    protected function record(Payment $payment, GatewayResult $result): void
    {
        $this->logs->handle(
            gateway: (string) $payment->gateway,
            direction: PaymentLog::OUTBOUND,
            event: 'reconcile',
            payment: $payment,
            gatewayReference: $result->gatewayReference,
            amount: $result->amount,
            outcome: $result->outcome->value,
            context: $result->raw,
        );
    }
}
