<?php

namespace App\Domain\Website;

use App\Domain\Settings\SettingsRepository;
use App\Domain\Website\Models\Website;
use App\Support\Money\Currency;
use App\Support\Money\Money;

/**
 * Whether a website may take cash on delivery, and up to what (§18.4, §28,
 * contract §6.1.2).
 *
 * **The ERP decides, never the storefront.** A submission asking for cash on
 * delivery is refused unless this says the shop may take it — the storefront is
 * told what happened, and nothing about it changes the answer.
 *
 * Two places hold the answer, narrowest first: the website's own
 * `payment_config`, then a platform default in settings. There is deliberately
 * no third: cash on delivery is switched on per shop by somebody who knows that
 * shop's couriers, and the platform default exists so that a new shop starts
 * where the platform wants it to (off, until §28's collection and settlement
 * are built).
 *
 * The ceiling is the same shape. An unpaid parcel is credit extended to a
 * stranger, and the ceiling is what limits how much of it one order can be.
 */
class CodTerms
{
    /** Whether any website may take cash on delivery unless it says otherwise. */
    public const ENABLED = 'orders.website_cod_enabled';

    /** The most one cash-on-delivery order may come to, platform-wide. */
    public const MAXIMUM = 'orders.website_cod_maximum_amount';

    public function __construct(
        protected SettingsRepository $settings,
    ) {}

    public function enabledFor(Website $website): bool
    {
        $own = $website->payment_config['cod']['enabled'] ?? null;

        if (is_bool($own)) {
            return $own;
        }

        return (bool) $this->settings->get(self::ENABLED, false);
    }

    /**
     * The most one order may come to, or null where nothing limits it.
     */
    public function maximumFor(Website $website, Currency $currency): ?Money
    {
        $own = $website->payment_config['cod']['maximum_amount'] ?? null;

        if (is_numeric($own)) {
            return Money::fromDecimal((string) $own, $currency);
        }

        $platform = $this->settings->get(self::MAXIMUM);

        if ($platform instanceof Money) {
            return $platform;
        }

        return is_numeric($platform) ? Money::fromDecimal((string) $platform, $currency) : null;
    }

    /**
     * Whether this shop may take this order on delivery.
     */
    public function allows(Website $website, Money $total): bool
    {
        if (! $this->enabledFor($website)) {
            return false;
        }

        $maximum = $this->maximumFor($website, $total->currency);

        return $maximum === null || ! $total->greaterThan($maximum);
    }
}
