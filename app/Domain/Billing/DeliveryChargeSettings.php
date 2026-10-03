<?php

namespace App\Domain\Billing;

use App\Domain\Settings\SettingsRepository;
use App\Support\Money\Currency;
use App\Support\Money\Money;

/**
 * The global knobs {@see CalculateDeliveryCharge} applies on top of whichever
 * weight-tier rule it resolves (beta-critical batch, Commit 2).
 *
 * Ordinary scalar settings, unlike the dated weight-tier rules themselves —
 * these are a server-wide default an administrator tunes directly, with no
 * history to preserve, the same shape {@see
 * \App\Domain\Inventory\ReservationWindows} already uses for its own pair of
 * settings.
 */
class DeliveryChargeSettings
{
    public const VOLUMETRIC_DIVISOR = 'delivery.volumetric_divisor';

    public const USE_GREATER_OF_ACTUAL_AND_VOLUMETRIC = 'delivery.use_greater_of_actual_and_volumetric';

    public const ADDITIONAL_PER_KG_CHARGE = 'delivery.additional_per_kg_charge';

    public const PER_BOX_CHARGE = 'delivery.per_box_charge';

    public const FRAGILE_HANDLING_CHARGE = 'delivery.fragile_handling_charge';

    public const MINIMUM_CHARGE = 'delivery.minimum_charge';

    public const MAXIMUM_CHARGE = 'delivery.maximum_charge';

    public const FREE_DELIVERY_THRESHOLD = 'delivery.free_delivery_threshold';

    public const SUCCESS_FEE_PERCENT = 'delivery.success_fee_percent';

    /**
     * The Delivery Success Fee's own default (D-new): 1% of the supplier's
     * gross payable per line, applied once at `Delivered`.
     */
    public const DEFAULT_SUCCESS_FEE_PERCENT = '1';

    /**
     * A courier's common 5000 divisor (centimetres cubed per kilogram) --
     * administrators change it per their own courier's convention.
     */
    public const DEFAULT_VOLUMETRIC_DIVISOR = 5000;

    public function __construct(
        protected SettingsRepository $settings,
    ) {}

    public function volumetricDivisor(): int
    {
        $value = $this->settings->get(self::VOLUMETRIC_DIVISOR);

        return is_numeric($value) && (int) $value > 0 ? (int) $value : self::DEFAULT_VOLUMETRIC_DIVISOR;
    }

    public function useGreaterOfActualAndVolumetric(): bool
    {
        $value = $this->settings->get(self::USE_GREATER_OF_ACTUAL_AND_VOLUMETRIC);

        return $value === null ? true : (bool) $value;
    }

    public function additionalPerKgCharge(Currency $currency): Money
    {
        return $this->moneyOrZero(self::ADDITIONAL_PER_KG_CHARGE, $currency);
    }

    public function perBoxCharge(Currency $currency): Money
    {
        return $this->moneyOrZero(self::PER_BOX_CHARGE, $currency);
    }

    public function fragileHandlingCharge(Currency $currency): Money
    {
        return $this->moneyOrZero(self::FRAGILE_HANDLING_CHARGE, $currency);
    }

    public function minimumCharge(Currency $currency): Money
    {
        return $this->moneyOrZero(self::MINIMUM_CHARGE, $currency);
    }

    /**
     * Null means no cap is configured.
     */
    public function maximumCharge(Currency $currency): ?Money
    {
        $value = $this->settings->get(self::MAXIMUM_CHARGE);

        return $value instanceof Money ? $value : null;
    }

    /**
     * Null means delivery is never free regardless of order value.
     */
    public function freeDeliveryThreshold(Currency $currency): ?Money
    {
        $value = $this->settings->get(self::FREE_DELIVERY_THRESHOLD);

        return $value instanceof Money ? $value : null;
    }

    /**
     * The Delivery Success Fee's rate, as a plain decimal string — never a
     * float, since it is about to multiply a Money amount (D-new).
     */
    public function deliverySuccessFeePercent(): string
    {
        $value = $this->settings->get(self::SUCCESS_FEE_PERCENT);

        return is_string($value) && $value !== '' ? $value : self::DEFAULT_SUCCESS_FEE_PERCENT;
    }

    protected function moneyOrZero(string $key, Currency $currency): Money
    {
        $value = $this->settings->get($key);

        return $value instanceof Money ? $value : Money::zero($currency);
    }
}
