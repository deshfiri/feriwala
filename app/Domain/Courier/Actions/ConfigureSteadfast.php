<?php

namespace App\Domain\Courier\Actions;

use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Courier\Enums\CourierProviderCode;
use App\Domain\Courier\Models\CourierProvider;
use App\Domain\Notification\Actions\ConfigureSmsCredentials;
use App\Domain\Settings\Enums\SettingType;
use App\Domain\Settings\SettingsRepository;
use App\Integrations\Courier\CourierManager;
use App\Integrations\Courier\Steadfast\SteadfastCredentials;
use App\Models\User;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;

/**
 * Stores Steadfast's credentials and switches the provider on or off.
 *
 * Mirrors {@see ConfigureSmsCredentials}: the
 * settings table holds the keys encrypted, a blank field means "leave this
 * alone" rather than "clear it", and nothing ever reads a stored secret back
 * out to a screen. The audit row names the fields that changed, never a value.
 *
 * "Enabled" is the seeded `courier_providers.is_enabled` row, because that is
 * what {@see CourierManager::isEnabled()} already
 * reads. A provider cannot be switched on while anything it needs is missing.
 */
class ConfigureSteadfast
{
    public function __construct(
        protected DatabaseManager $database,
        protected SettingsRepository $settings,
        protected SteadfastCredentials $credentials,
        protected RecordAuditLog $audit,
    ) {}

    /**
     * @param  array{api_key?: string|null, secret_key?: string|null}  $secrets
     *
     * @throws InvalidArgumentException when the base address is not permitted, or the
     *                                  provider is switched on while incomplete
     */
    public function handle(User $actor, bool $enabled, ?string $baseUrl, array $secrets): void
    {
        $baseUrl = $baseUrl === null ? null : rtrim(trim($baseUrl), '/');

        if ($baseUrl !== null && $baseUrl !== '' && ! SteadfastCredentials::isPermittedBaseUrl($baseUrl)) {
            throw new InvalidArgumentException(__('steadfast.base_url_refused'));
        }

        // Checked before anything is written, so a refusal leaves nothing half-saved
        // (and no stale cached setting behind a rolled-back write).
        if ($enabled && $this->wouldBeMissing($baseUrl, $secrets) !== []) {
            throw new InvalidArgumentException(__('steadfast.cannot_enable_incomplete'));
        }

        $this->database->transaction(function () use ($actor, $enabled, $baseUrl, $secrets): void {
            /** @var CourierProvider $provider */
            $provider = CourierProvider::query()
                ->where('code', CourierProviderCode::Steadfast->value)
                ->lockForUpdate()
                ->firstOrFail();

            $changed = [];

            foreach (SteadfastCredentials::SECRETS as $field => $setting) {
                $value = $secrets[$field] ?? null;

                if (! is_string($value) || trim($value) === '') {
                    continue;
                }

                $this->settings->define($setting, 'courier', SettingType::String, isEncrypted: true, label: 'Steadfast '.str_replace('_', ' ', $field));
                $this->settings->set($setting, trim($value), $actor->id);

                $changed[] = $field;
            }

            if ($baseUrl !== null && $baseUrl !== '') {
                $this->settings->define(SteadfastCredentials::BASE_URL, 'courier', SettingType::String, label: 'Steadfast base URL');
                $this->settings->set(SteadfastCredentials::BASE_URL, $baseUrl, $actor->id);

                $changed[] = 'base_url';
            }

            $before = $provider->is_enabled;

            $provider->forceFill(['is_enabled' => $enabled])->save();

            $this->audit->handle(new AuditEntry(
                action: 'courier.steadfast_configured',
                actorId: $actor->id,
                auditableType: CourierProvider::class,
                auditableId: $provider->id,
                before: ['enabled' => $before],
                // Field names and the host only. Never a key, a secret or a token.
                after: [
                    'enabled' => $enabled,
                    'fields_set' => $changed,
                    'host' => parse_url((string) $this->credentials->baseUrl(), PHP_URL_HOST),
                ],
                module: 'courier',
                isSensitive: true,
            ));
        });
    }

    /**
     * What would still be missing once the posted values are saved.
     *
     * @param  array{api_key?: string|null, secret_key?: string|null}  $secrets
     * @return array<int, string>
     */
    protected function wouldBeMissing(?string $baseUrl, array $secrets): array
    {
        $posted = fn (string $field): bool => is_string($secrets[$field] ?? null) && trim($secrets[$field]) !== '';

        $missing = [
            'api_key' => ! $posted('api_key') && $this->credentials->apiKey() === null,
            'secret_key' => ! $posted('secret_key') && $this->credentials->secretKey() === null,
            // A posted address was already checked; a stored one is checked on read.
            'base_url' => ($baseUrl === null || $baseUrl === '') && $this->credentials->baseUrl() === null,
        ];

        return array_keys(array_filter($missing));
    }
}
