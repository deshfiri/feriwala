<?php

namespace App\Domain\Billing\Actions;

use App\Domain\Access\Data\SensitiveActionRequest;
use App\Domain\Access\Enums\PermissionAction;
use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Access\SensitiveActionGuard;
use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Billing\Enums\PaymentStatus;
use App\Domain\Billing\Enums\RefundStatus;
use App\Domain\Billing\Exceptions\RefundRefused;
use App\Domain\Billing\Models\Payment;
use App\Domain\Billing\Models\PaymentLog;
use App\Domain\Billing\Models\RefundRequest;
use App\Domain\Billing\PaymentLogRedactor;
use App\Domain\Billing\Queries\RefundableAmount;
use App\Domain\Referral\Actions\ReverseQualifyingEvent;
use App\Integrations\Payment\Data\GatewayCapability;
use App\Integrations\Payment\Data\RefundIntent;
use App\Integrations\Payment\Data\RefundResult;
use App\Integrations\Payment\Exceptions\GatewayUnavailable;
use App\Integrations\Payment\PaymentGatewayManager;
use App\Models\User;
use Illuminate\Database\DatabaseManager;
use Illuminate\Log\LogManager;

/**
 * Send an approved refund back through the provider that took the money
 * (§26.3, §32.2, D17).
 *
 * The half {@see DecideRefund} deliberately left for later: "an approval moves
 * no money on its own". This is where it moves. Approval is a decision; this is
 * execution, and keeping them apart is what lets an approved refund fail at a
 * gateway without the decision looking like it was never taken.
 *
 * **A refund is a new financial operation, not an edit to the payment.** The
 * payment keeps saying what was taken and when. What changes there is its
 * status, and only after a provider has confirmed the money went back.
 *
 * The order of work is what makes this safe under two administrators pressing
 * the button at the same moment:
 *
 *   1. **Claim first.** Inside a transaction the request row is locked, the
 *      payment's remaining refundable amount is computed from the other refund
 *      rows, and an idempotency key is written. Nothing has been sent anywhere
 *      yet, and a second attempt collides on that key.
 *   2. **Then ask the provider.** Outside the transaction, because an HTTP call
 *      inside one holds a row lock for as long as somebody else's server feels
 *      like taking.
 *   3. **Then record what they said.** Only an explicit confirmation reverses
 *      anything. Acceptance without settlement leaves the refund approved and
 *      still claiming its amount, to be swept later.
 */
class ProcessRefund
{
    public function __construct(
        protected PaymentGatewayManager $gateways,
        protected RefundableAmount $refundable,
        protected ApplyRefundReversal $reversal,
        protected SensitiveActionGuard $guard,
        protected RecordPaymentLog $logs,
        protected RecordAuditLog $audit,
        protected PaymentLogRedactor $redactor,
        protected DatabaseManager $database,
        protected LogManager $log,
        protected ReverseQualifyingEvent $referralReversal,
    ) {}

    /**
     * Send an approved refund.
     *
     * @throws RefundRefused when the refund must not be sent at all
     */
    public function handle(
        RefundRequest $request,
        User $actor,
        bool $passwordConfirmed,
        bool $twoFactorEnabled,
    ): RefundRequest {
        /*
         * Permission, identity and intent, before anything is read or written.
         * `payment.reverse_transaction` is already classified as sensitive, so
         * this enforces a confirmed password, two-factor and a reason without
         * this action having to know that — and the reason is the one recorded
         * on the request, which cannot be blank.
         */
        $this->guard->authorize($actor, new SensitiveActionRequest(
            module: PermissionModule::Payment,
            action: PermissionAction::ReverseTransaction,
            reason: $request->reason,
            passwordConfirmed: $passwordConfirmed,
            twoFactorEnabled: $twoFactorEnabled,
        ));

        $prepared = $this->claim($request);

        return $this->submit($prepared);
    }

