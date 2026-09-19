<?php

namespace App\Domain\Referral\Actions;

use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Package\Models\Package;
use App\Domain\Referral\Data\ReferralPlanDraft;
use App\Domain\Referral\Exceptions\ReferralRefused;
use App\Domain\Referral\Models\ReferralPlan;
use App\Domain\Referral\Models\ReferralPlanLevel;
use App\Models\User;
use App\Support\Concurrency\DistributedLock;
use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;

/**
 * Put a new plan version in force from a date (§25.4, §25.4.1, D24, P7-12).
 *
 * **A rule for every level up to the depth**, or nothing is opened: a gap would
 * leave a level nobody decided. The version currently in force for the same
 * scope — global, or the same package — ends where the new one begins, so one
 * scope never has two versions in force at once. A version already scheduled
 * at or after the new start is left alone and the new one refused: two
 * people's future plans do not silently replace each other.
 *
 * Serialised per scope, audited with the terms and the reason.
 */
class OpenReferralPlan
{
    public function __construct(
        protected DatabaseManager $database,
        protected DistributedLock $lock,
        protected RecordAuditLog $audit,
    ) {}

    /**
     * @throws ReferralRefused
     */
    public function handle(ReferralPlanDraft $draft, User $actor): ReferralPlan
    {
        $this->assertLevelsComplete($draft);

        return $this->lock->run(
            key: 'referral-plan-scope:'.($draft->packageId ?? 'global'),
            callback: fn () => $this->open($draft, $actor),
            ttlSeconds: 30,
            waitSeconds: 10,
        );
    }

    protected function open(ReferralPlanDraft $draft, User $actor): ReferralPlan
    {
        return $this->database->transaction(function () use ($draft, $actor) {
            $sameScope = fn () => ReferralPlan::query()
                ->where('trigger_event', $draft->trigger->value)
                ->when($draft->packageId === null,
                    fn ($query) => $query->whereNull('package_id'),
                    fn ($query) => $query->where('package_id', $draft->packageId))
                ->lockForUpdate();

            $scheduled = $sameScope()
                ->where('effective_from', '>=', $draft->effectiveFrom)
                ->where(fn ($open) => $open->whereNull('effective_to')->orWhereColumn('effective_to', '>', 'effective_from'))
                ->exists();

            if ($scheduled) {
                throw ReferralRefused::planOverlaps();
            }

            // The version in force at the new start ends there.
            $superseded = $sameScope()
                ->where('effective_from', '<', $draft->effectiveFrom)
                ->where(fn ($open) => $open->whereNull('effective_to')->orWhere('effective_to', '>', $draft->effectiveFrom))
                ->get();

            foreach ($superseded as $previous) {
                $previous->forceFill(['effective_to' => $draft->effectiveFrom])->save();

                $this->audit->handle(new AuditEntry(
                    action: 'referral.plan_superseded',
                    actorId: $actor->id,
                    auditableType: ReferralPlan::class,
                    auditableId: $previous->id,
                    before: ['effective_to' => null],
                    after: ['effective_to' => $draft->effectiveFrom->toIso8601String()],
                    reason: $draft->reason,
                    module: 'referral',
                ));
            }

            $plan = ReferralPlan::create([
                'package_id' => $draft->packageId,
                'trigger_event' => $draft->trigger,
                'commission_base' => $draft->base,
                'max_depth' => $draft->maxDepth,
                'currency_code' => 'BDT',
                'joining_reward_type' => $draft->joiningReward?->type,
                'joining_reward_amount_minor' => $draft->joiningReward?->amountMinor,
                'joining_reward_rate_bps' => $draft->joiningReward?->rateBps,
                'joining_reward_cap_minor' => $draft->joiningReward?->capMinor,
                'holding_days' => $draft->holdingDays,
                'minimum_qualifying_payment_minor' => $draft->minimumQualifyingPaymentMinor,
                'qualifies_suspended' => $draft->qualifiesSuspended,
                'qualifies_restricted' => $draft->qualifiesRestricted,
                'qualifies_package_lapsed' => $draft->qualifiesPackageLapsed,
                'qualifies_not_active' => $draft->qualifiesNotActive,
                'effective_from' => $draft->effectiveFrom,
                'reason' => $draft->reason,
                'opened_by' => $actor->id,
            ]);

            foreach ($draft->levels as $level) {
                ReferralPlanLevel::create([
                    'referral_plan_id' => $plan->id,
                    'level' => $level['level'],
                    'reward_type' => $level['rule']->type,
                    'amount_minor' => $level['rule']->amountMinor,
                    'rate_bps' => $level['rule']->rateBps,
                    'cap_minor' => $level['rule']->capMinor,
                    'is_enabled' => $level['enabled'],
                    'required_package_ids' => $level['required_package_ids'] === [] ? null : $level['required_package_ids'],
                    'min_active_direct_referrals' => $level['min_active_direct_referrals'],
                    'created_at' => CarbonImmutable::now(),
                ]);
            }

            $this->audit->handle(new AuditEntry(
                action: 'referral.plan_opened',
                actorId: $actor->id,
                auditableType: ReferralPlan::class,
                auditableId: $plan->id,
                after: $this->terms($draft),
                reason: $draft->reason,
                module: 'referral',
            ));

            return $plan->load('levels');
        });
    }

    /**
     * @throws ReferralRefused
     */
    protected function assertLevelsComplete(ReferralPlanDraft $draft): void
    {
        $levels = array_map(fn (array $level) => $level['level'], $draft->levels);
        sort($levels);

        if ($draft->maxDepth < 1 || $levels !== range(1, $draft->maxDepth)) {
            throw ReferralRefused::levelsIncomplete(max(1, $draft->maxDepth));
        }
    }

    /**
     * What the audit keeps: the terms, not who might receive them.
     *
     * @return array<string, mixed>
     */
    protected function terms(ReferralPlanDraft $draft): array
    {
        return [
            'package' => $draft->packageId === null ? null : Package::query()->whereKey($draft->packageId)->value('public_id'),
            'trigger' => $draft->trigger->value,
            'commission_base' => $draft->base->value,
            'max_depth' => $draft->maxDepth,
            'levels' => array_map(fn (array $level) => [
                'level' => $level['level'],
                'enabled' => $level['enabled'],
                ...$level['rule']->snapshot(),
                'min_active_direct_referrals' => $level['min_active_direct_referrals'],
                'required_packages' => count($level['required_package_ids']),
            ], $draft->levels),
            'joining_reward' => $draft->joiningReward?->snapshot(),
            'holding_days' => $draft->holdingDays,
            'minimum_qualifying_payment_minor' => $draft->minimumQualifyingPaymentMinor,
            'qualifies' => [
                'suspended' => $draft->qualifiesSuspended,
                'restricted' => $draft->qualifiesRestricted,
                'package_lapsed' => $draft->qualifiesPackageLapsed,
                'not_active' => $draft->qualifiesNotActive,
            ],
            'effective_from' => $draft->effectiveFrom->toIso8601String(),
        ];
    }
}
