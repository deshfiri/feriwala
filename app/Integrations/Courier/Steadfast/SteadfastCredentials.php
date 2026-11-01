<?php

namespace App\Integrations\Courier\Steadfast;

use App\Domain\Settings\SettingsRepository;

/**
 * Steadfast's account details, held encrypted in settings like every other
 * provider's (`courier.steadfast.*`).
 *
 * Never in committed config, never in `.env` files, never in code. Reading a
 * missing credential returns null and {@see SteadfastClient} refuses to call --
 * an empty string would send an unauthenticated request and then have to
 * interpret whatever came back.
 *
 * The base address is an administrator-typed value, so it is validated on every
 * read, not only when it is saved: https only, and in production only a host
 * listed in `courier.steadfast.allowed_hosts` (SSRF).
 */
class SteadfastCredentials
{
    public const API_KEY = 'courier.steadfast.api_key';

    public const SECRET_KEY = 'courier.steadfast.secret_key';

    public const WEBHOOK_TOKEN = 'courier.steadfast.webhook_token';

    public const BASE_URL = 'courier.steadfast.base_url';

    public const LAST_TEST_AT = 'courier.steadfast.last_test_at';

    public const LAST_TEST_OK = 'courier.steadfast.last_test_ok';

    /** The encrypted settings, by the field name the screen posts. */
    public const SECRETS = [
        'api_key' => self::API_KEY,
        'secret_key' => self::SECRET_KEY,
    ];

    public function __construct(
        protected SettingsRepository $settings,
    ) {}

    public function apiKey(): ?string
    {
        return $this->read(self::API_KEY);
    }

    public function secretKey(): ?string
    {
        return $this->read(self::SECRET_KEY);
    }

    /**
     * What Steadfast must send in `Authorization: Bearer ...` to our callback.
     */
    public function webhookToken(): ?string
    {
        return $this->read(self::WEBHOOK_TOKEN);
    }

    /**
     * The names of what is still missing, never values. Drives both "is this
     * configured" and the screen's list of what to fill in.
     *
     * @return array<int, string>
     */
    public function missing(): array
    {
        $missing = [];

        if ($this->apiKey() === null) {
            $missing[] = 'api_key';
        }

        if ($this->secretKey() === null) {
            $missing[] = 'secret_key';
        }

        if ($this->baseUrl() === null) {
            $missing[] = 'base_url';
        }

        return $missing;
    }

    public function areConfigured(): bool
    {
        return $this->missing() === [];
    }

    /**
     * The API's base address without a trailing slash: the stored one, or the
     * documented default when none is stored. Null when what is stored is not
     * permitted (see {@see isPermittedBaseUrl()}).
     */
    public function baseUrl(): ?string
    {
        $stored = $this->read(self::BASE_URL);
        $value = rtrim(trim($stored ?? (string) config('courier.steadfast.default_base_url')), '/');

        return self::isPermittedBaseUrl($value) ? $value : null;
    }

    /**
     * Whether an address may carry Steadfast's keys.
     *
     * https always, with no user-info, query or fragment; and in production
     * only an allow-listed host. Outside production any https host is accepted
     * so a mocked host can be used in tests.
     */
    public static function isPermittedBaseUrl(string $value): bool
    {
        $parts = parse_url($value);

        if (! is_array($parts)
            || ($parts['scheme'] ?? null) !== 'https'
            || ($parts['host'] ?? '') === ''
            || isset($parts['user'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || (isset($parts['port']) && $parts['port'] !== 443)) {
            return false;
        }

        if (! app()->isProduction()) {
            return true;
        }

        $allowed = array_map('strtolower', (array) config('courier.steadfast.allowed_hosts', []));

        return in_array(strtolower($parts['host']), $allowed, true);
    }

    protected function read(string $key): ?string
    {
        $value = $this->settings->get($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
