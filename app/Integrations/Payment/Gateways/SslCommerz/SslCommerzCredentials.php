<?php

namespace App\Integrations\Payment\Gateways\SslCommerz;

use App\Integrations\Payment\Gateways\GatewayCredentials;

/**
 * SSLCommerz credentials, read from encrypted settings (§26.4, §36, D7).
 *
 * A store id and a store password, and nothing else: SSLCommerz authenticates
 * every call — session creation, validation, refund, transaction query — with
 * the same pair, so there is no separate API key to hold.
 */
class SslCommerzCredentials extends GatewayCredentials
{
    public function gateway(): string
    {
        return 'sslcommerz';
    }

    /**
     * @return array<int, string>
     */
    public function requiredKeys(): array
    {
        return ['store_id', 'store_password'];
    }

    public function storeId(): string
    {
        return $this->require('store_id');
    }

    public function storePassword(): string
    {
        return $this->require('store_password');
    }
}
