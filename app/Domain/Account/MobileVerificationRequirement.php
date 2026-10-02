<?php

namespace App\Domain\Account;

use App\Domain\Account\Actions\SkipMobileVerificationIfNotRequired;
use App\Domain\Kyc\KycDeadlines;
use App\Domain\Settings\SettingsRepository;

/**
 * Whether a registering owner must confirm their mobile number before the
 * funnel moves them on (§5.1).
 *
 * On by default -- §5.1 names mobile verification as a normal part of
 * registration, so the safe default for an installation nobody has
 * configured is the one the spec already describes. An administrator
 * switches it off only when SMS delivery is not reliable enough yet to gate
 * onboarding on it; the moment it is off, nobody is left stranded on a step
 * they cannot complete (see {@see SkipMobileVerificationIfNotRequired}).
 *
 * Mirrors {@see KycDeadlines}'s shape: one small class, one
 * setting, a single boolean question everything else asks instead of
 * re-deriving it.
 */
class MobileVerificationRequirement
{
    public const SETTING = 'account.mobile_verification_required';

    public function __construct(
        protected SettingsRepository $settings,
    ) {}

    public function isRequired(): bool
    {
        $value = $this->settings->get(self::SETTING);

        return $value === null ? true : (bool) $value;
    }
}
