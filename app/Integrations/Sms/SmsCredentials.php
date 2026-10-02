<?php

namespace App\Integrations\Sms;

use App\Domain\Settings\SettingsRepository;
use App\Integrations\Payment\Gateways\GatewayCredentials;
use App\Integrations\Sms\Exceptions\SmsProviderUnavailable;

/**
 * Where a provider's credentials live, for every SMS provider -- the same
 * shape {@see GatewayCredentials} already
 * holds payment gateways to, minus the sandbox/live split: an SMS provider
 * has one account, not two merchant modes.
 *
 * Never in committed config and never in code. Settings hold them encrypted
 * at rest. Reading a missing credential **throws** -- a driver that fell back
 * to an empty string would send an unauthenticated request and then have to
 * interpret whatever came back.
 */
abstract class SmsCredentials
{
    public function __construct(
        protected SettingsRepository $settings,
    ) {}

    /**
     * The provider's name in settings and config.
     */
    abstract public function provider(): string;

    /**
     * The setting keys this provider cannot work without.
     *
     * Names only. They drive both "is this configured" and the administration
     * screen's list of what is still missing, so the two cannot drift apart.
     *
     * @return array<int, string>
     */
    abstract public function requiredKeys(): array;

    /**
     * Required keys with nothing in them.
     *
     * @return array<int, string>
     */
    public function missing(): array
    {
        return array_values(array_filter(
            $this->requiredKeys(),
            fn (string $key) => $this->value($key) === null,
        ));
    }

    public function areConfigured(): bool
    {
        return $this->missing() === [];
    }

    /**
     * @throws SmsProviderUnavailable when the credential is not configured
     */
    public function require(string $key): string
    {
        $value = $this->value($key);

        if ($value === null) {
            throw SmsProviderUnavailable::missingCredentials($this->provider());
        }

        return $value;
    }

    public function value(string $key): ?string
    {
        $value = $this->settings->get("sms.{$this->provider()}.{$key}");

        return is_string($value) && $value !== '' ? $value : null;
    }
}
