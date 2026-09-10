<?php

namespace App\Integrations\Sms;

use App\Domain\Settings\SettingsRepository;
use App\Integrations\Sms\Contracts\SmsProvider;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use RuntimeException;

/**
 * Resolves SMS providers by name (§30.1).
 *
 * The same shape as the payment gateway manager, and for the same reasons.
 * Adding a provider is one class plus a config entry; nothing that sends a
 * message knows which providers exist.
 *
 * §30 requires SMS to be switchable off globally, by provider and by event. The
 * global and per-provider switches are here, in **settings** rather than config,
 * so turning off a misbehaving provider at two in the morning is not a deploy.
 * The per-event switch belongs with the notifications and lives beside them.
 */
class SmsProviderManager
{
    /** Which provider messages actually go through. */
    public const PROVIDER_SETTING = 'sms.provider';

    /** The global off switch §30 requires. */
    public const ENABLED_SETTING = 'sms.enabled';

    /** @var array<string, SmsProvider> */
    protected array $resolved = [];

    public function __construct(
        protected Container $container,
        protected SettingsRepository $settings,
    ) {}

    /**
     * The provider in use, or a named one.
     *
     * @throws InvalidArgumentException when the name is not a configured provider
     * @throws RuntimeException when the provider exists but has no driver yet
     */
    public function driver(?string $name = null): SmsProvider
    {
        $name ??= $this->active();

        if (isset($this->resolved[$name])) {
            return $this->resolved[$name];
        }

        /** @var array<string, mixed>|null $config */
        $config = config("sms.providers.{$name}");

        if ($config === null) {
            throw new InvalidArgumentException("[{$name}] is not a configured SMS provider.");
        }

        $driver = $config['driver'] ?? null;

        if (! is_string($driver) || $driver === '') {
            throw new RuntimeException("SMS provider [{$name}] has no driver implemented yet.");
        }

        /** @var SmsProvider $instance */
        $instance = $this->container->make($driver);

        return $this->resolved[$name] = $instance;
    }

    /**
     * Which provider is switched on right now.
     *
     * Settings first, then config: an administrator's choice outranks the
     * deployed default, and a fresh installation still has one.
     */
    public function active(): string
    {
        $chosen = $this->settings->get(self::PROVIDER_SETTING);

        if (is_string($chosen) && $chosen !== '' && $this->isImplemented($chosen)) {
            return $chosen;
        }

        return (string) config('sms.default', 'log');
    }

    /**
     * Whether SMS is switched on at all (§30).
     *
     * The global gate. Everything that sends asks this first, so "stop all SMS"
     * is one switch rather than a hunt through the notification classes.
     */
    public function isEnabled(): bool
    {
        $setting = $this->settings->get(self::ENABLED_SETTING);

        if ($setting !== null) {
            return (bool) $setting;
        }

        return (bool) config('sms.enabled', true);
    }

    /**
     * Whether a message can actually be sent right now.
     */
    public function canSend(): bool
    {
        return $this->isEnabled() && $this->isImplemented($this->active());
    }

    /**
     * Providers with a driver behind them.
     *
     * @return array<int, string>
     */
    public function available(): array
    {
        $available = [];

        foreach ($this->catalogue() as $entry) {
            if ($entry['is_implemented']) {
                $available[] = $entry['name'];
            }
        }

        return $available;
    }

    /**
     * Every provider §30.1 names, and where each one stands.
     *
     * All of them are reported, including the ones with no driver yet: an
     * administrator asking why Twilio is not an option should see that it is
     * known about and not built, rather than an absence.
     *
     * @return array<int, array{name: string, is_implemented: bool, is_active: bool}>
     */
    public function catalogue(): array
    {
        /** @var array<string, array<string, mixed>> $providers */
        $providers = config('sms.providers', []);

        $active = $this->active();

        $catalogue = [];

        foreach ($providers as $name => $config) {
            $catalogue[] = [
                'name' => $name,
                'is_implemented' => is_string($config['driver'] ?? null) && $config['driver'] !== '',
                'is_active' => $name === $active,
            ];
        }

        return $catalogue;
    }

    protected function isImplemented(string $name): bool
    {
        $driver = config("sms.providers.{$name}.driver");

        return is_string($driver) && $driver !== '';
    }
}
