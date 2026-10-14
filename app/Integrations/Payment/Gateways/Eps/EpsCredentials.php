<?php

namespace App\Integrations\Payment\Gateways\Eps;

use App\Integrations\Payment\Exceptions\GatewayUnavailable;
use App\Integrations\Payment\Gateways\GatewayCredentials;

/**
 * EPS credentials (§26.4, §36).
 *
 * The EPS Merchant API Integration Guide (v0.5) documents one host,
 * `https://pgapi.eps.com.bd`, and no sandbox. `base_url` is therefore stored
 * per mode and nothing is assumed for a mode that has none: a mode with no
 * explicit official address is simply not configured. Live may only be the
 * documented host; sandbox may be whatever EPS issued, provided it is a plain
 * `https://….eps.com.bd` origin.
 */
class EpsCredentials extends GatewayCredentials
{
    public const OFFICIAL_LIVE_BASE_URL = 'https://pgapi.eps.com.bd';

    public function gateway(): string
    {
        return 'eps';
    }

    /**
     * @return array<int, string>
     */
    public function requiredKeys(): array
    {
        return ['base_url', 'merchant_id', 'store_id', 'username', 'password', 'hash_key'];
    }

    /**
     * The origin every EPS path is appended to — validated, never trusted.
     *
     * @throws GatewayUnavailable when it is not an official EPS origin for the mode
     */
    public function baseUrl(): string
    {
        $url = rtrim(trim($this->require('base_url')), '/');
        $parts = parse_url($url);

        $host = is_array($parts) ? mb_strtolower((string) ($parts['host'] ?? '')) : '';
        $isPlainOrigin = is_array($parts)
            && ($parts['scheme'] ?? null) === 'https'
            && $host !== ''
            && ! isset($parts['user'], $parts['pass'], $parts['port'], $parts['path'], $parts['query'], $parts['fragment']);

        $isOfficial = $this->isSandbox()
            ? str_ends_with($host, '.eps.com.bd')
            : $url === self::OFFICIAL_LIVE_BASE_URL;

        if (! $isPlainOrigin || ! $isOfficial) {
            throw GatewayUnavailable::forGateway(
                'eps',
                $this->isSandbox()
                    ? 'the sandbox base URL must be an https://….eps.com.bd origin issued by EPS'
                    : 'the live base URL must be exactly '.self::OFFICIAL_LIVE_BASE_URL,
            );
        }

        return $url;
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
}
