<?php

namespace App\Domain\Storage;

use App\Domain\Settings\SettingsRepository;
use App\Integrations\Payment\PaymentGatewayManager;
use App\Integrations\Storage\R2Manager;

/**
 * The Cloudflare R2 configuration an administrator enters under Settings ->
 * Storage (beta-critical batch, Commit 3).
 *
 * Read-only and never exposes a secret: {@see accessKeyId()} and {@see
 * secretAccessKey()} exist for {@see R2Manager} to
 * build a disk connection with, never for a controller to hand to an Inertia
 * prop. A controller asks {@see isAccessKeyConfigured()}/{@see
 * isSecretConfigured()} instead -- the same "report presence, never the
 * value" rule {@see PaymentGatewayManager::
 * catalogue()} already holds credentials to.
 */
class R2StorageSettings
{
    public const ENABLED = 'storage.r2.enabled';

    public const ACCOUNT_ID = 'storage.r2.account_id';

    public const ACCESS_KEY_ID = 'storage.r2.access_key_id';

    public const SECRET_ACCESS_KEY = 'storage.r2.secret_access_key';

    public const BUCKET = 'storage.r2.bucket';

    public const ENDPOINT = 'storage.r2.endpoint';

    public const REGION = 'storage.r2.region';

    public const PUBLIC_DOMAIN = 'storage.r2.public_domain';

    public const DEFAULT_VISIBILITY = 'storage.r2.default_visibility';

    public const SIGNED_URL_EXPIRY_MINUTES = 'storage.r2.signed_url_expiry_minutes';

    public const DEFAULT_REGION = 'auto';

    public const DEFAULT_SIGNED_URL_EXPIRY_MINUTES = 15;

    public function __construct(
        protected SettingsRepository $settings,
    ) {}

    public function isEnabled(): bool
    {
        return (bool) $this->settings->get(self::ENABLED, false);
    }

    public function accountId(): ?string
    {
        return $this->stringOrNull($this->settings->get(self::ACCOUNT_ID));
    }

    public function accessKeyId(): ?string
    {
        return $this->stringOrNull($this->settings->get(self::ACCESS_KEY_ID));
    }

    public function secretAccessKey(): ?string
    {
        return $this->stringOrNull($this->settings->get(self::SECRET_ACCESS_KEY));
    }

    public function bucket(): ?string
    {
        return $this->stringOrNull($this->settings->get(self::BUCKET));
    }

    public function endpoint(): ?string
    {
        return $this->stringOrNull($this->settings->get(self::ENDPOINT));
    }

    public function region(): string
    {
        return $this->stringOrNull($this->settings->get(self::REGION)) ?? self::DEFAULT_REGION;
    }

    public function publicDomain(): ?string
    {
        return $this->stringOrNull($this->settings->get(self::PUBLIC_DOMAIN));
    }

    public function defaultVisibility(): string
    {
        $value = $this->stringOrNull($this->settings->get(self::DEFAULT_VISIBILITY));

        return in_array($value, ['public', 'private'], true) ? $value : 'private';
    }

    public function signedUrlExpiryMinutes(): int
    {
        $value = $this->settings->get(self::SIGNED_URL_EXPIRY_MINUTES);

        return is_numeric($value) && (int) $value > 0 ? (int) $value : self::DEFAULT_SIGNED_URL_EXPIRY_MINUTES;
    }

    public function isAccessKeyConfigured(): bool
    {
        return $this->accessKeyId() !== null;
    }

    public function isSecretConfigured(): bool
    {
        return $this->secretAccessKey() !== null;
    }

    /**
     * Whether every field a connection needs is actually present -- distinct
     * from {@see isEnabled()}, the same "configured" vs "switched on"
     * distinction {@see PaymentGatewayManager}
     * already draws for a payment gateway.
     */
    public function isConfigured(): bool
    {
        return $this->accountId() !== null
            && $this->isAccessKeyConfigured()
            && $this->isSecretConfigured()
            && $this->bucket() !== null
            && $this->endpoint() !== null;
    }

    /**
     * Whether this application can actually use R2 today: configured and
     * switched on.
     */
    public function isAvailable(): bool
    {
        return $this->isEnabled() && $this->isConfigured();
    }

    /**
     * Only the last four characters, for a masked display -- e.g.
     * "····wxyz". Never the key itself.
     */
    public function maskedAccessKeyId(): ?string
    {
        return $this->mask($this->accessKeyId());
    }

    /**
     * Submitted form fields merged over what is already stored -- "blank
     * means keep what's there", the same rule {@see
     * \App\Domain\Billing\Actions\ConfigureGateway} applies to a gateway
     * secret: the form cannot show what it cannot read back, so a blank
     * field cannot mean "clear it".
     *
     * Used both by the save action (to decide what to persist) and by the
     * standalone "test connection" action (to try credentials that have not
     * been saved yet), so the merge rule lives in exactly one place.
     *
     * @param  array<string, mixed>  $fields
     * @return array{account_id: ?string, access_key_id: ?string, secret_access_key: ?string, bucket: ?string, endpoint: ?string, region: string, public_domain: ?string, default_visibility: string, signed_url_expiry_minutes: int}
     */
    public function candidate(array $fields): array
    {
        return [
            'account_id' => $this->keepOrReplace($fields['account_id'] ?? null, $this->accountId()),
            'access_key_id' => $this->keepOrReplace($fields['access_key_id'] ?? null, $this->accessKeyId()),
            'secret_access_key' => $this->keepOrReplace($fields['secret_access_key'] ?? null, $this->secretAccessKey()),
            'bucket' => $this->keepOrReplace($fields['bucket'] ?? null, $this->bucket()),
            'endpoint' => $this->keepOrReplace($fields['endpoint'] ?? null, $this->endpoint()),
            'region' => $this->keepOrReplace($fields['region'] ?? null, $this->region()) ?? self::DEFAULT_REGION,
            'public_domain' => $this->keepOrReplace($fields['public_domain'] ?? null, $this->publicDomain()),
            'default_visibility' => $this->keepOrReplace($fields['default_visibility'] ?? null, $this->defaultVisibility()) ?? 'private',
            'signed_url_expiry_minutes' => is_numeric($fields['signed_url_expiry_minutes'] ?? null)
                ? (int) $fields['signed_url_expiry_minutes']
                : $this->signedUrlExpiryMinutes(),
        ];
    }

    protected function keepOrReplace(mixed $incoming, ?string $current): ?string
    {
        if (! is_string($incoming) || trim($incoming) === '') {
            return $current;
        }

        return trim($incoming);
    }

    protected function mask(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $tail = mb_substr($value, -4);

        return str_repeat('•', 4).$tail;
    }

    protected function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
