<?php

namespace App\Domain\Referral\Actions;

use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Referral\Exceptions\ReferralRefused;
use App\Domain\Referral\Models\ReferralPlan;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;

/**
 * Take a plan version out of force, from now (D24, P7-12, P7-44).
 *
 * Closed rather than deleted: every commission it calculated names it, and
 * stays explained by it. A version that had not started yet ends before it
 * began and was never in force. Commissions already calculated under it are
 * untouched — they were decided when their event happened.
 */
class CloseReferralPlan
{
    public function __construct(
        protected DatabaseManager $database,
        protected RecordAuditLog $audit,
    ) {}

    /**
     * @throws ReferralRefused
     */
    public function handle(ReferralPlan $plan, User $actor, string $reason): ReferralPlan
    {
        return $this->database->transaction(function () use ($plan, $actor, $reason) {
            /** @var ReferralPlan $locked */
            $locked = ReferralPlan::query()->lockForUpdate()->findOrFail($plan->id);

            if ($locked->isClosed()) {
                throw ReferralRefused::planNotOpen();
            }

            $now = CarbonImmutable::now();
            $before = $locked->effective_to;

            $endsAt = $locked->effective_from->greaterThan($now) ? $locked->effective_from : $now;

            if ($before !== null && $before->lessThan($endsAt)) {
                $endsAt = $before;
            }

            $locked->forceFill([
                'effective_to' => $endsAt,
                'closed_at' => $now,
                'closed_by' => $actor->id,
                'close_reason' => $reason,
            ])->save();

            $this->audit->handle(new AuditEntry(
                action: 'referral.plan_closed',
                actorId: $actor->id,
                auditableType: ReferralPlan::class,
                auditableId: $locked->id,
                before: ['effective_to' => $before?->toIso8601String()],
                after: ['effective_to' => $endsAt->toIso8601String()],
                reason: $reason,
                module: 'referral',
            ));

            return $locked;
        });
    }
}
