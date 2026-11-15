<?php

namespace App\Domain\Website\Actions;

use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Settings\Enums\SettingType;
use App\Domain\Settings\SettingsRepository;
use App\Domain\Website\CheckoutVerification;
use App\Domain\Website\Models\Website;

/**
 * Turn the SMS confirmation code for cash-on-delivery orders off for every
 * storefront and keep it off — or require it of all of them — from the console.
 *
 * **Off** sets the platform kill switch ({@see CheckoutVerification::DISABLED}):
 * no storefront asks a customer for a code and **no Partner can turn it back
 * on**. It also clears the "required" policy, and writes each storefront's own
 * setting off, so the state is the same whichever way it is read. Each shop is
 * told its site changed.
 *
 * **On** lifts the kill switch and requires the code of every storefront;
 * shops' own settings are left as they were, and cannot turn it off while the
 * policy stands.
 *
 * One audit entry records the run, made by the system rather than a person,
 * with how many storefronts it changed. No code or customer detail is in it.
 */
class SetCheckoutVerificationEverywhere
{
    public function __construct(
        protected SettingsRepository $settings,
        protected CheckoutVerification $verification,
        protected AnnounceWebsiteUpdated $announce,
        protected RecordAuditLog $audit,
    ) {}

    /**
     * @return array{code_required: bool, storefronts_changed: int}
     */
    public function handle(bool $codeRequired): array
    {
        $before = [
            'required' => $this->verification->requiredByPlatform(),
            'disabled' => $this->verification->disabledByPlatform(),
        ];

        $this->settings->define(
            CheckoutVerification::POLICY,
            'orders',
            SettingType::Boolean,
            false,
            label: 'Require SMS verification for every storefront order',
        );
        $this->settings->define(
            CheckoutVerification::DISABLED,
            'orders',
            SettingType::Boolean,
            false,
            label: 'Switch the SMS code off for every storefront',
        );

        $this->settings->set(CheckoutVerification::POLICY, $codeRequired);
        $this->settings->set(CheckoutVerification::DISABLED, ! $codeRequired);

        $changed = 0;

        if (! $codeRequired) {
            Website::query()
                ->where('sms_verification_enabled', true)
                ->each(function (Website $website) use (&$changed) {
                    $website->forceFill(['sms_verification_enabled' => false])->save();
                    $this->announce->handle($website);
                    $changed++;
                });
        }

        $this->audit->handle(new AuditEntry(
            action: 'website.sms_verification_changed_everywhere',
            actorType: 'system',
            actorLabel: 'artisan website:sms-verification',
            before: $before,
            after: ['required' => $codeRequired, 'disabled' => ! $codeRequired, 'storefronts_changed' => $changed],
            module: 'website',
            isSensitive: true,
        ));

        return ['code_required' => $codeRequired, 'storefronts_changed' => $changed];
    }
}
