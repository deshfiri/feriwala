<?php

namespace App\Domain\Billing\Actions;

use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Settings\Enums\SettingType;
use App\Domain\Settings\SettingsRepository;
use App\Integrations\Payment\Gateways\SslCommerz\SslCommerzCredentials;
use App\Models\User;
use InvalidArgumentException;

/**
 * Stores a gateway's credentials (§26.4, §36, D7).
 *
 * The credentials go into the settings table **encrypted**, under separate
 * sandbox and live keys, so switching mode cannot pick up the wrong pair — and
 * so a live merchant account can never take a test transaction.
 *
 * Write-only by design. Nothing reads a stored secret back out to a screen: the
 * settings form reports whether a credential is *present*, never what it is. A
 * secret rendered into an Inertia prop is a secret in the page source, in the
 * browser's history, and in any error report that captures it (§42).
 *
 * A blank field therefore means "leave this alone", not "clear it". Otherwise
 * an administrator changing the mode would silently wipe the credentials they
 * could not see.
 */
class ConfigureGateway
{
    /** The mode names the credential keys are nested under. */
    public const MODES = ['sandbox', 'live'];

    public function __construct(
        protected SettingsRepository $settings,
        protected RecordAuditLog $audit,
    ) {}

    /**
     * @param  array<string, string|null>  $credentials  keyed `store_id`, `store_password`
     */
    public function sslCommerz(User $actor, string $mode, array $credentials): void
    {
        if (! in_array($mode, self::MODES, true)) {
            throw new InvalidArgumentException('A gateway is either in sandbox or live mode.');
        }

        $this->settings->define(
            'payment.sslcommerz.mode',
            'payment',
            SettingType::String,
            SslCommerzCredentials::SANDBOX_MODE,
            label: 'SSLCommerz mode',
        );

        $this->settings->set('payment.sslcommerz.mode', $mode, $actor->id);

        $written = [];

        foreach (['store_id', 'store_password'] as $key) {
            $value = $credentials[$key] ?? null;

            // Blank means "leave it", not "clear it" — the form cannot show what
            // is already there, so it cannot ask to keep it either.
            if (! is_string($value) || trim($value) === '') {
                continue;
            }

            $setting = "payment.sslcommerz.{$mode}.{$key}";

            $this->settings->define(
                $setting,
                'payment',
                SettingType::String,
                isEncrypted: true,
                label: 'SSLCommerz '.str_replace('_', ' ', $key),
            );

            $this->settings->set($setting, trim($value), $actor->id);

            $written[] = $key;
        }

        $this->audit->handle(new AuditEntry(
            action: 'payment.gateway_configured',
            actorId: $actor->id,
            // The names of the fields that changed, never their values. An audit
            // log that records a store password is a second place it leaks from.
            after: ['gateway' => 'sslcommerz', 'mode' => $mode, 'credentials_set' => $written],
            module: 'payment',
            isSensitive: true,
        ));
    }
}
