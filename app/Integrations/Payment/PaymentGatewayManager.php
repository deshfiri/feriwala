<?php

namespace App\Integrations\Payment;

use App\Domain\Billing\ExchangeRates;
use App\Domain\Settings\SettingsRepository;
use App\Integrations\Payment\Contracts\PaymentGateway;
use App\Integrations\Payment\Data\GatewayCapability;
use App\Integrations\Payment\Exceptions\GatewayUnavailable;
use App\Support\Money\Currency;
use App\Support\Money\Money;
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
        protected ExchangeRates $rates,
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
     * A gateway that cannot take the currency, and has no exchange rate set to
     * take it in another, is not a gateway this payer can use — so it is
     * filtered out before it is ever offered rather than failing at initiation.
     *
     * @return array<int, string>
     */
    public function availableFor(Currency $currency): array
    {
        $available = [];

        foreach ($this->available() as $name) {
            if ($this->chargeCurrencyFor($name, $currency) !== null) {
                $available[] = $name;
            }
        }

        return $available;
    }

    /**
     * The currency a gateway would actually be asked for, for a payment in this
     * one — or null when it cannot take the payment at all (D4, amended).
     *
     * The payment's own currency when the gateway accepts it. Otherwise, for a
     * Taka payment only, the first currency the gateway accepts that an
     * administrator has set a rate for. Nothing is ever converted without a rate
     * somebody entered, and a payment already in a foreign currency is never
     * converted into another.
     */
    public function chargeCurrencyFor(string $gateway, Currency $paymentCurrency): ?Currency
    {
        $supported = $this->driver($gateway)->supportedCurrencies();

        if (in_array($paymentCurrency, $supported, true)) {
            return $paymentCurrency;
        }

        if ($paymentCurrency !== Currency::base()) {
            return null;
        }

        foreach ($supported as $currency) {
            if ($this->rates->hasRate($currency)) {
                return $currency;
            }
        }

        return null;
    }

    /**
     * What a payer should be told before choosing a gateway that converts.
     *
     * Null when the gateway takes the payment in its own currency, so there is
     * nothing to say. Otherwise the currency they will be charged in, the rate
     * (Taka per unit) and — when the amount is known — the figure itself.
     *
     * @return array{currency: string, rate: string, charge: array<string, mixed>|null}|null
     */
    public function conversionNotice(string $gateway, Currency $paymentCurrency, ?Money $amount = null): ?array
    {
        $charge = $this->chargeCurrencyFor($gateway, $paymentCurrency);

        if ($charge === null || $charge === $paymentCurrency) {
            return null;
        }

        $rate = $this->rates->rateFor($charge);

        if ($rate === null) {
            return null;
        }

        return [
            'currency' => $charge->value,
            'rate' => $rate,
            'charge' => $amount !== null && $amount->currency === $paymentCurrency
                ? $this->rates->chargeFor($amount, $charge, $rate)->jsonSerialize()
                : null,
        ];
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
     *     is_operational: bool,
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

            /*
             * A driver that cannot confirm a payment with its provider cannot
             * take one (§26.4). That is the state EPS and Nagad rest in: a
             * class is wired up and their credentials have somewhere to live,
             * but their protocol could not be confirmed, so they declare
             * nothing and can do nothing.
             *
             * Checked here as well as when enabling, because this is what the
             * checkout reads. "Switched on" is a decision somebody made once;
             * this is whether the gateway can actually work today.
             */
            $canVerify = $driver?->supports(GatewayCapability::Verify) ?? false;

            $catalogue[] = [
                'name' => $name,
                'label' => is_string($config['label'] ?? null) ? $config['label'] : $name,
                'is_implemented' => $isImplemented,
                'is_operational' => $canVerify,
                'is_enabled' => $isEnabled,
                'is_configured' => $isConfigured,
                'is_available' => $isImplemented && $canVerify && $isEnabled && $isConfigured,
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
