<?php

namespace App\Domain\Storage\Actions;

use App\Domain\Access\Data\SensitiveActionRequest;
use App\Domain\Access\Enums\PermissionAction;
use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Access\SensitiveActionGuard;
use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Billing\Actions\ConfigureGateway;
use App\Domain\Settings\Enums\SettingType;
use App\Domain\Settings\SettingsRepository;
use App\Domain\Storage\R2StorageSettings;
use App\Models\User;
use RuntimeException;

/**
 * Stores the Cloudflare R2 credentials and switches R2 on or off
 * (beta-critical batch, Commit 3).
 *
 * Mirrors {@see ConfigureGateway} exactly:
 * secrets go into the settings table encrypted, a blank field means "leave
 * this alone" rather than "clear it", and nothing ever reads a stored secret
 * back out to a screen.
 *
 * One rule this adds on top of that: a save is tested against R2, live,
 * before anything is written. If the test fails, **nothing below this class
 * has touched the settings table** -- the previous working configuration (if
 * any) is exactly as it was, by construction, not by a restore step.
 */
class ConfigureR2Storage
{
    public function __construct(
        protected SettingsRepository $settings,
        protected R2StorageSettings $current,
        protected TestR2Connection $test,
        protected SensitiveActionGuard $guard,
        protected RecordAuditLog $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $fields
     *
     * @throws RuntimeException when required fields are missing or the live connection test fails
     */
    public function handle(
        User $actor,
        array $fields,
        bool $passwordConfirmed,
        bool $twoFactorEnabled,
        ?string $reason = null,
    ): void {
        $this->guard->authorize($actor, new SensitiveActionRequest(
            module: PermissionModule::Integration,
            action: PermissionAction::ManageIntegrations,
            reason: $reason,
            passwordConfirmed: $passwordConfirmed,
            twoFactorEnabled: $twoFactorEnabled,
        ));

        $candidate = $this->current->candidate($fields);

        foreach (['account_id', 'access_key_id', 'secret_access_key', 'bucket', 'endpoint'] as $required) {
            if (($candidate[$required] ?? null) === null || $candidate[$required] === '') {
                throw new RuntimeException(sprintf(
                    '%s is required before R2 storage can be saved.',
                    ucfirst(str_replace('_', ' ', $required)),
                ));
            }
        }

        $result = $this->test->handle($fields);

        if (! $result->success) {
            throw new RuntimeException("Could not connect to R2 with these settings: {$result->message}");
        }

        $written = $this->writtenFieldNames($fields);

        $this->persist($actor, $candidate);

        $this->audit->handle(new AuditEntry(
            action: 'storage.r2_configured',
            actorId: $actor->id,
            // Field names only, never values -- a secret in an audit log is a
            // second place it leaks from.
            after: ['credentials_set' => $written, 'bucket' => $candidate['bucket'], 'endpoint' => $candidate['endpoint']],
            reason: $reason,
            module: 'storage',
            isSensitive: true,
        ));
    }

    /**
     * Switch R2 on or off. Separate from saving credentials: entering them is
     * preparation, but switching R2 on is the moment real uploads start
     * landing there.
     *
     * @throws RuntimeException when switching on before R2 is fully configured
     */
    public function setEnabled(
        User $actor,
        bool $enabled,
        bool $passwordConfirmed,
        bool $twoFactorEnabled,
        ?string $reason = null,
    ): void {
        $this->guard->authorize($actor, new SensitiveActionRequest(
            module: PermissionModule::Integration,
            action: PermissionAction::ManageIntegrations,
            reason: $reason,
            passwordConfirmed: $passwordConfirmed,
            twoFactorEnabled: $twoFactorEnabled,
        ));

        if ($enabled && ! $this->current->isConfigured()) {
            throw new RuntimeException('R2 storage is not fully configured yet, so it cannot be switched on.');
        }

        $this->settings->define(
            R2StorageSettings::ENABLED,
            'storage',
            SettingType::Boolean,
            false,
            label: 'Cloudflare R2 enabled',
        );

        $this->settings->set(R2StorageSettings::ENABLED, $enabled, $actor->id);

        $this->audit->handle(new AuditEntry(
            action: $enabled ? 'storage.r2_enabled' : 'storage.r2_disabled',
            actorId: $actor->id,
            after: ['enabled' => $enabled],
            reason: $reason,
            module: 'storage',
            isSensitive: true,
        ));
    }

    /**
     * @param  array{account_id: ?string, access_key_id: ?string, secret_access_key: ?string, bucket: ?string, endpoint: ?string, region: string, public_domain: ?string, default_visibility: string, signed_url_expiry_minutes: int}  $candidate
     */
    protected function persist(User $actor, array $candidate): void
    {
        /** @var array<string, array{0: SettingType, 1: mixed, 2: bool, 3: string}> $map */
        $map = [
            R2StorageSettings::ACCOUNT_ID => [SettingType::String, $candidate['account_id'], false, 'R2 account ID'],
            R2StorageSettings::ACCESS_KEY_ID => [SettingType::String, $candidate['access_key_id'], true, 'R2 access key ID'],
            R2StorageSettings::SECRET_ACCESS_KEY => [SettingType::String, $candidate['secret_access_key'], true, 'R2 secret access key'],
            R2StorageSettings::BUCKET => [SettingType::String, $candidate['bucket'], false, 'R2 bucket'],
            R2StorageSettings::ENDPOINT => [SettingType::String, $candidate['endpoint'], false, 'R2 endpoint'],
            R2StorageSettings::REGION => [SettingType::String, $candidate['region'], false, 'R2 region'],
            R2StorageSettings::PUBLIC_DOMAIN => [SettingType::String, $candidate['public_domain'], false, 'R2 public domain'],
            R2StorageSettings::DEFAULT_VISIBILITY => [SettingType::String, $candidate['default_visibility'], false, 'R2 default visibility'],
            R2StorageSettings::SIGNED_URL_EXPIRY_MINUTES => [SettingType::Integer, $candidate['signed_url_expiry_minutes'], false, 'R2 signed URL expiry (minutes)'],
        ];

        foreach ($map as $key => [$type, $value, $encrypted, $label]) {
            $this->settings->define($key, 'storage', $type, isEncrypted: $encrypted, label: $label);
            $this->settings->set($key, $value, $actor->id);
        }
    }

    /**
     * Which fields were actually submitted (non-blank) this time, for the
     * audit entry -- never the kept-from-before ones, and never the values.
     *
     * @param  array<string, mixed>  $fields
     * @return list<string>
     */
    protected function writtenFieldNames(array $fields): array
    {
        $written = [];

        foreach ([
            'account_id', 'access_key_id', 'secret_access_key', 'bucket', 'endpoint',
            'region', 'public_domain', 'default_visibility', 'signed_url_expiry_minutes',
        ] as $key) {
            $value = $fields[$key] ?? null;

            if (is_string($value) && trim($value) !== '') {
                $written[] = $key;
            } elseif (is_int($value)) {
                $written[] = $key;
            }
        }

        return $written;
    }
}
