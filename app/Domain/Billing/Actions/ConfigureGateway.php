<?php

namespace App\Domain\Billing\Actions;

use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Settings\Enums\SettingType;
use App\Domain\Settings\SettingsRepository;
use App\Integrations\Payment\Contracts\PaymentGateway;
use App\Integrations\Payment\Data\GatewayCapability;
use App\Integrations\Payment\Gateways\GatewayCredentials;
use App\Integrations\Payment\PaymentGatewayManager;
use App\Models\User;
use App\Support\Money\Currency;
use InvalidArgumentException;
use RuntimeException;

/**
 * Stores a gateway's credentials and switches it on or off (§26.4, §36, D7).
 *
 * The credentials go into the settings table **encrypted**, under separate
 * sandbox and live keys, so switching mode cannot pick up the wrong pair — and
 * so a live merchant account can never take a test transaction.
 *
 * Write-only by design. Nothing reads a stored secret back out to a screen: the
 * settings form reports whether a credential is *present*, never what it is. A
 * secret rendered into an Inertia prop is a secret in the page source, in the
 * browser's history, and in any error report that captures it (§42).
 *
 * A blank field therefore means "leave this alone", not "clear it". Otherwise
 * an administrator changing the mode would silently wipe the credentials they
 * could not see.
 *
 * Switching a gateway on is deliberately the narrower operation: it refuses
 * while any required credential is missing, because "enabled" is the promise
 * that somebody can actually pay with it.
 */
class ConfigureGateway
{
    /** The mode names the credential keys are nested under. */
    public const MODES = [GatewayCredentials::SANDBOX, GatewayCredentials::LIVE];

    public function __construct(
        protected SettingsRepository $settings,
        protected PaymentGatewayManager $gateways,
        protected RecordAuditLog $audit,
    ) {}

    /**
     * Store what was filled in, for one gateway and one mode.
     *
     * @param  array<string, string|null>  $credentials  keyed by the driver's own required keys
     *
     * @throws InvalidArgumentException when the gateway has no driver or the mode is not real
     */
    public function handle(User $actor, string $gateway, string $mode, array $credentials): void
    {
        $driver = $this->driverFor($gateway);

        if (! in_array($mode, self::MODES, true)) {
            throw new InvalidArgumentException('A gateway is either in sandbox or live mode.');
        }

        $label = $this->labelFor($gateway);

        $this->settings->define(
            "payment.{$gateway}.mode",
            'payment',
            SettingType::String,
            GatewayCredentials::SANDBOX,
            label: "{$label} mode",
        );

        $this->settings->set("payment.{$gateway}.mode", $mode, $actor->id);

        $written = [];

        // Only the keys this driver actually declares. A value posted for a key
        // the provider does not have is not stored — a settings table is not a
        // place to accept arbitrary named secrets from a form.
        foreach ($driver->requiredConfiguration() as $key) {
            $value = $credentials[$key] ?? null;

            // Blank means "leave it", not "clear it" — the form cannot show what
            // is already there, so it cannot ask to keep it either.
            if (! is_string($value) || trim($value) === '') {
                continue;
            }

            $setting = "payment.{$gateway}.{$mode}.{$key}";

            $this->settings->define(
                $setting,
                'payment',
                SettingType::String,
                isEncrypted: true,
                label: $label.' '.str_replace('_', ' ', $key),
            );

            $this->settings->set($setting, trim($value), $actor->id);

            $written[] = $key;
        }

        /*
         * A gateway that has lost a credential it needs cannot stay switched on.
         *
         * The case this exists for is a switch to live while only sandbox
         * credentials are stored: the gateway is enabled, configured for the
         * mode it is no longer in, and would take somebody to a checkout that
         * cannot start a session.
         */
        if ($this->settings->get("payment.{$gateway}.enabled") === true
            && $driver->missingConfiguration() !== []) {
            $this->writeEnabled($actor, $gateway, false);
        }

        $this->audit->handle(new AuditEntry(
            action: 'payment.gateway_configured',
            actorId: $actor->id,
            // The names of the fields that changed, never their values. An audit
            // log that records a store password is a second place it leaks from.
            after: ['gateway' => $gateway, 'mode' => $mode, 'credentials_set' => $written],
            module: 'payment',
            isSensitive: true,
        ));
    }

    /**
     * Switch a gateway on or off (§26.4).
     *
     * @throws InvalidArgumentException when the gateway has no driver
     * @throws RuntimeException when switching on a gateway that is not ready
     */
    public function setEnabled(User $actor, string $gateway, bool $enabled): void
    {
        $driver = $this->driverFor($gateway);

        if ($enabled) {
            $missing = $driver->missingConfiguration();

            if ($missing !== []) {
                throw new RuntimeException(sprintf(
                    'This gateway is still missing %s for its %s credentials.',
                    implode(', ', array_map(fn (string $key) => str_replace('_', ' ', $key), $missing)),
                    $driver->isSandbox() ? 'sandbox' : 'live',
                ));
            }

            /*
             * A gateway that cannot verify cannot be used to take money (§26.4).
             *
             * Everything that releases value depends on asking the provider
             * directly, so a driver that only reads callbacks would be a gateway
             * whose payments could never be settled safely.
             */
            if (! $driver->supports(GatewayCapability::Verify)) {
                throw new RuntimeException(
                    'This gateway cannot confirm a payment with its provider, so it cannot take one.'
                );
            }
        }

        $this->writeEnabled($actor, $gateway, $enabled);

        $this->audit->handle(new AuditEntry(
            action: $enabled ? 'payment.gateway_enabled' : 'payment.gateway_disabled',
            actorId: $actor->id,
            after: [
                'gateway' => $gateway,
                'enabled' => $enabled,
                'mode' => $driver->isSandbox() ? GatewayCredentials::SANDBOX : GatewayCredentials::LIVE,
                'currencies' => array_map(
                    fn (Currency $currency) => $currency->value,
                    $driver->supportedCurrencies(),
                ),
            ],
            module: 'payment',
            isSensitive: true,
        ));
    }

    protected function writeEnabled(User $actor, string $gateway, bool $enabled): void
    {
        $setting = "payment.{$gateway}.enabled";

        $this->settings->define(
            $setting,
            'payment',
            SettingType::Boolean,
            // A provider nobody has decided about is off. Whatever config ships
            // as the default, introducing the setting must not be what turns a
            // gateway on.
            false,
            label: $this->labelFor($gateway).' enabled',
        );

        $this->settings->set($setting, $enabled, $actor->id);
    }

    /**
     * @throws InvalidArgumentException when the gateway has no driver behind it
     */
    protected function driverFor(string $gateway): PaymentGateway
    {
        if (! $this->gateways->isImplemented($gateway)) {
            throw new InvalidArgumentException(
                'That gateway has no driver yet, so there is nothing to configure for it.'
            );
        }

        return $this->gateways->driver($gateway);
    }

    protected function labelFor(string $gateway): string
    {
        $label = config("payment.gateways.{$gateway}.label");

        return is_string($label) && $label !== '' ? $label : $gateway;
    }
}
