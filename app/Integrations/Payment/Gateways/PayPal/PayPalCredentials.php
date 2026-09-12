<?php

namespace App\Integrations\Payment\Gateways\PayPal;

use App\Integrations\Payment\Gateways\GatewayCredentials;

/**
 * PayPal credentials (§26.4, §36).
 *
 * A client id and secret exchanged for an access token, plus the id of the
 * webhook this application listens on — which is not a secret but *is* required,
 * because PayPal's verification call asks which webhook a notification claims to
 * be for. Without it nothing can be verified at all, so it belongs with the
 * things whose absence makes this gateway incomplete.
 */
class PayPalCredentials extends GatewayCredentials
{
    public function gateway(): string
    {
        return 'paypal';
    }

    /**
     * @return array<int, string>
     */
    public function requiredKeys(): array
    {
        return ['client_id', 'client_secret', 'webhook_id'];
    }

    public function clientId(): string
    {
        return $this->require('client_id');
    }

    public function clientSecret(): string
    {
        return $this->require('client_secret');
    }

    public function webhookId(): string
    {
        return $this->require('webhook_id');
    }
}
