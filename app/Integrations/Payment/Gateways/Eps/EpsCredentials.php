<?php

namespace App\Integrations\Payment\Gateways\Eps;

use App\Integrations\Payment\Gateways\GatewayCredentials;

/**
 * EPS credentials (§26.4, §36).
 *
 * The field names come from EPS's own published SDKs — the repositories linked
 * from eps.com.bd's gateway page, which list `username`, `password`, `hashKey`,
 * `merchantId` and `storeId` as what a merchant is issued.
 *
 * Knowing what a merchant holds is not the same as knowing the protocol those
 * credentials authenticate. See {@see EpsGateway} for why this gateway stores
 * configuration but does nothing with it yet.
 */
class EpsCredentials extends GatewayCredentials
{
    public function gateway(): string
    {
        return 'eps';
    }

    /**
     * @return array<int, string>
     */
    public function requiredKeys(): array
    {
        return ['merchant_id', 'store_id', 'username', 'password', 'hash_key'];
    }
}
