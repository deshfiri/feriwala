<?php

namespace App\Integrations\Payment\Gateways\Eps;

use App\Integrations\Payment\Gateways\GatewayCredentials;

/**
 * EPS credentials (§26.4, §36).
 *
 * The field names come from EPS's own official SDK
 * (github.com/EPS-PG/EPS_Laravel, `config/epsPayment.php`, read 2026-09-29):
 * `merchant_id`, `store_id`, `username`, `password` and `hash_key` are what a
 * merchant is issued, and `device_type_id` (`EPSDeviceTypeID`) is a further
 * value the SDK's own `InitializeEPS` request requires but never explains —
 * ask EPS what value a web merchant should send.
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
        return ['merchant_id', 'store_id', 'username', 'password', 'hash_key', 'device_type_id'];
    }

    public function merchantId(): string
    {
        return $this->require('merchant_id');
    }

    public function storeId(): string
    {
        return $this->require('store_id');
    }

    public function username(): string
    {
        return $this->require('username');
    }

    public function password(): string
    {
        return $this->require('password');
    }

    public function hashKey(): string
    {
        return $this->require('hash_key');
    }

    public function deviceTypeId(): string
    {
        return $this->require('device_type_id');
    }
}
