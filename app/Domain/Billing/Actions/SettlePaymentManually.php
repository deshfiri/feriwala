<?php

namespace App\Domain\Billing\Actions;

use App\Domain\Access\Data\SensitiveActionRequest;
use App\Domain\Access\Enums\PermissionAction;
use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Access\Exceptions\SensitiveActionRefused;
use App\Domain\Access\SensitiveActionGuard;
use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Billing\Data\ManualSettlement;
use App\Domain\Billing\Exceptions\ManualSettlementRefused;
use App\Domain\Billing\Models\Payment;
use App\Domain\Billing\Models\PaymentLog;
use App\Integrations\Payment\Data\GatewayCapability;
use App\Integrations\Payment\Data\GatewayResult;
use App\Integrations\Payment\PaymentGatewayManager;
use App\Models\User;
use App\Support\Concurrency\DistributedLock;
use Illuminate\Database\DatabaseManager;
use Throwable;

/**
 * Mark a payment paid by hand when the gateway's confirmation never landed.
 *
 * For the payment that was paid at the provider and still reads failed, expired
 * or stuck here. It is the one route to `Paid` that does not start from the
 * gateway's own callback, so it is narrow on purpose:
 *
 *   - **Permission, password, two-factor and a reason** — `payment.settle_manually`
 *     is sensitive, enforced here and not only in the controller.
 *   - **Verified first.** The gateway is asked about the transaction the person
 *     names. A confirmed, matching answer settles exactly like an IPN would.
 *   - **Override only when the gateway cannot say.** Unreachable, unsupported, or
 *     answering "not paid" may be overridden with an explicit flag, and the
 *     record says so. A gateway that contradicts the claim — the transaction is
 *     another payment's, or for another amount — is never overridable.
 *   - **One transaction, one payment.** The reference must not belong to another
 *     payment, whatever the override says.
 *   - **Locked and idempotent.** The same lock {@see SettlePayment} holds, a row
 *     lock inside it, and a second attempt on a settled payment changes nothing.
 *   - **Everything the money unlocks** runs through {@see SettlePayment::applyPurpose()},
 *     so a wallet deposit credits the ledger, an activation proceeds and an order
 *     is confirmed (or held, if its stock is already gone) exactly as for a
 *     payment the gateway confirmed.
 *   - **Audited and logged.** An audit row with the reason, and a payment-log row.
 */
class SettlePaymentManually
{
    public function __construct(
        protected SettlePayment $settle,
        protected PaymentGatewayManager $gateways,
        protected SensitiveActionGuard $guard,
        protected RecordAuditLog $audit,
        protected RecordPaymentLog $logs,
        protected DatabaseManager $database,
        protected DistributedLock $lock,
    ) {}

    /**
     * @throws ManualSettlementRefused
     * @throws SensitiveActionRefused
     */
    public function handle(
        Payment $payment,
        User $actor,
        string $gatewayReference,
        string $reason,
        bool $override,
        bool $passwordConfirmed,
        bool $twoFactorEnabled,
    ): ManualSettlement {
        $this->guard->authorize($actor, new SensitiveActionRequest(
            module: PermissionModule::Payment,
            action: PermissionAction::SettleManually,
            reason: $reason,
            passwordConfirmed: $passwordConfirmed,
            twoFactorEnabled: $twoFactorEnabled,
        ));

        // The lock SettlePayment holds, so a late IPN and this cannot both be
        // mid-settlement on the same payment.
        return $this->lock->run(
            key: 'payment:settle:'.$payment->id,
            callback: fn () => $this->settleLocked($payment, $actor, trim($gatewayReference), trim($reason), $override),
            ttlSeconds: 30,
            waitSeconds: 10,
        );
    }

