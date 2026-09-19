<?php

namespace App\Domain\Billing\Actions;

use App\Domain\Account\Actions\EvaluateActivationReadiness;
use App\Domain\Billing\Enums\PaymentPurpose;
use App\Domain\Billing\Enums\PaymentStatus;
use App\Domain\Billing\Models\Payment;
use App\Domain\Billing\Models\PaymentLog;
use App\Domain\Order\Actions\CancelUnpaidOrder;
use App\Domain\Order\Actions\ConfirmOrderPayment;
use App\Domain\Order\Enums\UnpaidOrderCancellation;
use App\Domain\Package\Actions\ActivatePackageChange;
use App\Domain\Package\Actions\ActivateRenewal;
use App\Domain\Package\Models\UserPackage;
use App\Domain\Wallet\Actions\CreditSettledPayment;
use App\Integrations\Payment\Data\GatewayResult;
use App\Integrations\Payment\PaymentGatewayManager;
use App\Notifications\Billing\PaymentReceived;
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
        protected SettleCouponRedemption $couponRedemptions,
        protected CreditSettledPayment $walletCredits,
        protected ConfirmOrderPayment $orders,
        protected CancelUnpaidOrder $unpaidOrders,
        protected RecordPaymentLog $logs,
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

        /*
         * An order's payment window is also its stock's (§19.1, P4-10) —
         * wholesale or website alike.
         *
         * Past it, the order is cancelled and its stock given back before the
         * gateway is asked anything — so a success that arrives late finds a
         * closed payment and goes to reconciliation below, rather than paying
         * for stock that is no longer held.
         */
        if ($this->closeOverdueOrder($payment)) {
            $payment->refresh();
        }

        /*
         * A payment we have already given up on still gets asked (§26.4).
         *
         * A checkout expires or fails, and the gateway confirms the money
         * afterwards — the classic late callback. Returning "already cancelled"
         * without asking would discard a confirmed payment, so the verification
         * runs either way and a genuine success lands in
         * `ReconciliationRequired` instead of activating anything.
         */
        $wasClosed = $payment->status->isTerminal();

        if ($wasClosed && ! $payment->canTransitionTo(PaymentStatus::ReconciliationRequired)) {
            return GatewayResult::failed(
                $payment->reference,
                'This payment is already '.$payment->status->label().'.',
            );
        }

        $gateway = $this->gateways->driver((string) $payment->gateway);

        // Authoritative. Throws rather than guessing if the gateway cannot be
        // reached, leaving the payment untouched for a later retry.
        $result = $gateway->verify($gatewayReference);

        /*
         * What the gateway actually said, kept (§42). The authoritative answer
         * is the one worth having on the record — the redirect and the webhook
         * are only claims, and this is what we acted on.
         */
        $this->logs->handle(
            gateway: (string) $payment->gateway,
            direction: PaymentLog::OUTBOUND,
            event: 'verify',
            payment: $payment,
            gatewayReference: $gatewayReference,
            amount: $result->amount,
            outcome: $result->outcome->value,
            context: $result->raw,
        );

        if (! $result->isPaid()) {
            // A closed payment stays closed. Re-marking it failed would move
            // its timestamps every time a stray callback arrived.
            if (! $wasClosed) {
                $this->recordUnsuccessful($payment, $result);
            }

            return $result;
        }

        /*
         * Whose transaction is this? The callback said which payment to look
         * at; the gateway says which payment it actually belongs to. A signed
         * notification naming one payment while carrying another's transaction
         * would otherwise settle the wrong order with real money.
         */
        if (! $this->belongsToPayment($payment, $result, $gatewayReference)) {
            return GatewayResult::failed(
                $payment->reference,
                'That gateway transaction does not belong to this payment.',
                'reference_mismatch',
            );
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

            // A closed payment is already closed; a live one is not payment for
            // this order and must not stay open pretending to be.
            if (! $wasClosed) {
                $this->markFailed($payment, 'Gateway reported a different amount than was requested.');
            }

            return GatewayResult::failed(
                $payment->reference,
                'The amount confirmed by the gateway does not match this payment.',
            );
        }

        /*
         * Verified, matching, and for a purchase that is already over.
         *
         * The money is real and the purchase is not being revived: nothing is
         * activated, the expired quote and its invoice are left exactly as they
         * are, and the payment is handed to an administrator to reconcile or
         * refund. Recorded rather than thrown, because an exception here would
         * leave confirmed money with no durable record anywhere.
         */
        if ($wasClosed) {
            $this->flagForReconciliation($payment, $result, $gatewayReference);

            return $result;
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

                // Kept now because a refund is addressed to it, and going back
                // to ask for it later is an extra call that can fail at exactly
                // the moment somebody is owed their money (§26.3).
                'gateway_settlement_reference' => $result->settlementReference,

                /*
                 * What the provider kept, recorded separately and never netted
                 * off (D4). A figure that mixed the charge and the fee could
                 * not answer either question: what the customer paid, or what
                 * the business received.
                 */
                'gateway_fee_minor' => $result->fee?->minorUnits,
                'gateway_fee_currency_code' => $result->fee?->currency->value,
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
     * Whether this gateway transaction really is this payment's.
     *
     * Two questions, and both matter. The gateway names the transaction it
     * verified — if that is a different payment's reference, a signed
     * notification has been pointed at the wrong order. And no other payment may
     * already hold this provider transaction: one payment of real money settling
     * two orders is the failure the unique index exists to make impossible, and
     * this is the same check with a readable answer instead of a 500.
     */
    protected function belongsToPayment(
        Payment $payment,
        GatewayResult $result,
        string $gatewayReference,
    ): bool {
        if ($result->reference !== null
            && $result->reference !== ''
            && $result->reference !== $payment->reference) {
            $this->log->channel('payment')->critical('Gateway transaction names a different payment', [
                'payment' => $payment->reference,
                'reported_reference' => $result->reference,
                'gateway' => $payment->gateway,
            ]);

            return false;
        }

        $claimed = Payment::query()
            ->where('gateway_reference', $gatewayReference)
            ->whereKeyNot($payment->id)
            ->exists();

        if ($claimed) {
            $this->log->channel('payment')->critical('Gateway transaction already settled another payment', [
                'payment' => $payment->reference,
                'gateway_reference' => $gatewayReference,
                'gateway' => $payment->gateway,
            ]);

            return false;
        }

        /*
         * And the same question about the settlement side.
         *
         * Several providers carry two identifiers — the one a verification is
         * addressed to and the one the banking side settled under — and it is
         * the second that identifies the money. SSLCommerz can issue a fresh
         * `val_id` for the same `bank_tran_id`, so checking only the first would
         * let one real transaction pay for two orders while every reference
         * looked distinct.
         */
        $settlement = $result->settlementReference;

        if ($settlement !== null && $settlement !== '') {
            $settled = Payment::query()
                ->where('gateway_settlement_reference', $settlement)
                ->whereKeyNot($payment->id)
                ->exists();

            if ($settled) {
                $this->log->channel('payment')->critical('Provider settlement already belongs to another payment', [
                    'payment' => $payment->reference,
                    'settlement_reference' => $settlement,
                    'gateway' => $payment->gateway,
                ]);

                return false;
            }
        }

        return true;
    }

    /**
     * Record confirmed money against a purchase that had already ended.
     *
     * Idempotent under a row lock: the transition only runs from a state that
     * allows it, so a gateway retrying the same notification finds the payment
     * already flagged and writes nothing further.
     *
     * Nothing here reads as settled, so nothing downstream activates: the
     * account stays where it was, the subscription stays where it was, and the
     * invoice still answers "not paid" because it asks the payment's status.
     * What changes is that the payment now says, durably, that money arrived
     * and somebody has to deal with it.
     */
    protected function flagForReconciliation(
        Payment $payment,
        GatewayResult $result,
        string $gatewayReference,
    ): void {
        $this->database->transaction(function () use ($payment, $result, $gatewayReference) {
            $locked = Payment::query()->lockForUpdate()->find($payment->id);

            if ($locked === null || ! $locked->canTransitionTo(PaymentStatus::ReconciliationRequired)) {
                return;
            }

            $closed = $locked->status;

            $locked->transitionTo(PaymentStatus::ReconciliationRequired);

            $locked->forceFill([
                'gateway_reference' => $gatewayReference,
                'settled_currency_code' => $result->amount?->currency->value,
                'settled_amount_minor' => $result->amount?->minorUnits,
                'gateway_settlement_reference' => $result->settlementReference,
                'reconciliation_required_at' => now(),
                'reconciliation_reason' => sprintf(
                    'The gateway confirmed this payment after the checkout was %s. '
                    .'The money is real; the purchase was not reopened.',
                    mb_strtolower($closed->label()),
                ),
            ])->save();

            $payment->setRawAttributes($locked->getAttributes(), sync: true);
        });

        $this->log->channel('payment')->critical('Payment confirmed after its checkout closed', [
            'payment' => $payment->reference,
            'gateway' => $payment->gateway,
            'gateway_reference' => $gatewayReference,
            'amount_minor' => $result->amount?->minorUnits,
            'currency' => $result->amount?->currency->value,
        ]);
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
        /*
         * A coupon held against this payment becomes a use, whatever the
         * payment was for (§9). Before the purpose switch, because the
         * redemption is a fact about the money arriving rather than about what
         * it bought — and because it must happen even if what it bought fails
         * to activate for some other reason.
         */
        $this->couponRedemptions->redeem($payment);

        /*
         * Tell them the money arrived (D20, §30).
         *
         * Never allowed to fail the settlement. The payment happened; an SMS
         * provider having a bad afternoon must not roll it back, and the
         * notification's own queue will retry without our help.
         */
        $this->announce($payment);

        /*
         * Every purpose §26.3 names, answered explicitly (P2-33).
         *
         * A total match rather than one with a `default`, and deliberately: a
         * purpose added later must make the compiler ask what it should do
         * rather than falling silently into "nothing". Money arriving for
         * something nobody wired up is exactly the failure that goes unnoticed
         * for a month.
         */
        match ($payment->purpose) {
            // A settled activation payment can be the last requirement standing
            // between an account and the approval queue (§5.1). Registration
            // and package fees paid together are one activation payment with
            // two allocations, not two payments.
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

            /*
             * The only two purposes that put money **into** a wallet (§23.1).
             *
             * Everything else on this list is money paid *to* Feriwala, and
             * crediting a wallet for it would hand back what was just charged.
             */
            PaymentPurpose::WalletDeposit,
            PaymentPurpose::WalletTopUp => $this->creditWallet($payment),

            // The order this pays for — ERP wholesale (P4-9) or a customer's on
            // a partner website (P5-23): stock committed and paid, or held for
            // review when the stock is not there.
            PaymentPurpose::WholesaleOrder,
            PaymentPurpose::WebsiteOrder => $this->confirmOrder($payment),

            /*
             * Charged to the wallet, never through a gateway (P5-10). Reaching
             * this means something reached the payment table it should not
             * have — worth a person looking at rather than a silent success.
             */
            PaymentPurpose::WebsiteSetup,
            PaymentPurpose::DomainCharge,
            PaymentPurpose::HostingCharge,
            PaymentPurpose::MaintenanceCharge => $this->flagUndeliverable($payment),
        };
    }

    /**
     * Confirmed money for something this platform cannot yet deliver.
     *
     * The money is real and is recorded as such — nothing is rolled back and no
     * status is invented. What happens is that it stops being invisible: a
     * critical log an administrator is watching, and the payment log entry that
     * a reconciliation sweep will find.
     */
    protected function flagUndeliverable(Payment $payment): void
    {
        $this->log->channel('payment')->critical('Settled a payment for a module that is not built', [
            'payment' => $payment->reference,
            'purpose' => $payment->purpose->value,
            'business_account' => $payment->business_account_id,
            'amount_minor' => $payment->amount_minor->minorUnits,
        ]);

        $this->logs->handle(
            gateway: (string) $payment->gateway,
            direction: PaymentLog::OUTBOUND,
            event: 'settle',
            payment: $payment,
            amount: $payment->amount_minor,
            outcome: 'undeliverable_purpose',
            context: ['purpose' => $payment->purpose->value],
        );
    }

    /**
     * Confirm the order a settled payment paid for.
     *
     * Wrapped like every other consequence: the money arrived either way. An
     * order left waiting is picked up by the unpaid-order sweep, which runs the
     * same idempotent confirmation.
     */
    protected function confirmOrder(Payment $payment): void
    {
        try {
            $this->orders->handle($payment);
        } catch (Throwable $throwable) {
            $this->log->channel('payment')->critical('Could not confirm a settled order', [
                'payment' => $payment->reference,
                'business_account' => $payment->business_account_id,
                'amount_minor' => $payment->amount_minor->minorUnits,
                'error' => $throwable->getMessage(),
            ]);

            $this->logs->handle(
                gateway: (string) $payment->gateway,
                direction: PaymentLog::OUTBOUND,
                event: 'settle',
                payment: $payment,
                amount: $payment->amount_minor,
                outcome: 'order_unconfirmed',
                context: ['error' => $throwable->getMessage()],
            );
        }
    }

    /**
     * Cancel an unpaid order whose payment window has closed.
     *
     * Runs inside the settlement lock this class already holds.
     */
    protected function closeOverdueOrder(Payment $payment): bool
    {
        if (! $payment->purpose->paysForOrder()
            || ! in_array($payment->status, PaymentStatus::open(), true)
            || $payment->expires_at === null
            || $payment->expires_at->isFuture()) {
            return false;
        }

        return $this->unpaidOrders->forPaymentWithinSettlement($payment, UnpaidOrderCancellation::PaymentExpired);
    }

    /**
     * Put a settled top-up or deposit into the wallet it was paid into (§23.1).
     *
     * Once, however many times the gateway tells us: the posting's idempotency
     * key comes from the payment's own reference, so a retried IPN finds the
     * credit already made.
     *
     * Wrapped like every other consequence. The money arrived either way, and a
     * settlement rolled back because a downstream posting failed would be worse
     * than a critical log an administrator can act on.
     */
    protected function creditWallet(Payment $payment): void
    {
        try {
            $this->walletCredits->handle($payment);
        } catch (Throwable $throwable) {
            $this->log->channel('wallet')->critical('Could not credit a settled wallet payment', [
                'payment' => $payment->reference,
                'business_account' => $payment->business_account_id,
                'amount_minor' => $payment->amount_minor->minorUnits,
                'error' => $throwable->getMessage(),
            ]);

            /*
             * Written down, not just logged. Confirmed money that reached no
             * wallet is the one failure here that somebody has to act on, and a
             * line in a log file is not something an administrator is going to
             * find. The retry that clears this is idempotent on the payment's
             * own reference, so acting on it cannot credit twice.
             */
            $this->walletCredits->flagUnapplied($payment, $throwable->getMessage());
        }
    }

    /**
     * Tell the account holder their payment landed.
     *
     * D20 lists payment success among the notifications a user cannot turn off:
     * somebody who has just handed over money is entitled to know it arrived.
     * Wrapped, because a notification is never worth a rolled-back settlement.
     */
    protected function announce(Payment $payment): void
    {
        // A website order's money is its customer's, told by their storefront;
        // the shop's owner hears about the order instead (P5-23).
        if (! $payment->purpose->isPaidByAccount()) {
            return;
        }

        try {
            $payment->businessAccount()->with('owner')->first()?->owner?->notify(
                new PaymentReceived($payment->reference, $payment->amount_minor),
            );
        } catch (Throwable $throwable) {
            $this->log->channel('payment')->error('Could not announce a settled payment', [
                'payment' => $payment->reference,
                'error' => $throwable->getMessage(),
            ]);
        }
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

        /*
         * The coupon slot goes back (§9). A failed payment is retried as a new
         * attempt rather than revived, so a hold left on this one would be a
         * slot nobody can ever spend — and on a coupon with a usage limit, one
         * fewer promotion than the administrator granted.
         */
        $this->couponRedemptions->release($payment);

        /*
         * And an order waiting on it is cancelled with its stock given back
         * now, rather than on the sweep's next pass (P4-10). The sweep still
         * catches it if this cannot finish.
         */
        if ($payment->purpose->paysForOrder()) {
            try {
                $this->unpaidOrders->forPaymentWithinSettlement($payment, UnpaidOrderCancellation::PaymentFailed);
            } catch (Throwable $throwable) {
                $this->log->channel('payment')->error('Could not cancel an order after its payment failed', [
                    'payment' => $payment->reference,
                    'error' => $throwable->getMessage(),
                ]);
            }
        }
    }
}
