<?php

namespace App\Integrations\Payment\Gateways;

use App\Domain\Settings\Enums\SettingType;
use App\Domain\Settings\SettingsRepository;
use LogicException;

/**
 * A provider's credentials for one frozen Partner configuration version (D36).
 *
 * Every driver reads its credentials through {@see GatewayCredentials}, which
 * asks a {@see SettingsRepository} for `payment.{gateway}.mode` and
 * `payment.{gateway}.{mode}.{key}`. This answers those same questions from the
 * values of a single version, so a driver needs no change to be pointed at a
 * Partner's account instead of Feriwala's.
 *
 * It never reads the settings table, never touches the cache, and cannot write
 * either. The values live only for as long as the driver built on them: there is
 * no cached copy of a decrypted Partner secret anywhere.
 */
final class ScopedGatewaySettings extends SettingsRepository
{
    /** @var array<string, mixed> */
    private array $values;

    /**
     * @param  array<string, string>  $credentials  keyed by the driver's own credential keys
     */
    public function __construct(string $gateway, string $mode, array $credentials)
    {
        $values = ["payment.{$gateway}.mode" => $mode];

        foreach ($credentials as $key => $value) {
            $values["payment.{$gateway}.{$mode}.{$key}"] = $value;
        }

        $this->values = $values;
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->values;
    }

    /**
     * @return array<string, mixed>
     */
    public function publicValues(): array
    {
        return [];
    }

    public function set(string $key, mixed $value, ?int $updatedBy = null): never
    {
        throw new LogicException('A Partner gateway version is immutable; save a new version instead.');
    }

    public function define(
        string $key,
        string $group,
        SettingType $type,
        mixed $default = null,
        bool $isEncrypted = false,
        bool $isPublic = false,
        ?string $label = null,
        ?string $description = null,
    ): never {
        throw new LogicException('A Partner gateway version holds no settings definitions.');
    }

    public function flush(): void {}
}
