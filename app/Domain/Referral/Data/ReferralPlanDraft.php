<?php

namespace App\Domain\Referral\Data;

use App\Domain\Referral\Enums\CommissionBase;
use App\Domain\Referral\Enums\ReferralTrigger;
use Carbon\CarbonImmutable;

/**
 * A plan version as a person asked for it, before it is opened (D24, P7-12).
 *
 * @phpstan-type LevelDraft array{level: int, rule: RewardRule, enabled: bool, required_package_ids: list<int>, min_active_direct_referrals: int}
 */
readonly class ReferralPlanDraft
{
    /**
     * @param  list<LevelDraft>  $levels
     */
    public function __construct(
        public ?int $packageId,
        public ReferralTrigger $trigger,
        public CommissionBase $base,
        public int $maxDepth,
        public array $levels,
        public ?RewardRule $joiningReward,
        public int $holdingDays,
        public int $minimumQualifyingPaymentMinor,
        public bool $qualifiesSuspended,
        public bool $qualifiesRestricted,
        public bool $qualifiesPackageLapsed,
        public bool $qualifiesNotActive,
        public CarbonImmutable $effectiveFrom,
        public string $reason,
    ) {}
}
