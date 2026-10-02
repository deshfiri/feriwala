<?php

namespace App\Domain\Notification\Actions;

use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Billing\Actions\ConfigureGateway;
use App\Domain\Settings\Enums\SettingType;
use App\Domain\Settings\SettingsRepository;
use App\Integrations\Sms\Contracts\SmsProvider;
use App\Integrations\Sms\SmsProviderManager;
use App\Models\User;
use InvalidArgumentException;

/**
 * Stores one SMS provider's credentials (§30.1, §36).
 *
 * Mirrors {@see ConfigureGateway} exactly: the
 * settings table holds them encrypted, a blank field means "leave this
 * alone" rather than "clear it", and nothing ever reads a stored secret back
 * out to a screen. There is no sandbox/live split here -- an SMS provider
 * has one account, not two merchant modes -- so unlike a gateway's
 * credentials, these are not nested under a mode.
 */
class ConfigureSmsCredentials
{
    public function __construct(
        protected SettingsRepository $settings,
        protected SmsProviderManager $providers,
        protected RecordAuditLog $audit,
    ) {}

    /**
     * @param  array<string, string|null>  $credentials  keyed by the driver's own required keys
     *
     * @throws InvalidArgumentException when the provider has no driver
     */
    public function handle(User $actor, string $provider, array $credentials): void
    {
        $driver = $this->driverFor($provider);

        $written = [];

        // Only the keys this driver actually declares. A value posted for a
        // key the provider does not have is not stored.
        foreach ($driver->requiredConfiguration() as $key) {
            $value = $credentials[$key] ?? null;

            // Blank means "leave it", not "clear it" -- the form cannot show
            // what is already there, so it cannot ask to keep it either.
            if (! is_string($value) || trim($value) === '') {
                continue;
            }

            $setting = "sms.{$provider}.{$key}";

            $this->settings->define(
                $setting,
                'sms',
                SettingType::String,
                isEncrypted: true,
                label: $provider.' '.str_replace('_', ' ', $key),
            );

            $this->settings->set($setting, trim($value), $actor->id);

            $written[] = $key;
        }

        $this->audit->handle(new AuditEntry(
            action: 'sms.provider_configured',
            actorId: $actor->id,
            // The names of the fields that changed, never their values.
            after: ['provider' => $provider, 'credentials_set' => $written],
            module: 'sms',
            isSensitive: true,
        ));
    }

    /**
     * @throws InvalidArgumentException when the provider has no driver
     */
    protected function driverFor(string $provider): SmsProvider
    {
        if (! in_array($provider, $this->providers->available(), true)) {
            throw new InvalidArgumentException(
                'That SMS provider has no driver yet, so there is nothing to configure for it.'
            );
        }

        return $this->providers->driver($provider);
    }
}
