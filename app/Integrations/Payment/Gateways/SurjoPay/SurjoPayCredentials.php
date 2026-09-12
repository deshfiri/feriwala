<?php

namespace App\Integrations\Payment\Gateways\SurjoPay;

use App\Integrations\Payment\Gateways\GatewayCredentials;

/**
 * shurjoPay credentials (§26.4, §36).
 *
 * Three things, all issued on onboarding: an API username and password that are
 * exchanged for a token, and a transaction prefix that shurjoPay puts in front
 * of every order id so a merchant's transactions are distinguishable inside
 * their system.
 *
 * The store id is **not** here. It comes back from the token call rather than
 * being configured, so storing it would be keeping a second copy of something
 * the provider already tells us on every authentication.
 */
class SurjoPayCredentials extends GatewayCredentials
{
    public function gateway(): string
    {
        return 'surjopay';
    }

    /**
     * @return array<int, string>
     */
    public function requiredKeys(): array
    {
        return ['username', 'password', 'prefix'];
    }

    public function username(): string
    {
        return $this->require('username');
    }

    public function password(): string
    {
        return $this->require('password');
    }

    public function prefix(): string
    {
        return $this->require('prefix');
    }
}
