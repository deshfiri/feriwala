<?php

namespace App\Domain\Referral\Enums;

/**
 * What makes a referral commission payable (§25.2, D24).
 *
 * One today: the referred business's **activation after its verified combined
 * activation payment**, which is where §25.2's qualification path ends. An enum
 * rather than a flag, so an order-based trigger can be added without changing
 * how a plan, an event or a commission is recorded.
 */
enum ReferralTrigger: string
{
    case AccountActivation = 'account_activation';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $trigger) => $trigger->value, self::cases());
    }
}
