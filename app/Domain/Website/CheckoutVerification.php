<?php

namespace App\Domain\Website;

use App\Domain\Settings\SettingsRepository;
use App\Domain\Website\Models\Website;

/**
 * Whether a storefront's cash-on-delivery customers must confirm their order
 * with an SMS code.
 *
 * Two places hold the answer, and the platform's wins when it says "required":
 *
 * 1. the platform policy (`orders.website_sms_verification_required`, Admin → SMS):
 *    when on, **every** storefront requires the code and no Partner can turn it
 *    off;
 * 2. otherwise the Partner's own choice for that storefront
 *    (`websites.sms_verification_enabled`), on unless they switched it off.
 *
 * This governs storefront customer orders only. Account and Supplier
 * verification have their own rules and are not read here.
 */
class CheckoutVerification
{
    /** The platform policy: when true, every storefront requires the code. */
    public const POLICY = 'orders.website_sms_verification_required';

    public function __construct(
        protected SettingsRepository $settings,
    ) {}

    /**
     * Whether the platform requires the code of every storefront.
     */
    public function requiredByPlatform(): bool
    {
        return (bool) $this->settings->get(self::POLICY, false);
    }

    /**
     * Whether this storefront's customers must confirm with a code — the one
     * answer order placement and the storefront both use.
     */
    public function requiredFor(Website $website): bool
    {
        return $this->requiredByPlatform() || $website->sms_verification_enabled;
    }

    /**
     * Whether the Partner's own setting is overridden by the platform, so the
     * screen can say why the switch cannot be turned off.
     */
    public function lockedFor(Website $website): bool
    {
        return $this->requiredByPlatform();
    }
}
