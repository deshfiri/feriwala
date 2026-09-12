<?php

namespace App\Integrations\Payment\Gateways;

use App\Domain\Settings\SettingsRepository;
use App\Integrations\Payment\Exceptions\GatewayUnavailable;

/**
 * Where a provider's credentials live, for every provider (§26.4, §36, D7).
 *
 * Never in committed config and never in code. Settings hold them encrypted at
 * rest, under **separate sandbox and live keys**, so switching mode cannot pick
 * up the wrong pair — testing must not be able to take real money, and a live
 * merchant account must not receive test traffic.
 *
 * The mode itself is a setting rather than an environment flag, because §26.4
 * makes it an administrator's decision and an environment variable is not
 * something an administrator has.
 *
 * Reading a missing credential **throws**. A driver that fell back to an empty
 * string would send an unauthenticated request to a payment provider and then
 * have to interpret whatever came back.
 */
abstract class GatewayCredentials
{
    public const SANDBOX = 'sandbox';

    public const LIVE = 'live';

    public function __construct(
        protected SettingsRepository $settings,
    ) {}

    /**
     * The provider's name in settings and config.
     */
    abstract public function gateway(): string;

    /**
     * The setting keys this provider cannot work without.
     *
     * Names only. They drive both "is this configured" and the administration
     * screen's list of what is still missing, so the two cannot drift apart.
     *
     * @return array<int, string>
     */
    abstract public function requiredKeys(): array;

    public function isSandbox(): bool
    {
        return $this->mode() === self::SANDBOX;
    }

    public function mode(): string
    {
        $mode = $this->settings->get("payment.{$this->gateway()}.mode", self::SANDBOX);

        // Anything unrecognised means sandbox. A typo in a setting must not
        // quietly point test credentials at a live endpoint.
        return $mode === self::LIVE ? self::LIVE : self::SANDBOX;
    }

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
     * @throws GatewayUnavailable when the credential is not configured
     */
    public function require(string $key): string
    {
        $value = $this->value($key);

        if ($value === null) {
            throw GatewayUnavailable::missingCredentials($this->gateway());
        }

        return $value;
    }

    /**
     * One credential, for the mode currently in force.
     */
    public function value(string $key): ?string
    {
        $value = $this->settings->get("payment.{$this->gateway()}.{$this->mode()}.{$key}");

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * A setting that is the same in both modes — an endpoint override, say.
     */
    public function shared(string $key, ?string $default = null): ?string
    {
        $value = $this->settings->get("payment.{$this->gateway()}.{$key}", $default);

        return is_string($value) && $value !== '' ? $value : $default;
    }
}
