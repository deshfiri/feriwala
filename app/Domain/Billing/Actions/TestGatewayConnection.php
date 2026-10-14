<?php

namespace App\Domain\Billing\Actions;

use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Integrations\Payment\Contracts\TestsConnection;
use App\Integrations\Payment\Exceptions\GatewayUnavailable;
use App\Integrations\Payment\Gateways\GatewayCredentials;
use App\Integrations\Payment\PaymentGatewayManager;
use App\Models\User;
use InvalidArgumentException;

/**
 * Prove a gateway's stored credentials work, with a real authentication call.
 *
 * Moves no money and returns nothing — not the token, not a hash. The audit
 * entry records that somebody tested it and whether it worked; the failure
 * detail is only what the provider itself reported.
 */
class TestGatewayConnection
{
    public function __construct(
        protected PaymentGatewayManager $gateways,
        protected RecordAuditLog $audit,
    ) {}

    /**
     * @throws InvalidArgumentException when the gateway cannot be tested
     * @throws GatewayUnavailable when the provider refused or could not be reached
     */
    public function handle(User $actor, string $gateway): void
    {
        $driver = $this->gateways->isImplemented($gateway) ? $this->gateways->driver($gateway) : null;

        if (! $driver instanceof TestsConnection) {
            throw new InvalidArgumentException('That gateway has no connection test.');
        }

        $mode = $driver->isSandbox() ? GatewayCredentials::SANDBOX : GatewayCredentials::LIVE;

        try {
            $driver->testConnection();
        } catch (GatewayUnavailable $failure) {
            $this->record($actor, $gateway, $mode, 'failed');

            throw $failure;
        }

        $this->record($actor, $gateway, $mode, 'ok');
    }

    protected function record(User $actor, string $gateway, string $mode, string $outcome): void
    {
        $this->audit->handle(new AuditEntry(
            action: 'payment.gateway_connection_tested',
            actorId: $actor->id,
            after: ['gateway' => $gateway, 'mode' => $mode, 'outcome' => $outcome],
            module: 'payment',
            isSensitive: true,
        ));
    }
}
