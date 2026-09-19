<?php

namespace App\Domain\Referral\Actions;

use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Billing\Models\Payment;
use App\Domain\Billing\Models\RefundRequest;
use App\Domain\Referral\Enums\CommissionStatus;
use App\Domain\Referral\Enums\ReversalCause;
use App\Domain\Referral\Models\ReferralCommission;
use App\Domain\Referral\Models\ReferralQualifyingEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Log\LogManager;
use Throwable;

/**
 * Take back everything one qualifying event paid (§25.5, D24, P7-43).
 *
 * The whole chain at once: a refund of the activation payment, a chargeback,
 * fraud or an activation rolled back undoes the event, and every level it
 * paid or would pay goes with it — each through {@see ReverseReferralCommission},
 * so each is a compensating entry, owed where a wallet cannot carry it.
 *
 * **Any refund of the qualifying payment reverses the whole event**, partial
 * refunds included: §25.5 forbids rewards from refunded payments, and paying a
 * chain on a payment that was partly given back is exactly that.
 */
class ReverseQualifyingEvent
{
    public function __construct(
        protected DatabaseManager $database,
        protected ReverseReferralCommission $reverse,
        protected RecordAuditLog $audit,
        protected LogManager $log,
    ) {}

    public function handle(ReferralQualifyingEvent $event, ReversalCause $cause, string $reason, ?User $actor = null): ReferralQualifyingEvent
    {
        $marked = $this->database->transaction(function () use ($event, $reason) {
            /** @var ReferralQualifyingEvent $locked */
            $locked = ReferralQualifyingEvent::query()->lockForUpdate()->findOrFail($event->id);

            if ($locked->status === ReferralQualifyingEvent::REVERSED) {
                return false;
            }

            $locked->forceFill([
                'status' => ReferralQualifyingEvent::REVERSED,
                'reversed_at' => CarbonImmutable::now(),
                'reversal_reason' => $reason,
            ])->save();

            return true;
        });

        ReferralCommission::query()
            ->where('referral_qualifying_event_id', $event->id)
            ->whereIn('status', [CommissionStatus::Pending->value, CommissionStatus::Paid->value])
            ->orderBy('level')
            ->get()
            ->each(fn (ReferralCommission $commission) => $this->reverse->handle($commission, $cause, $reason, $actor));

        if ($marked) {
            $this->audit->handle(new AuditEntry(
                action: 'referral.event_reversed',
                actorId: $actor?->id,
                actorType: $actor === null ? 'system' : 'user',
                auditableType: ReferralQualifyingEvent::class,
                auditableId: $event->id,
                before: ['status' => ReferralQualifyingEvent::RECORDED],
                after: ['status' => ReferralQualifyingEvent::REVERSED, 'cause' => $cause->value],
                reason: $reason,
                accountId: $event->source_account_id,
                module: 'referral',
                isSensitive: true,
            ));
        }

        return $event->refresh();
    }

    /**
     * A refund of a qualifying payment was confirmed by the provider.
     *
     * Never allowed to fail the refund: the money has already gone back. A
     * failure here is written loudly and the event is left for a person.
     */
    public function forRefund(Payment $payment, RefundRequest $refund): void
    {
        /** @var ReferralQualifyingEvent|null $event */
        $event = ReferralQualifyingEvent::query()->where('payment_id', $payment->id)->first();

        if ($event === null) {
            return;
        }

        try {
            $this->handle($event, ReversalCause::Refund, 'Refund '.$refund->public_id.' of payment '.$payment->reference.'.');
        } catch (Throwable $throwable) {
            $this->log->channel('wallet')->critical('Could not reverse referral commissions for a refunded payment', [
                'refund' => $refund->public_id,
                'payment' => $payment->reference,
                'event' => $event->public_id,
                'error' => $throwable->getMessage(),
            ]);
        }
    }
}