    protected function settleLocked(
        Payment $payment,
        User $actor,
        string $gatewayReference,
        string $reason,
        bool $override,
    ): ManualSettlement {
        $payment->refresh();

        if ($payment->status->isSettled()) {
            return new ManualSettlement($payment, false, ManualSettlement::ALREADY_SETTLED);
        }

        if (! $payment->canBeSettledManually()) {
            throw ManualSettlementRefused::notSettleable($payment->status->label());
        }

        $claimed = Payment::query()
            ->where('gateway_reference', $gatewayReference)
            ->whereKeyNot($payment->id)
            ->exists();

        if ($claimed) {
            throw ManualSettlementRefused::referenceTaken();
        }

        $confirmed = $this->confirm($payment, $gatewayReference, $override);

        $basis = $confirmed === null ? ManualSettlement::MANUAL_OVERRIDE : ManualSettlement::GATEWAY_VERIFIED;

        $locked = $this->database->transaction(function () use ($payment, $actor, $gatewayReference, $reason, $confirmed, $basis) {
            /** @var Payment $locked */
            $locked = Payment::query()->lockForUpdate()->findOrFail($payment->id);

            if ($locked->status->isSettled()) {
                return null;
            }

            $before = [
                'status' => $locked->status->value,
                'gateway_reference' => $locked->gateway_reference,
            ];

            $locked->transitionToPaidManually();

            $note = sprintf(
                'Marked paid manually by %s on %s (%s): %s',
                $actor->name,
                now()->toDateTimeString(),
                $basis === ManualSettlement::GATEWAY_VERIFIED ? 'gateway confirmed' : 'staff override, gateway did not confirm',
                $reason,
            );

            $locked->forceFill([
                'gateway_reference' => $gatewayReference,
                'completed_at' => now(),
                'settled_amount' => $confirmed?->amount ?? $locked->amount,
                'gateway_settlement_reference' => $confirmed?->settlementReference,
                'gateway_fee' => $confirmed?->fee,
                'reconciliation_reason' => trim(($locked->reconciliation_reason ?? '')."\n".$note),
            ])->save();

            $this->audit->handle(new AuditEntry(
                action: 'billing.payment_settled_manually',
                actorId: $actor->id,
                auditableType: Payment::class,
                auditableId: $locked->id,
                before: $before,
                after: [
                    'status' => $locked->status->value,
                    'gateway_reference' => $gatewayReference,
                    'basis' => $basis,
                    'amount' => $locked->amount->jsonSerialize(),
                ],
                reason: $reason,
                accountId: $locked->business_account_id,
                module: 'billing',
                isSensitive: true,
            ));

            return $locked;
        });

        if ($locked === null) {
            return new ManualSettlement($payment->refresh(), false, ManualSettlement::ALREADY_SETTLED);
        }

        $this->logs->handle(
            gateway: (string) ($locked->gateway ?? 'manual'),
            direction: PaymentLog::OUTBOUND,
            event: 'manual_settle',
            payment: $locked,
            gatewayReference: $gatewayReference,
            amount: $locked->amount,
            outcome: $basis,
            context: ['actor' => $actor->public_id ?? $actor->id, 'reason' => $reason],
        );

        $this->settle->applyPurpose($locked);

        return new ManualSettlement($locked, true, $basis);
    }

    /**
     * Ask the gateway, and decide whether this may go ahead.
     *
     * @return GatewayResult|null the gateway's confirmation, or null when staff
     *                            are overriding because it could not confirm
     *
     * @throws ManualSettlementRefused
     */
    protected function confirm(Payment $payment, string $gatewayReference, bool $override): ?GatewayResult
    {
        $result = null;
        $why = 'The gateway did not confirm this transaction as paid.';

        try {
            $driver = $this->gateways->driver((string) $payment->gateway);

            if (! $driver->supports(GatewayCapability::Verify)) {
                $why = 'This gateway cannot be asked to confirm a transaction.';
            } else {
                $result = $driver->verify($gatewayReference);
            }
        } catch (Throwable $throwable) {
            $why = 'The gateway could not be reached to confirm it: '.$throwable->getMessage();
        }

        if ($result !== null) {
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

            if ($result->isPaid()) {
                if (! $this->settle->belongsToPayment($payment, $result, $gatewayReference)) {
                    throw ManualSettlementRefused::contradicted('That gateway transaction does not belong to this payment.');
                }

                if (! $result->matchesAmount($payment->amount)) {
                    throw ManualSettlementRefused::contradicted('The amount the gateway confirmed does not match this payment.');
                }

                return $result;
            }

            $why = $result->error ?? $why;
        }

        if (! $override) {
            throw ManualSettlementRefused::unverified($why);
        }

        return null;
    }
}
