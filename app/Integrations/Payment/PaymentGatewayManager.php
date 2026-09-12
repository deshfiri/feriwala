<?php

namespace App\Integrations\Payment;

use App\Domain\Settings\SettingsRepository;
use App\Integrations\Payment\Contracts\PaymentGateway;
use App\Integrations\Payment\Data\GatewayCapability;
use App\Integrations\Payment\Exceptions\GatewayUnavailable;
use App\Support\Money\Currency;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * Resolves payment gateways by name (§26, D7).
 *
 * Adding a gateway is one class plus a config entry. Nothing in the payment flow
 * knows which providers exist.
 *
 * Whether a gateway is **switched on** is a setting rather than the config value,
 * because §26.4 makes it an administrator's decision and an administrator has no
 * way to edit a committed file. Config still supplies the shipped default, which
 * is how a newly added provider arrives disabled: nobody has decided anything
 * about it yet, so the answer is no.
 */
class PaymentGatewayManager
{
    /** @var array<string, PaymentGateway> */
    protected array $resolved = [];

    public function __construct(
        protected Container $container,
        protected SettingsRepository $settings,
    ) {}

    /**
     * @throws GatewayUnavailable when the gateway exists but is not implemented
     * @throws InvalidArgumentException when the name is not a configured gateway
     */
    public function driver(string $name): PaymentGateway
    {
        if (isset($this->resolved[$name])) {
            return $this->resolved[$name];
        }

        /** @var array<string, mixed>|null $config */
        $config = config("payment.gateways.{$name}");

        if ($config === null) {
            throw new InvalidArgumentException("[{$name}] is not a configured payment gateway.");
        }

        $driver = $config['driver'] ?? null;

        if (! is_string($driver) || $driver === '') {
            throw GatewayUnavailable::forGateway($name, 'no driver is implemented for it yet');
        }

        /** @var PaymentGateway $instance */
        $instance = $this->container->make($driver);

        return $this->resolved[$name] = $instance;
    }

    /**
     * Whether a name belongs to a gateway with a driver behind it.
     *
     * Asked before anything tries to configure one: a provider with no driver
     * has nothing to hold credentials for, and a settings screen that accepted
     * them would be storing secrets for a gateway nobody can use.
     */
    public function isImplemented(string $name): bool
    {
        $driver = config("payment.gateways.{$name}.driver");

        return is_string($driver) && $driver !== '';
    }

    /**
     * Every gateway §26 names, whether or not it is built.
     *
     * @return array<int, string>
     */
    public function names(): array
    {
        /** @var array<string, array<string, mixed>> $gateways */
        $gateways = config('payment.gateways', []);

        return array_keys($gateways);
    }

    /**
     * Gateways with a driver behind them.
     *
     * @return array<int, string>
     */
    public function implemented(): array
    {
        return array_values(array_filter($this->names(), fn (string $name) => $this->isImplemented($name)));
    }

    /**
     * Whether an administrator has switched this gateway on.
     *
     * The setting wins when one exists; config supplies the shipped default
     * otherwise. A provider nobody has decided about is off (§26.4).
     */
    public function isEnabled(string $name): bool
    {
        $setting = "payment.{$name}.enabled";

        if ($this->settings->has($setting)) {
            return $this->settings->get($setting) === true;
        }

        return config("payment.gateways.{$name}.enabled") === true;
    }

    /**
     * Gateways an administrator has switched on **and** finished configuring.
     *
     * Both conditions matter: offering a gateway whose credentials are missing
     * sends somebody through package selection and the whole fee breakdown only
     * to fail on the last click (§26.4).
     *
     * @return array<int, string>
     */
    public function available(): array
    {
        $available = [];

        foreach ($this->catalogue() as $entry) {
            if ($entry['is_available']) {
                $available[] = $entry['name'];
            }
        }

        return $available;
    }

    public function isAvailable(string $name): bool
    {
        return in_array($name, $this->available(), true);
    }

    /**
     * Gateways that can take a payment in this currency (D4).
     *
     * A gateway that does not accept the currency is not a gateway this payer
     * can use, whatever else is configured — so it is filtered out before it is
     * ever offered rather than failing at initiation.
     *
     * @return array<int, string>
     */
    public function availableFor(Currency $currency): array
    {
        $available = [];

        foreach ($this->available() as $name) {
            if (in_array($currency, $this->driver($name)->supportedCurrencies(), true)) {
                $available[] = $name;
            }
        }

        return $available;
    }

    /**
     * Every gateway §26 names, and where each one stands.
     *
     * All eight are reported rather than only the working ones: an administrator
     * asking "why can nobody pay by bKash" needs to see that it exists and what
     * is missing, not an empty list.
     *
     * **No credential ever appears here.** Only whether the credentials are
     * present, and the *names* of the ones that are not — this feeds an Inertia
     * prop, and a secret in a prop is a secret in the page source (§42).
     *
     * @return array<int, array{
     *     name: string,
     *     label: string,
     *     is_implemented: bool,
     *     is_enabled: bool,
     *     is_configured: bool,
     *     is_available: bool,
     *     is_sandbox: bool|null,
     *     capabilities: array<int, string>,
     *     currencies: array<int, string>,
     *     required_configuration: array<int, string>,
     *     missing_configuration: array<int, string>,
     * }>
     */
    public function catalogue(): array
    {
        /** @var array<string, array<string, mixed>> $gateways */
        $gateways = config('payment.gateways', []);

        $catalogue = [];

        foreach ($gateways as $name => $config) {
            $isImplemented = $this->isImplemented($name);
            $isEnabled = $this->isEnabled($name);

            $driver = $isImplemented ? $this->driver($name) : null;

            $isConfigured = $driver?->isConfigured() ?? false;

            $catalogue[] = [
                'name' => $name,
                'label' => is_string($config['label'] ?? null) ? $config['label'] : $name,
                'is_implemented' => $isImplemented,
                'is_enabled' => $isEnabled,
                'is_configured' => $isConfigured,
                'is_available' => $isImplemented && $isEnabled && $isConfigured,
                'is_sandbox' => $driver?->isSandbox(),

                /*
                 * What the provider actually does, declared rather than assumed
                 * (§26.4). The screen renders a refund control from this, so a
                 * provider with no refund endpoint offers no button to press.
                 */
                'capabilities' => array_map(
                    fn (GatewayCapability $capability) => $capability->value,
                    $driver?->capabilities() ?? [],
                ),
                'currencies' => array_map(
                    fn (Currency $currency) => $currency->value,
                    $driver?->supportedCurrencies() ?? [],
                ),

                // Field names, never values. "Incomplete" that does not say what
                // is incomplete leaves somebody guessing which of six fields
                // they missed.
                'required_configuration' => $driver?->requiredConfiguration() ?? [],
                'missing_configuration' => $driver?->missingConfiguration() ?? [],
            ];
        }

        return $catalogue;
    }
}
