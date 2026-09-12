<?php

namespace App\Integrations\Payment\Gateways\Bkash;

use App\Integrations\Payment\Gateways\GatewayCredentials;

/**
 * bKash credentials (§26.4, §36).
 *
 * Four secrets and a base URL. The username and password go in headers on the
 * token call; the app key and app secret go in its body; and the resulting
 * token authenticates everything else.
 *
 * `base_url` is configuration rather than a constant because bKash's own
 * documentation says so: the base URL is shared with each merchant during
 * onboarding, and the published pattern only gives its shape. Pinning a guess
 * here would be pointing a live merchant account at a host nobody confirmed.
 */
class BkashCredentials extends GatewayCredentials
{
    public function gateway(): string
    {
        return 'bkash';
    }

    /**
     * @return array<int, string>
     */
    public function requiredKeys(): array
    {
        return ['base_url', 'app_key', 'app_secret', 'username', 'password'];
    }

    public function baseUrl(): string
    {
        return rtrim($this->require('base_url'), '/');
    }

    public function appKey(): string
    {
        return $this->require('app_key');
    }

    public function appSecret(): string
    {
        return $this->require('app_secret');
    }

    public function username(): string
    {
        return $this->require('username');
    }

    public function password(): string
    {
        return $this->require('password');
    }
}
