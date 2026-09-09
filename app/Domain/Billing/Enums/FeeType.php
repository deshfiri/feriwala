<?php

namespace App\Domain\Billing\Enums;

/**
 * A fee an administrator can price by rule (§9).
 *
 * One case today. It is an enum rather than a bare string because a fee type
 * that nothing validates is a typo away from a rule that matches nothing —
 * silently, and only visibly wrong on somebody's invoice.
 */
enum FeeType: string
{
    /**
     * Charged once, when an account is created (§5.1, §9).
     *
     * The package fee is not here: it belongs to the package and is edited on
     * the package, where what it buys is also edited. Splitting it across two
     * screens would be two places to look and two places to disagree.
     */
    case Registration = 'registration';

    public function label(): string
    {
        return match ($this) {
            self::Registration => 'Registration fee',
        };
    }
}
