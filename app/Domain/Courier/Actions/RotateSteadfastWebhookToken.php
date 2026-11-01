<?php

namespace App\Domain\Courier\Actions;

use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Settings\Enums\SettingType;
use App\Domain\Settings\SettingsRepository;
use App\Integrations\Courier\Steadfast\SteadfastCredentials;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Generates the Bearer token Steadfast must present to Banij's callback.
 *
 * Steadfast's callback setting wants a "Callback Url" and an "Auth Token
 * (Bearer)" (package README, §5) -- the merchant chooses the token, so Banij
 * generates it. Rotating replaces the old one at once.
 *
 * The one place a secret is handed to a screen: the new token is returned so it
 * can be shown **once**, because staff must paste it into Steadfast's merchant
 * panel. It is stored encrypted, is never readable afterwards, and never
 * reaches an audit row.
 */
class RotateSteadfastWebhookToken
{
    public function __construct(
        protected SettingsRepository $settings,
        protected RecordAuditLog $audit,
    ) {}

    public function handle(User $actor): string
    {
        $token = Str::random(48);

        $this->settings->define(
            SteadfastCredentials::WEBHOOK_TOKEN,
            'courier',
            SettingType::String,
            isEncrypted: true,
            label: 'Steadfast webhook token',
        );

        $this->settings->set(SteadfastCredentials::WEBHOOK_TOKEN, $token, $actor->id);

        $this->audit->handle(new AuditEntry(
            action: 'courier.steadfast_webhook_token_rotated',
            actorId: $actor->id,
            module: 'courier',
            isSensitive: true,
        ));

        return $token;
    }
}
