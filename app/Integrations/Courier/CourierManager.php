<?php

namespace App\Integrations\Courier;

use App\Domain\Courier\Enums\CourierProviderCode;
use App\Domain\Courier\Models\CourierProvider as CourierProviderModel;
use App\Integrations\Courier\Contracts\CourierProvider;
use App\Integrations\Payment\PaymentGatewayManager;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * Resolves courier drivers by provider code (§21, D8).
 *
 * Mirrors {@see PaymentGatewayManager}: adding a
 * provider is one class plus a `config('courier.drivers')` entry, and
 * nothing in the shipment domain's own actions knows which providers exist.
 *
 * Unlike Payment, a provider's enabled/disabled state is not a setting here
 * -- it is the seeded `courier_providers.is_enabled` row, since this batch
 * gives administrators no toggle to flip (D8: Steadfast and Pathao have no
 * credentials to switch on). A later batch that adds real credentials for
 * either is the one to decide whether that belongs in Settings instead.
 */
class CourierManager
{
    /** @var array<string, CourierProvider> */
    protected array $resolved = [];

    public function __construct(
        protected Container $container,
    ) {}

    public function driver(CourierProviderCode $code): CourierProvider
    {
        if (isset($this->resolved[$code->value])) {
            return $this->resolved[$code->value];
        }

        if (! $this->isImplemented($code)) {
            throw new InvalidArgumentException("[{$code->value}] has no courier driver implemented yet.");
        }

        /** @var class-string<CourierProvider> $driver */
        $driver = config("courier.drivers.{$code->value}");

        /** @var CourierProvider $instance */
        $instance = $this->container->make($driver);

        return $this->resolved[$code->value] = $instance;
    }

    /**
     * Whether a provider has a driver behind it at all.
     */
    public function isImplemented(CourierProviderCode $code): bool
    {
        return is_string(config("courier.drivers.{$code->value}"));
    }

    /**
     * Whether an administrator has switched this provider on. Read from the
     * seeded row, never hard-coded, so disabling Manual in an emergency needs
     * no deployment.
     */
    public function isEnabled(CourierProviderCode $code): bool
    {
        return (bool) CourierProviderModel::query()
            ->where('code', $code->value)
            ->value('is_enabled');
    }

    /**
     * A provider staff can actually create a shipment with: implemented and
     * switched on.
     */
    public function isAvailable(CourierProviderCode $code): bool
    {
        return $this->isImplemented($code) && $this->isEnabled($code);
    }

    /**
     * Every provider §21 names, implemented or not -- so the UI can name
     * Steadfast and Pathao and say why they are unreachable rather than
     * omit them (D8).
     *
     * @return array<int, array{
     *     code: string,
     *     label: string,
     *     is_implemented: bool,
     *     is_enabled: bool,
     *     is_available: bool,
     * }>
     */
    public function catalogue(): array
    {
        return CourierProviderModel::query()
            ->orderBy('id')
            ->get()
            ->map(function (CourierProviderModel $provider) {
                $code = $provider->code;

                return [
                    'code' => $code->value,
                    'label' => $provider->name,
                    'is_implemented' => $this->isImplemented($code),
                    'is_enabled' => $provider->is_enabled,
                    'is_available' => $this->isAvailable($code),
                ];
            })
            ->all();
    }
}
