<?php

namespace App\Integrations\Payment\Gateways\AmarPay;

use App\Integrations\Payment\Gateways\GatewayCredentials;

/**
 * aamarPay credentials (§26.4, §36).
 *
 * A store id and a signature key. The signature key is not a webhook secret —
 * aamarPay sends it as a request parameter on every call, which is why it is
 * held encrypted like a password rather than treated as a public identifier.
 */
class AmarPayCredentials extends GatewayCredentials
{
    public function gateway(): string
    {
        return 'amarpay';
    }

    /**
     * @return array<int, string>
     */
    public function requiredKeys(): array
    {
        return ['store_id', 'signature_key'];
    }

    public function storeId(): string
    {
        return $this->require('store_id');
    }

    public function signatureKey(): string
    {
        return $this->require('signature_key');
    }
}
