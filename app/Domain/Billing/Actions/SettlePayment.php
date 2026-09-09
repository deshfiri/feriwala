<?php

namespace App\Domain\Billing\Actions;

use App\Domain\Account\Actions\EvaluateActivationReadiness;
use App\Domain\Billing\Enums\PaymentPurpose;
use App\Domain\Billing\Enums\PaymentStatus;
use App\Domain\Billing\Models\Payment;
use App\Domain\Package\Actions\ActivatePackageChange;
use App\Domain\Package\Actions\ActivateRenewal;
use App\Domain\Package\Models\UserPackage;
use App\Integrations\Payment\Data\GatewayResult;
use App\Integrations\Payment\PaymentGatewayManager;
use App\Support\Concurrency\DistributedLock;
use Illuminate\Database\DatabaseManager;
use Illuminate\Log\LogManager;
use Throwable;

/**
 * Confirms a payment against its gateway and records the outcome (§26.4, §36.1).
 *
 * The single place a payment becomes settled. Every route into it — the browser
 * redirect, the IPN, a scheduled reconciliation sweep, a manual retry — lands
 * here, so the rules below hold no matter how the news arrives.
 *
 *   - **Verified server-to-server, always.** Nothing in a redirect or a webhook
 *     body decides the outcome; the gateway is asked directly.
 *   - **Idempotent.** A gateway will send the same IPN several times. Settling
 *     twice would credit a wallet twice.
 *   - **Amount-checked.** A gateway reporting a different amount is not payment
 *     for this order, whatever it says.
 *   - **Locked.** Concurrent callbacks race; the row lock and the status guard
 *     between them mean only one wins.
 */
class SettlePayment
{
    public function __construct(
        protected PaymentGatewayManager $gateways,
        protected EvaluateActivationReadiness $readiness,
        protected ActivateRenewal $renewals,
        protected ActivatePackageChange $packageChanges,
        protected DatabaseManager $database,
        protected DistributedLock $lock,
        protected LogManager $log,
    ) {}

    /**
     * @param  string  $gatewayReference  the gateway's own transaction id
     */
    public function handle(Payment $payment, string $gatewayReference): GatewayResult
    {
        // Held across the verification too, so two callbacks cannot both be
        // mid-verify when they reach the status check.
        return $this->lock->run(
            key: 'payment:settle:'.$payment->id,
            callback: fn () => $this->settle($payment, $gatewayReference),
            ttlSeconds: 30,
            waitSeconds: 10,
        );
    }

    protected function settle(Payment $payment, string $gatewayReference): GatewayResult
    {
        $payment->refresh();

        // Already done. Returning the settled result rather than re-verifying
        // keeps a repeated IPN cheap and side-effect free.
        if ($payment->status->isSettled()) {
            return GatewayResult::paid(
                reference: $payment->reference,
                gatewayReference: (string) $payment->gateway_reference,
                amount: $payment->amount_minor,
            );
        }

        if ($payment->status->isTerminal()) {
            return GatewayResult::failed(
                $payment->reference,
                'This payment is already '.$payment->status->label().'.',
            );
        }

        $gateway = $this->gateways->driver((string) $payment->gateway);

        // Authoritative. Throws rather than guessing if the gateway cannot be
        // reached, leaving the payment untouched for a later retry.
        $result = $gateway->verify($gatewayReference);

        if (! $result->isPaid()) {
            $this->recordUnsuccessful($payment, $result);

            return $result;
        }

        // The amount check. A gateway reporting a different figure means the
        // request was tampered with or the gateway is misconfigured — either
        // way it does not pay for this order.
        if (! $result->matchesAmount($payment->amount_minor)) {
            $this->log->channel('payment')->critical('Payment amount mismatch', [
                'payment' => $payment->reference,
                'expected_minor' => $payment->amount_minor->minorUnits,
                'reported_minor' => $result->amount?->minorUnits,
                'gateway' => $payment->gateway,
            ]);

            $this->markFailed($payment, 'Gateway reported a different amount than was requested.');

            return GatewayResult::failed(
                $payment->reference,
                'The amount confirmed by the gateway does not match this payment.',
            );
        }

        $this->database->transaction(function () use ($payment, $result, $gatewayReference) {
            // Re-read under a row lock: between the status check above and here,
            // another process could have settled it.
            $locked = Payment::query()->lockForUpdate()->find($payment->id);

            if ($locked === null || $locked->status->isSettled()) {
                return;
            }

            if ($locked->status === PaymentStatus::Draft) {
                $locked->transitionTo(PaymentStatus::Initiated);
            }

            $locked->transitionTo(PaymentStatus::Paid);

            $locked->forceFill([
                'gateway_reference' => $gatewayReference,
                'completed_at' => now(),
                'settled_currency_code' => $result->amount?->currency->value,
                'settled_amount_minor' => $result->amount?->minorUnits,
            ])->save();

            $payment->setRawAttributes($locked->getAttributes(), sync: true);
        });

        /*
         * What the money unlocks, once it has actually moved.
         *
         * Run after the transaction commits and never allowed to fail the
         * settlement: the payment happened either way, and rolling it back
         * because a downstream status could not be recalculated would be far
         * worse than a follow-up that catches up on the next sweep.
         */
        $this->applyPurpose($payment);

        return $result;
    }

