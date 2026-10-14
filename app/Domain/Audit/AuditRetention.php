<?php

namespace App\Domain\Audit;

use App\Domain\Settings\SettingsRepository;

/**
 * How many days the audit log is kept before the nightly purge removes entries.
 *
 * Zero (the default for an installation nobody has configured) keeps everything:
 * deleting a trail must be a choice somebody made, never what happens by
 * default.
 */
class AuditRetention
{
    public const SETTING = 'audit.log_retention_days';

    public const MAXIMUM_DAYS = 3650;

    public function __construct(
        protected SettingsRepository $settings,
    ) {}

    /**
     * Days to keep entries, or null when they are kept without limit.
     */
    public function days(): ?int
    {
        $days = (int) $this->settings->get(self::SETTING, 0);

        return $days > 0 ? min($days, self::MAXIMUM_DAYS) : null;
    }
}
