<?php

namespace App\Domain\Inventory;

use App\Domain\Inventory\Enums\ReservationKind;
use App\Domain\Settings\SettingsRepository;
use Carbon\CarbonImmutable;

/**
 * How long a reservation holds stock (contract §6.1.2).
 *
 * The frozen storefront contract fixes the v1 defaults — **15 minutes** for an
 * online payment and a **24-hour** confirmation window for cash on delivery —
 * and makes both configurable in ERP settings. Unlike an opt-in deadline, a
 * reservation always has an end: a hold that never expires is stock quietly
 * taken off sale, so an unset or unusable setting falls back to the contract's
 * default rather than to "forever".
 *
 * Bounded, so a mistyped value cannot release stock before anybody could pay or
 * hold it for weeks.
 */
class ReservationWindows
{
    public const ONLINE_MINUTES = 'inventory.reservation_online_minutes';

    public const COD_HOURS = 'inventory.reservation_cod_hours';

    public const DEFAULT_ONLINE_MINUTES = 15;

    public const DEFAULT_COD_HOURS = 24;

    /** Nobody completes a payment in under five minutes reliably. */
    public const MINIMUM_ONLINE_MINUTES = 5;

    /** A day: longer is not waiting on a payment, it is holding stock. */
    public const MAXIMUM_ONLINE_MINUTES = 1440;

    public const MINIMUM_COD_HOURS = 1;

    /** A week. */
    public const MAXIMUM_COD_HOURS = 168;

    public function __construct(
        protected SettingsRepository $settings,
    ) {}

    public function onlineMinutes(): int
    {
        return $this->bounded(
            $this->settings->get(self::ONLINE_MINUTES),
            self::DEFAULT_ONLINE_MINUTES,
            self::MINIMUM_ONLINE_MINUTES,
            self::MAXIMUM_ONLINE_MINUTES,
        );
    }

    public function codHours(): int
    {
        return $this->bounded(
            $this->settings->get(self::COD_HOURS),
            self::DEFAULT_COD_HOURS,
            self::MINIMUM_COD_HOURS,
            self::MAXIMUM_COD_HOURS,
        );
    }

    /**
     * When a reservation of this kind made at `$from` runs out.
     *
     * Stored on the reservation when it is made: a customer told they have
     * fifteen minutes still has fifteen minutes if the window is changed while
     * they are paying.
     */
    public function expiryFor(ReservationKind $kind, ?CarbonImmutable $from = null): CarbonImmutable
    {
        $from ??= CarbonImmutable::now();

        return match ($kind) {
            ReservationKind::OnlinePayment => $from->addMinutes($this->onlineMinutes()),
            ReservationKind::CashOnDelivery => $from->addHours($this->codHours()),
        };
    }

    protected function bounded(mixed $value, int $default, int $minimum, int $maximum): int
    {
        if (! is_numeric($value) || (int) $value <= 0) {
            return $default;
        }

        return max($minimum, min($maximum, (int) $value));
    }
}
