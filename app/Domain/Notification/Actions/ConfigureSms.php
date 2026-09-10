<?php

namespace App\Domain\Notification\Actions;

use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Settings\Enums\SettingType;
use App\Domain\Settings\SettingsRepository;
use App\Integrations\Sms\SmsProviderManager;
use App\Models\User;
use InvalidArgumentException;

/**
 * Switches SMS on or off, and chooses who carries it (§30).
 *
 * §30 requires SMS to be disableable globally, by provider and by event. This
 * holds the first two; the per-event switch lives beside the notifications it
 * governs.
 *
 * Audited, and deliberately so. "Nobody received their payment confirmations
 * last week" is a question about a setting somebody changed, and an audit trail
 * is what turns that from an argument into a fact.
 */
class ConfigureSms
{
    public function __construct(
        protected SettingsRepository $settings,
        protected SmsProviderManager $providers,
        protected RecordAuditLog $audit,
    ) {}

    public function handle(User $actor, bool $enabled, ?string $provider = null): void
    {
        $before = [
            'enabled' => $this->providers->isEnabled(),
            'provider' => $this->providers->active(),
        ];

        if ($provider !== null && ! in_array($provider, $this->providers->available(), true)) {
            // Choosing a provider with no driver would switch messaging to
            // something that cannot send, which reads as "SMS is broken".
            throw new InvalidArgumentException('That SMS provider is not available.');
        }

        $this->settings->define(
            SmsProviderManager::ENABLED_SETTING,
            'sms',
            SettingType::Boolean,
            true,
            label: 'SMS enabled',
        );

        $this->settings->set(SmsProviderManager::ENABLED_SETTING, $enabled, $actor->id);

        if ($provider !== null) {
            $this->settings->define(
                SmsProviderManager::PROVIDER_SETTING,
                'sms',
                SettingType::String,
                'log',
                label: 'SMS provider',
            );

            $this->settings->set(SmsProviderManager::PROVIDER_SETTING, $provider, $actor->id);
        }

        $this->audit->handle(new AuditEntry(
            action: 'sms.settings_changed',
            actorId: $actor->id,
            before: $before,
            after: ['enabled' => $enabled, 'provider' => $provider ?? $before['provider']],
            module: 'sms',
            // Whether customers are told about their own money.
            isSensitive: true,
        ));
    }
}
