<?php

namespace App\Domain\Referral\Enums;

/**
 * Why a level was decided and pays nothing (D24, P7-42).
 *
 * Recorded on the level itself, so the ancestors above keep their own level:
 * a skipped level is still that level.
 */
enum CommissionSkipReason: string
{
    case LevelDisabled = 'level_disabled';
    case StatusNotQualified = 'status_not_qualified';
    case PackageNotEligible = 'package_not_eligible';
    case TooFewDirectReferrals = 'too_few_direct_referrals';
    case BaseExhausted = 'base_exhausted';
    case NothingToPay = 'nothing_to_pay';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $reason) => $reason->value, self::cases());
    }
}
