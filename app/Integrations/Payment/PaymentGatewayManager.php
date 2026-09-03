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
     * sends the user to a checkout that fails at the last step (§26.4).
     *
     * @return array<int, string>
     */
    public function available(): array
    {
        /** @var array<string, array<string, mixed>> $gateways */
        $gateways = config('payment.gateways', []);

        $available = [];

        foreach ($gateways as $name => $config) {
            if (($config['enabled'] ?? false) !== true) {
                continue;
            }

            if (! is_string($config['driver'] ?? null)) {
                continue;
            }

            $available[] = $name;
        }

        return $available;
    }

    public function isAvailable(string $name): bool
    {
        return in_array($name, $this->available(), true);
    }
}
