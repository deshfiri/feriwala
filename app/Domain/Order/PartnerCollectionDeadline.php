<?php

namespace App\Domain\Order;

use App\Domain\Settings\SettingsRepository;
use Carbon\CarbonImmutable;

/**
 * How long a Partner-gateway order may wait for the Partner's Wallet to cover
 * Banij's share before it needs a person (D36).
 *
 * Twenty-four hours from the moment the Partner's payment was verified, unless
 * Admin has set another. Like a reservation window, an unset or unusable setting
 * falls back to the default rather than to "forever", and the figure is bounded
 * so a mistyped value cannot release stock at once or hold it for months.
 *
 * Read once, when a collection is opened, and frozen on it: a later change
 * reaches only the holds that come after.
 */
class PartnerCollectionDeadline
{
    public const SETTING = 'orders.partner_collection_deadline_hours';

    public const DEFAULT_HOURS = 24;

    public const MINIMUM_HOURS = 1;

    /** A week. */
    public const MAXIMUM_HOURS = 168;

    public function __construct(
        protected SettingsRepository $settings,
    ) {}

    public function hours(): int
    {
        $value = $this->settings->get(self::SETTING);

        if (! is_numeric($value)) {
            return self::DEFAULT_HOURS;
        }

        $hours = (int) $value;

        return $hours < self::MINIMUM_HOURS || $hours > self::MAXIMUM_HOURS ? self::DEFAULT_HOURS : $hours;
    }

    public function dueFor(CarbonImmutable $verifiedAt, int $hours): CarbonImmutable
    {
        return $verifiedAt->addHours($hours);
    }
}
