<?php

namespace App\Domain\Settings;

use App\Domain\Settings\Enums\SettingType;
use App\Domain\Settings\Models\Setting;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Reads and writes settings, with a Redis cache in front.
 *
 * Settings are read constantly — a fee on every checkout, a threshold on every
 * balance check — and change rarely. Caching the whole table as one entry means
 * a request that touches ten settings makes zero queries rather than ten.
 *
 * The cache is cleared on every write. Deliberately the entire entry, not one
 * key: a partial invalidation that misses a case leaves a stale fee in
 * circulation, and stale money configuration is far more expensive than a cache
 * miss.
 */
class SettingsRepository
{
    public const CACHE_KEY = 'feriwala:settings';

    /**
     * @var array<string, mixed>|null
     */
    protected ?array $resolved = null;

    public function __construct(
        protected Cache $cache,
    ) {}

    /**
     * Read a setting, falling back when it has not been configured.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $all = $this->all();

        return array_key_exists($key, $all) ? $all[$key] : $default;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->all());
    }

    /**
     * Every setting, keyed by name.
     *
     * Held for the request as well as in the cache, so repeated reads inside one
     * request do not repeatedly hit Redis either.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        /** @var array<string, mixed> $values */
        $values = $this->cache->rememberForever(
            self::CACHE_KEY,
            fn () => Setting::query()
                ->get()
                ->mapWithKeys(fn (Setting $setting) => [
                    $setting->key => $setting->typedValue(),
                ])
                ->all(),
        );

        return $this->resolved = $values;
    }

    /**
     * Write a setting and invalidate the cache.
     *
     * The setting must already exist — its type, group, and whether it is
     * encrypted are part of the schema, not something a caller invents at write
     * time. Use {@see define()} to introduce one.
     */
    public function set(string $key, mixed $value, ?int $updatedBy = null): Setting
    {
        $setting = Setting::query()->where('key', $key)->firstOrFail();

        $serialised = $setting->type->serialise($value);

        $setting->forceFill([
            'value' => $serialised !== null && $setting->is_encrypted
                ? encrypt($serialised)
                : $serialised,
            'updated_by' => $updatedBy,
        ])->save();

        $this->flush();

        return $setting;
    }

    /**
     * Introduce a setting, or update its definition without touching its value.
     */
    public function define(
        string $key,
        string $group,
        SettingType $type,
        mixed $default = null,
        bool $isEncrypted = false,
        bool $isPublic = false,
        ?string $label = null,
        ?string $description = null,
    ): Setting {
        $existing = Setting::query()->where('key', $key)->first();

        $attributes = [
            'group' => $group,
            'type' => $type,
            'is_encrypted' => $isEncrypted,
            'is_public' => $isPublic,
            'label' => $label,
            'description' => $description,
        ];

        if ($existing !== null) {
            // Redefining must never silently reset a configured value.
            $existing->forceFill($attributes)->save();
            $this->flush();

            return $existing;
        }

        $serialised = $type->serialise($default);

        $setting = Setting::query()->create([
            'key' => $key,
            ...$attributes,
            'value' => $serialised !== null && $isEncrypted
                ? encrypt($serialised)
                : $serialised,
        ]);

        $this->flush();

        return $setting;
    }

    /**
     * Settings a partner is allowed to read.
     *
     * @return array<string, mixed>
     */
    public function publicValues(): array
    {
        return Setting::query()
            ->public()
            ->get()
            ->mapWithKeys(fn (Setting $setting) => [
                $setting->key => $setting->typedValue(),
            ])
            ->all();
    }

    public function flush(): void
    {
        $this->resolved = null;
        $this->cache->forget(self::CACHE_KEY);
    }
}
