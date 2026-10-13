<?php

namespace App\Integrations\Payment\Contracts;

use App\Integrations\Payment\Exceptions\GatewayUnavailable;

/**
 * A gateway that can prove its stored credentials work without moving money.
 */
interface TestsConnection
{
    /**
     * Authenticate against the provider and discard the result.
     *
     * @throws GatewayUnavailable with a message that is safe to show an administrator
     */
    public function testConnection(): void;
}