    /**
     * Lock the request, check everything, and write the idempotency key.
     *
     * @throws RefundRefused
     */
    protected function claim(RefundRequest $request): RefundRequest
    {
        return $this->database->transaction(function () use ($request) {
            /** @var RefundRequest $locked */
            $locked = RefundRequest::query()->lockForUpdate()->findOrFail($request->id);

            /*
             * Approved, or approved and failed at the gateway. Both are
             * decisions that stand — the status machine allows `Failed` back to
             * `Processed` for exactly this reason. Anything else has either not
             * been decided or has already gone back.
             */
            if (! in_array($locked->status, [RefundStatus::Approved, RefundStatus::Failed], true)) {
                throw RefundRefused::notApproved($locked->status->label());
            }

            /** @var Payment $payment */
            $payment = Payment::query()->lockForUpdate()->findOrFail($locked->payment_id);

            // Money that never arrived cannot go back.
            if (! $payment->status->isSettled() && $payment->status !== PaymentStatus::PartiallyRefunded) {
                throw RefundRefused::notSettled($payment->status->label());
            }

            $gateway = (string) $payment->gateway;

            if ($gateway === '') {
                throw RefundRefused::noGateway();
            }

            $driver = $this->gateways->driver($gateway);

            /*
             * What is left, read inside the lock. Approved refunds count, so
             * two decisions totalling more than the payment cannot both be
             * sent — the second one reads what the first one claimed.
             *
             * This request's own claim is excluded, because it is the one
             * asking whether there is room for itself.
             */
            $remaining = $this->refundable->handle($payment, excluding: $locked);

            if ($locked->amount->greaterThan($remaining)) {
                throw RefundRefused::exceedsRemaining($locked->amount, $remaining);
            }

            $capability = $locked->amount->equals($payment->amount)
                ? GatewayCapability::RefundFull
                : GatewayCapability::RefundPartial;

            if (! $driver->supports($capability)) {
                throw RefundRefused::unsupported($gateway, $capability);
            }

            /*
             * A wallet that cannot afford the reversal stops the refund here,
             * before the provider is asked. Giving the money back and then
             * discovering the wallet has spent it would leave a negative
             * available balance nobody authorised — and unlike the provider
             * call, this is a question we can answer for certain first.
             */
            $this->reversal->assertPossible($payment, $locked->amount);

            $locked->forceFill([
                'gateway' => $gateway,

                /*
                 * Decided before the provider is contacted, so the guard exists
                 * whether or not the provider offers one of its own. Derived
                 * from the request's own identity, so a retry sends the same
                 * key and a provider that took the first instruction recognises
                 * it rather than refunding twice.
                 */
                'idempotency_key' => $locked->idempotency_key ?? 'refund:'.$locked->public_id,
                'submitted_at' => now(),
            ])->save();

            return $locked;
        });
    }

    /**
     * Ask the provider, then record what they said.
     */
    protected function submit(RefundRequest $request): RefundRequest
    {
        /** @var Payment $payment */
        $payment = $request->payment()->firstOrFail();

        $driver = $this->gateways->driver((string) $request->gateway);

        $intent = new RefundIntent(
            reference: $payment->reference,

            /*
             * The settlement reference when the provider has one — a refund is
             * addressed to the transaction the banking side settled, not the
             * one a verification was addressed to. Falls back to the gateway
             * reference for providers that carry only one.
             */
            gatewayReference: $this->addressFor($payment),
            amount: $request->amount,
            originalAmount: $payment->amount,
            reason: $request->reason,
            idempotencyKey: (string) $request->idempotency_key,
        );

        try {
            $result = $driver->refund($intent);
        } catch (GatewayUnavailable $exception) {
            /*
             * Could not ask. The request stays approved with its amount
             * claimed, which is the safe side: it is visible, it can be sent
             * again under the same key, and nothing has been reversed on the
             * strength of a call that never completed.
             */
            $this->log->channel('payment')->error('Could not submit a refund', [
                'refund' => $request->public_id,
                'payment' => $payment->reference,
                'gateway' => $request->gateway,
                'error' => $exception->getMessage(),
            ]);

            $this->record($payment, $request, 'unavailable', ['error' => $exception->getMessage()]);

            return $request;
        }

        $this->record($payment, $request, $result->outcome->value, $result->raw);

        return $this->apply($payment, $request, $result);
    }

    /**
     * Move the refund to where the provider's answer puts it.
     */
    public function apply(Payment $payment, RefundRequest $request, RefundResult $result): RefundRequest
    {
        if ($result->isSucceeded()) {
            return $this->complete($payment, $request, $result);
        }

        if ($result->isPending()) {
            /*
             * Accepted, not settled. The request stays approved — its amount
             * still claimed, so nothing else can be refunded against it — and
             * the provider's reference is kept so a sweep can ask later.
             */
            return $this->database->transaction(function () use ($request, $result) {
                /** @var RefundRequest $locked */
                $locked = RefundRequest::query()->lockForUpdate()->findOrFail($request->id);

                $locked->forceFill([
                    'gateway_refund_reference' => $result->gatewayRefundReference,
                    'evidence' => $this->redactor->redact($result->raw),
                ])->save();

                return $locked;
            });
        }

        return $this->database->transaction(function () use ($request, $result) {
            /** @var RefundRequest $locked */
            $locked = RefundRequest::query()->lockForUpdate()->findOrFail($request->id);

            if ($locked->canTransitionTo(RefundStatus::Failed)) {
                $locked->transitionTo(RefundStatus::Failed);
            }

            /*
             * The amount stops being claimed the moment this reads failed,
             * because `RefundableAmount` counts only approved and processed —
             * so the money becomes refundable again without anything having to
             * remember to release it. The decision stands, which is why this
             * can be retried without being approved a second time.
             */
            $locked->forceFill([
                'failure_reason' => $result->error,
                'gateway_refund_reference' => $result->gatewayRefundReference,
                'evidence' => $this->redactor->redact($result->raw),
            ])->save();

            return $locked;
        });
    }

