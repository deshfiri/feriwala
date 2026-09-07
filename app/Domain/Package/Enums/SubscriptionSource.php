<?php

namespace App\Domain\Package\Enums;

/**
 * How an account came to hold this subscription (§8.2, §8.3).
 *
 * A bare string was doing this, always `'purchase'`, which was true only
 * because nothing else could create one yet. §8.3 adds renewal, upgrade,
 * downgrade and administrative assignment, and each answers a different
 * question later: what to charge on renewal, what a report counts as revenue,
 * and whether a term was sold or granted.
 *
 * Kept apart from {@see UserPackageStatus} on purpose. Status is where a
 * subscription **is**; source is where it **came from**, and it never changes
 * once written — a promotional term that renews is a new subscription with a
 * new source, not a promotional one that quietly became a purchase.
 */
enum SubscriptionSource: string
{
    /** The account chose and paid for it (§8.2). */
    case Purchase = 'purchase';

    /** Carried on from a term that ran out (§8.2). */
    case Renewal = 'renewal';

    /** Moved to a larger package mid-term (§8.3). */
    case Upgrade = 'upgrade';

    /** Moved to a smaller one (§8.3, D16). */
    case Downgrade = 'downgrade';

    /** Assigned by an administrator, not bought (§8.3). */
    case Manual = 'manual';

    /** Granted as a promotion (§8.3). */
    case Promotional = 'promotional';

    public function label(): string
    {
        return match ($this) {
            self::Purchase => 'Purchased',
            self::Renewal => 'Renewed',
            self::Upgrade => 'Upgraded',
            self::Downgrade => 'Downgraded',
            self::Manual => 'Assigned by an administrator',
            self::Promotional => 'Promotional',
        };
    }

    /**
     * Whether the account paid for this term.
     *
     * A revenue report asks this. An administratively granted or promotional
     * term is real entitlement and no money, and counting it as a sale would
     * overstate what Feriwala earned.
     */
    public function isPaid(): bool
    {
        return match ($this) {
            self::Purchase, self::Renewal, self::Upgrade, self::Downgrade => true,
            self::Manual, self::Promotional => false,
        };
    }
}
