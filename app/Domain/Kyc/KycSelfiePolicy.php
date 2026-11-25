<?php

namespace App\Domain\Kyc;

use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Kyc\Enums\KycSelfieMode;
use App\Domain\Settings\SettingsRepository;

/**
 * Whether a partner's KYC asks for a selfie (D39 step 4): "only if enabled by
 * the admin, globally or for the individual partner".
 *
 * Three levels, most specific first: the partner's own override
 * (`business_accounts.kyc_selfie_mode`), the platform setting, and — when
 * neither says anything (null) — the KYC batch's own selfie setting (D30),
 * which is how every round behaved before.
 */
class KycSelfiePolicy
{
    public const SETTING = 'kyc.selfie_mode';

    public function __construct(
        protected SettingsRepository $settings,
    ) {}

    /** The platform-wide setting; null leaves it to each KYC batch. */
    public function global(): ?KycSelfieMode
    {
        $value = $this->settings->get(self::SETTING);

        return is_string($value) ? KycSelfieMode::tryFrom($value) : null;
    }

    /** What applies to this partner; null leaves it to the KYC batch. */
    public function forAccount(BusinessAccount $account): ?KycSelfieMode
    {
        $override = is_string($account->kyc_selfie_mode) ? KycSelfieMode::tryFrom($account->kyc_selfie_mode) : null;

        return $override ?? $this->global();
    }
}
