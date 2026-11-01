<?php

namespace App\Domain\Courier\Actions;

use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Courier\Data\SteadfastConnectionTest;
use App\Domain\Settings\Enums\SettingType;
use App\Domain\Settings\SettingsRepository;
use App\Integrations\Courier\Steadfast\SteadfastClient;
use App\Integrations\Courier\Steadfast\SteadfastCredentials;
use App\Integrations\Courier\Steadfast\SteadfastException;
use App\Models\User;

/**
 * Proves the stored keys work, by asking for the account balance.
 *
 * The balance call is a read: it makes no booking, creates no shipment and no
 * charge. The outcome is remembered (when, and whether it worked) so the
 * screen can say honestly whether this connection has ever been verified; the
 * response itself is not kept.
 */
class TestSteadfastConnection
{
    public function __construct(
        protected SteadfastClient $client,
        protected SettingsRepository $settings,
        protected RecordAuditLog $audit,
    ) {}

    public function handle(User $actor): SteadfastConnectionTest
    {
        try {
            $result = new SteadfastConnectionTest(ok: true, balance: $this->client->balance());
        } catch (SteadfastException $exception) {
            $result = new SteadfastConnectionTest(ok: false, failure: $exception->kind);
        }

        $this->settings->define(SteadfastCredentials::LAST_TEST_AT, 'courier', SettingType::String, label: 'Steadfast last test');
        $this->settings->define(SteadfastCredentials::LAST_TEST_OK, 'courier', SettingType::Boolean, false, label: 'Steadfast last test succeeded');
        $this->settings->set(SteadfastCredentials::LAST_TEST_AT, now()->toIso8601String(), $actor->id);
        $this->settings->set(SteadfastCredentials::LAST_TEST_OK, $result->ok, $actor->id);

        $this->audit->handle(new AuditEntry(
            action: 'courier.steadfast_connection_tested',
            actorId: $actor->id,
            after: ['ok' => $result->ok, 'failure' => $result->failure?->value],
            module: 'courier',
        ));

        return $result;
    }
}
