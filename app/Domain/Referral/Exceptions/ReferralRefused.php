<?php

namespace App\Domain\Referral\Exceptions;

use RuntimeException;

/**
 * A referral step the rules do not allow, refused before anything is kept
 * (§25.5, D24).
 *
 * Each refusal is in the reader's language and names the field it belongs to,
 * so a form can put it where the person is looking.
 */
class ReferralRefused extends RuntimeException
{
    public function __construct(string $message, public readonly string $field)
    {
        parent::__construct($message);
    }

    public static function selfReferral(): self
    {
        return new self(__('referral.refused.self_referral'), 'referrer');
    }

    /** The referrer descends from the account: the chain would close on itself. */
    public static function circular(): self
    {
        return new self(__('referral.refused.circular'), 'referrer');
    }

    /** A qualifying event has used this link; it is part of the record now. */
    public static function locked(): self
    {
        return new self(__('referral.refused.locked'), 'referrer');
    }

    /** Only an active business can refer (§25.1). */
    public static function referrerNotActive(): self
    {
        return new self(__('referral.refused.referrer_not_active'), 'referrer');
    }

    public static function planOverlaps(): self
    {
        return new self(__('referral.refused.plan_overlaps'), 'effective_from');
    }

    public static function levelsIncomplete(int $depth): self
    {
        return new self(__('referral.refused.levels_incomplete', ['depth' => $depth]), 'levels');
    }

    public static function levelInvalid(int $level): self
    {
        return new self(__('referral.refused.level_invalid', ['level' => $level]), 'levels');
    }

    public static function planNotOpen(): self
    {
        return new self(__('referral.refused.plan_not_open'), 'plan');
    }

    public static function nothingToReverse(): self
    {
        return new self(__('referral.refused.nothing_to_reverse'), 'commission');
    }
}
