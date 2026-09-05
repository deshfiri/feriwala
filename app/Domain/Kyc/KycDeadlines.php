<?php

namespace App\Domain\Kyc;

use App\Domain\Settings\SettingsRepository;
use Carbon\CarbonImmutable;

/**
 * The §7.4 deadline policy, in one place.
 *
 * §7.4 makes the window configurable and leaves the consequences to the
 * administrator's judgement — "may remain blocked", "may be restricted", "may
 * be sent". Every one of those is a setting rather than a hardcoded rule, and
 * the defaults below are deliberately mild: the safe failure for a new
 * installation is that nobody is restricted by a policy nobody chose.
 *
 * Deadlines are opt-in. With `kyc.deadline_days` unset there is no deadline at
 * all, which is the right behaviour before an administrator has decided what
 * the window should be — inventing thirty days on their behalf would start
 * restricting real accounts on a number nobody agreed.
 */
class KycDeadlines
{
    /** Days an applicant has to complete a round. Null disables deadlines. */
    public const DAYS = 'kyc.deadline_days';

    /** Days before the deadline that a warning is sent. Null disables it. */
    public const WARN_DAYS = 'kyc.deadline_warning_days';

    /**
     * Whether an already-active account is restricted when its KYC update
     * (§7.2) goes overdue.
     *
     * Off by default. Restricting a trading business is a serious step, and
     * §7.4 offers it rather than requiring it.
     */
    public const RESTRICTS_ACTIVE = 'kyc.overdue_restricts_active_account';

    public function __construct(
        protected SettingsRepository $settings,
    ) {}

    public function isEnabled(): bool
    {
        return $this->days() !== null;
    }

    public function days(): ?int
    {
        $days = $this->settings->get(self::DAYS);

        return is_numeric($days) && (int) $days > 0 ? (int) $days : null;
    }

    public function warningDays(): ?int
    {
        $days = $this->settings->get(self::WARN_DAYS);

        return is_numeric($days) && (int) $days > 0 ? (int) $days : null;
    }

    public function restrictsActiveAccounts(): bool
    {
        return (bool) $this->settings->get(self::RESTRICTS_ACTIVE, false);
    }

    /**
     * When a round opened now would fall due, or null when deadlines are off.
     */
    public function deadlineFrom(?CarbonImmutable $openedAt = null): ?CarbonImmutable
    {
        $days = $this->days();

        if ($days === null) {
            return null;
        }

        return ($openedAt ?? now()->toImmutable())->addDays($days);
    }
}
