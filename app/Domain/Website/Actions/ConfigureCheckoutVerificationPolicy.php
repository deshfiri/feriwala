<?php

namespace App\Domain\Website\Actions;

use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Settings\Enums\SettingType;
use App\Domain\Settings\SettingsRepository;
use App\Domain\Website\CheckoutVerification;
use App\Models\User;

/**
 * Admin → SMS: require the order confirmation code of every storefront, or leave
 * it to each Partner.
 *
 * Audited. No code, phone number or customer detail is ever part of the entry —
 * only the switch and who moved it.
 */
class ConfigureCheckoutVerificationPolicy
{
    public function __construct(
        protected SettingsRepository $settings,
        protected CheckoutVerification $verification,
        protected RecordAuditLog $audit,
    ) {}

    public function handle(User $actor, bool $required): void
    {
        $before = $this->verification->requiredByPlatform();

        $this->settings->define(
            CheckoutVerification::POLICY,
            'orders',
            SettingType::Boolean,
            false,
            label: 'Require SMS verification for every storefront order',
        );

        $this->settings->set(CheckoutVerification::POLICY, $required, $actor->id);

        $this->audit->handle(new AuditEntry(
            action: 'website.sms_verification_policy_changed',
            actorId: $actor->id,
            before: ['required' => $before],
            after: ['required' => $required],
            module: 'website',
            isSensitive: true,
        ));
    }
}
