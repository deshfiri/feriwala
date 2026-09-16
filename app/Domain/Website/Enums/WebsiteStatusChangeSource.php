<?php

namespace App\Domain\Website\Enums;

/**
 * What moved a website's status (§16.4, P5-9).
 *
 * Recorded beside every change, because "who" is not always a person and the
 * history has to say so: a scheduled sweep dropping a website into its grace
 * period and an administrator suspending it are different events, and reading
 * `changed_by IS NULL` cannot tell them apart.
 */
enum WebsiteStatusChangeSource: string
{
    /** The partner, from their own ERP. */
    case Account = 'account';

    /** Feriwala staff. */
    case Staff = 'staff';

    /** The daily lifecycle sweep: package expiry, grace, renewals (§24.3). */
    case Scheduler = 'scheduler';

    /** A setup, domain or hosting charge being paid (§16.2). */
    case Billing = 'billing';

    /** The website's own API connection coming up (§17.3). */
    case Integration = 'integration';

    case System = 'system';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $source) => $source->value, self::cases());
    }
}
