<?php

namespace App\Integrations\Payment\Gateways\Nagad;

use App\Integrations\Payment\Gateways\GatewayCredentials;

/**
 * Nagad credentials (§26.4, §36).
 *
 * Nagad's integration is built on RSA key exchange: a merchant registers, is
 * given a merchant id and Nagad's own public key, and signs sensitive data with
 * a private key of their own. Those are the four things a merchant ends up
 * holding, and they are what this stores.
 *
 * Both keys are held encrypted like every other credential. A merchant private
 * key is the single most dangerous thing in this table — it is what proves a
 * request came from Feriwala — so it is stored under the same write-only,
 * never-returned rules as a password, not as configuration.
 *
 * See {@see NagadGateway} for why this stores keys and does nothing with them.
 */
class NagadCredentials extends GatewayCredentials
{
    public function gateway(): string
    {
        return 'nagad';
    }

    /**
     * @return array<int, string>
     */
    public function requiredKeys(): array
    {
        return ['merchant_id', 'merchant_number', 'merchant_private_key', 'nagad_public_key'];
    }
}