    /**
     * The consequence of this particular payment settling.
     *
     * Switched on purpose in one place rather than scattered through the
     * callers, because every route into settlement — redirect, IPN,
     * reconciliation, manual retry — has to produce the same consequence.
     */
    protected function applyPurpose(Payment $payment): void
    {
        match ($payment->purpose) {
            // A settled activation payment can be the last requirement standing
            // between an account and the approval queue (§5.1).
            PaymentPurpose::Activation => $this->refreshActivationReadiness($payment),

            // §8.4's "successful renewal verification": the renewal was created
            // awaiting payment and grants nothing until this runs.
            PaymentPurpose::PackageRenewal => $this->activateRenewal($payment),

            /*
             * §8.3, both directions. A downgrade is settled here too, and for
             * the same reason: a limit taken away by an invoice nobody paid
             * would be a restriction imposed for free.
             */
            PaymentPurpose::PackageUpgrade,
            PaymentPurpose::PackageDowngrade => $this->activatePackageChange($payment),

            default => null,
        };
    }

    protected function activatePackageChange(Payment $payment): void
    {
        $change = $payment->payable;

        if (! $change instanceof UserPackage) {
            return;
        }

        try {
            $this->packageChanges->handle($change);
        } catch (Throwable $throwable) {
            $this->log->channel('payment')->error('Could not activate a package change', [
                'payment' => $payment->reference,
                'subscription' => $change->public_id,
                'error' => $throwable->getMessage(),
            ]);
        }
    }

    protected function activateRenewal(Payment $payment): void
    {
        $renewal = $payment->payable;

        if (! $renewal instanceof UserPackage) {
            return;
        }

        try {
            $this->renewals->handle($renewal);
        } catch (Throwable $throwable) {
            $this->log->channel('payment')->error('Could not activate a renewed subscription', [
                'payment' => $payment->reference,
                'subscription' => $renewal->public_id,
                'error' => $throwable->getMessage(),
            ]);
        }
    }

    protected function refreshActivationReadiness(Payment $payment): void
    {
        $account = $payment->businessAccount()->first();

        if ($account === null) {
            return;
        }

        try {
            $this->readiness->handle($account, 'Activation payment settled.');
        } catch (Throwable $throwable) {
            $this->log->channel('payment')->error('Could not re-evaluate activation readiness', [
                'payment' => $payment->reference,
                'business_account' => $account->id,
                'error' => $throwable->getMessage(),
            ]);
        }
    }

    protected function recordUnsuccessful(Payment $payment, GatewayResult $result): void
    {
        // Pending is not a failure — a bank transfer or a risk review may still
        // settle. Marking it failed would abandon money that is on its way.
        if ($result->outcome->value === 'pending') {
            if ($payment->canTransitionTo(PaymentStatus::Pending)) {
                $payment->transitionTo(PaymentStatus::Pending)->save();
            }

            return;
        }

        $this->markFailed($payment, $result->error ?? 'The gateway did not confirm this payment.');
    }

    protected function markFailed(Payment $payment, string $reason): void
    {
        if (! $payment->canTransitionTo(PaymentStatus::Failed)) {
            return;
        }

        $payment->transitionTo(PaymentStatus::Failed);

        $payment->forceFill([
            'failed_at' => now(),
            'failure_reason' => $reason,
        ])->save();
    }
}
