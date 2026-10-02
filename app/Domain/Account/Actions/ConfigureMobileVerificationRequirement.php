<?php

namespace App\Domain\Account\Actions;

use App\Domain\Account\MobileVerificationRequirement;
use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Settings\Enums\SettingType;
use App\Domain\Settings\SettingsRepository;
use App\Models\User;

/**
 * Switches the mobile-verification requirement on or off (§5.1).
 *
 * Audited, and deliberately so: this changes what every newly registering
 * owner must do before their business can be activated, and "why did my
 * account skip mobile verification" is a question an audit trail answers
 * rather than an argument.
 */
class ConfigureMobileVerificationRequirement
{
    public function __construct(
        protected SettingsRepository $settings,
        protected MobileVerificationRequirement $requirement,
        protected RecordAuditLog $audit,
    ) {}

    public function handle(User $actor, bool $required): void
    {
        $before = $this->requirement->isRequired();

        $this->settings->define(
            MobileVerificationRequirement::SETTING,
            'account',
            SettingType::Boolean,
            true,
            label: 'Mobile number verification required',
        );

        $this->settings->set(MobileVerificationRequirement::SETTING, $required, $actor->id);

        $this->audit->handle(new AuditEntry(
            action: 'account.mobile_verification_requirement_changed',
            actorId: $actor->id,
            before: ['required' => $before],
            after: ['required' => $required],
            module: 'account',
            isSensitive: true,
        ));
    }
}
