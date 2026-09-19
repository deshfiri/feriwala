<?php

namespace App\Domain\Referral\Queries;

use App\Domain\Referral\Enums\ReferralTrigger;
use App\Domain\Referral\Models\ReferralPlan;
use Carbon\CarbonImmutable;

/**
 * The plan version that governs an event (D24, P7-12).
 *
 * **Package first, then the global default, whole.** A version for the
 * account's package replaces the global version entirely — depth, levels,
 * joining reward and all — rather than being merged level by level, so which
 * terms paid a commission always has one answer: the version the commission
 * names.
 */
class ResolveReferralPlan
{
    public function for(ReferralTrigger $trigger, ?int $packageId, CarbonImmutable $at): ?ReferralPlan
    {
        $inForce = fn () => ReferralPlan::query()
            ->where('trigger_event', $trigger->value)
            ->inForceAt($at)
            ->with('levels')
            ->orderByDesc('effective_from')
            ->orderByDesc('id');

        if ($packageId !== null) {
            /** @var ReferralPlan|null $specific */
            $specific = $inForce()->where('package_id', $packageId)->first();

            if ($specific !== null) {
                return $specific;
            }
        }

        /** @var ReferralPlan|null $global */
        $global = $inForce()->whereNull('package_id')->first();

        return $global;
    }
}
