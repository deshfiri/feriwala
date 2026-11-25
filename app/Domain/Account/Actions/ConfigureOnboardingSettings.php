<?php

namespace App\Domain\Account\Actions;

use App\Domain\Account\PreferredDomainExtensions;
use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Kyc\Enums\KycSelfieMode;
use App\Domain\Kyc\KycSelfiePolicy;
use App\Domain\Settings\Enums\SettingType;
use App\Domain\Settings\SettingsRepository;
use App\Models\User;
use InvalidArgumentException;

/**
 * Onboarding settings an administrator controls (D39): whether KYC asks for a
 * selfie for everyone, and which domain extensions a partner may choose their
 * preferred domain from. Each change is audited.
 */
class ConfigureOnboardingSettings
{
    public function __construct(
        protected SettingsRepository $settings,
        protected KycSelfiePolicy $selfies,
        protected PreferredDomainExtensions $extensions,
        protected RecordAuditLog $audit,
    ) {}

    /** Null leaves the selfie to each KYC batch, as before. */
    public function selfie(User $actor, ?KycSelfieMode $mode): void
    {
        $before = $this->selfies->global()?->value;

        $this->settings->define(KycSelfiePolicy::SETTING, 'kyc', SettingType::String, null, label: 'KYC selfie for every partner');
        $this->settings->set(KycSelfiePolicy::SETTING, $mode?->value, $actor->id);

        $this->audit->handle(new AuditEntry(
            action: 'kyc.selfie_setting_changed',
            actorId: $actor->id,
            before: ['mode' => $before ?? 'per_batch'],
            after: ['mode' => $mode === null ? 'per_batch' : $mode->value],
            module: 'kyc',
            isSensitive: true,
        ));
    }

    /**
     * @param  list<string>  $extensions  e.g. ['.com', 'shop']
     *
     * @throws InvalidArgumentException when none is a valid extension
     */
    public function domainExtensions(User $actor, array $extensions): void
    {
        $clean = [];

        foreach ($extensions as $extension) {
            $normalised = PreferredDomainExtensions::normalise($extension);

            if ($normalised !== null && ! in_array($normalised, $clean, true)) {
                $clean[] = $normalised;
            }
        }

        if ($clean === []) {
            throw new InvalidArgumentException('Offer at least one valid domain extension, such as .com.');
        }

        $before = $this->extensions->enabled();

        $this->settings->define(PreferredDomainExtensions::SETTING, 'onboarding', SettingType::Json, PreferredDomainExtensions::DEFAULT, label: 'Preferred domain extensions');
        $this->settings->set(PreferredDomainExtensions::SETTING, $clean, $actor->id);

        $this->audit->handle(new AuditEntry(
            action: 'onboarding.domain_extensions_changed',
            actorId: $actor->id,
            before: ['extensions' => $before],
            after: ['extensions' => $clean],
            module: 'account',
        ));
    }
}