    /**
     * The provider confirmed it. Now, and only now, reverse anything.
     */
    protected function complete(Payment $payment, RefundRequest $request, RefundResult $result): RefundRequest
    {
        $processed = $this->database->transaction(function () use ($request, $result) {
            /** @var RefundRequest $locked */
            $locked = RefundRequest::query()->lockForUpdate()->findOrFail($request->id);

            // Already done. A repeated provider confirmation must not reverse
            // the wallet twice.
            if ($locked->status === RefundStatus::Processed) {
                return $locked;
            }

            if (! $locked->canTransitionTo(RefundStatus::Processed)) {
                return $locked;
            }

            $locked->transitionTo(RefundStatus::Processed);

            $locked->forceFill([
                'processed_at' => now(),
                'gateway_refund_reference' => $result->gatewayRefundReference ?? $locked->gateway_refund_reference,
                'evidence' => $this->redactor->redact($result->raw),
                'failure_reason' => null,
            ])->save();

            return $locked;
        });

        if ($processed->status !== RefundStatus::Processed) {
            return $processed;
        }

        /*
         * The financial consequence, after the refund is durably processed. Its
         * own idempotency key comes from the request's public id, so running
         * this twice posts once.
         */
        $this->reversal->handle($payment, $processed);

        // A refunded qualifying payment pays no referral commission (§25.5, D24).
        $this->referralReversal->forRefund($payment, $processed);

        $this->settleStatus($payment);

        $this->audit->handle(new AuditEntry(
            action: 'billing.refund_processed',
            actorId: $processed->decided_by,
            auditableType: RefundRequest::class,
            auditableId: $processed->id,
            after: [
                'payment' => $payment->reference,
                'amount' => $processed->amount->jsonSerialize(),
                'currency' => $processed->currency_code,
                'gateway' => $processed->gateway,
                'gateway_refund_reference' => $processed->gateway_refund_reference,
            ],
            reason: $processed->reason,
            accountId: $processed->business_account_id,
            module: 'billing',
            isSensitive: true,
        ));

        return $processed;
    }

    /**
     * Move the payment to refunded or partially refunded.
     *
     * The payment's own figures are not rewritten — this changes its status and
     * nothing else, which is what §23.2 allows: the amount taken still says what
     * was taken.
     */
    protected function settleStatus(Payment $payment): void
    {
        $this->database->transaction(function () use ($payment) {
            /** @var Payment|null $locked */
            $locked = Payment::query()->lockForUpdate()->find($payment->id);

            if ($locked === null) {
                return;
            }

            $target = $this->refundable->isFullyRefunded($locked)
                ? PaymentStatus::Refunded
                : PaymentStatus::PartiallyRefunded;

            if ($locked->canTransitionTo($target)) {
                $locked->transitionTo($target)->save();
                $payment->setRawAttributes($locked->getAttributes(), sync: true);
            }
        });
    }

    /**
     * Which of the provider's identifiers a refund is addressed to.
     */
    protected function addressFor(Payment $payment): string
    {
        $settlement = $payment->gateway_settlement_reference;

        if (is_string($settlement) && $settlement !== '') {
            return $settlement;
        }

        return (string) $payment->gateway_reference;
    }

    /**
     * @param  array<array-key, mixed>  $context
     */
    protected function record(Payment $payment, RefundRequest $request, ?string $outcome, array $context): void
    {
        $this->logs->handle(
            gateway: (string) $request->gateway,
            direction: PaymentLog::OUTBOUND,
            event: 'refund',
            payment: $payment,
            reference: $payment->reference,
            gatewayReference: $request->gateway_refund_reference,
            amount: $request->amount,
            outcome: $outcome,
            context: $context,
        );
    }
}
