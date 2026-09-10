<?php

namespace App\Integrations\Payment;

use App\Integrations\Payment\Contracts\PaymentGateway;
use App\Integrations\Payment\Exceptions\GatewayUnavailable;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * Resolves payment gateways by name (§26, D7).
 *
 * Adding a gateway is one class plus a config entry. Nothing in the payment flow
 * knows which providers exist.
 */
class PaymentGatewayManager
{
    /** @var array<string, PaymentGateway> */
    protected array $resolved = [];

    public function __construct(
        protected Container $container,
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
     * Every gateway §26 names, and where each one stands.
     *
     * All eight are reported rather than only the working ones: an administrator
     * asking "why can nobody pay by bKash" needs to see that it exists and what
     * is missing, not an empty list.
     *
     * **No credential ever appears here.** Only whether the credentials are
     * present — this feeds an Inertia prop, and a secret in a prop is a secret in
     * the page source (§42).
     *
     * @return array<int, array{name: string, label: string, is_implemented: bool, is_enabled: bool, is_configured: bool, is_available: bool, is_sandbox: bool|null}>
     */
    public function catalogue(): array
    {
        /** @var array<string, array<string, mixed>> $gateways */
        $gateways = config('payment.gateways', []);

        $catalogue = [];

        foreach ($gateways as $name => $config) {
            $isImplemented = is_string($config['driver'] ?? null) && $config['driver'] !== '';
            $isEnabled = ($config['enabled'] ?? false) === true;

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
            ];
        }

        return $catalogue;
    }
}
