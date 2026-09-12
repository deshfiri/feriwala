<?php

namespace App\Integrations\Payment\Gateways\Stripe;

use App\Integrations\Payment\Gateways\GatewayCredentials;

/**
 * Stripe credentials (§26.4, §36).
 *
 * Two secrets that are not interchangeable. The secret key authenticates calls
 * *to* Stripe; the webhook signing secret verifies calls *from* Stripe, is
 * issued per endpoint, and starts `whsec_`. Using one where the other belongs
 * fails in a way that is easy to misread, so they are held separately and named
 * for what they do.
 *
 * Stripe has no separate sandbox host: the key decides which environment a call
 * reaches. That makes the usual "sandbox credentials must not operate against
 * live endpoints" rule sharper here rather than looser, which is why
 * {@see StripeGateway::misconfiguredMode()} checks the key's own prefix against
 * the mode it is stored under.
 */
class StripeCredentials extends GatewayCredentials
{
    /** The prefix on a key that reaches Stripe's test environment. */
    public const TEST_KEY_PREFIX = 'sk_test_';

    /** The prefix on a key that moves real money. */
    public const LIVE_KEY_PREFIX = 'sk_live_';

    public function gateway(): string
    {
        return 'stripe';
    }

    /**
     * @return array<int, string>
     */
    public function requiredKeys(): array
    {
        return ['secret_key', 'webhook_secret'];
    }

    public function secretKey(): string
    {
        return $this->require('secret_key');
    }

    public function webhookSecret(): string
    {
        return $this->require('webhook_secret');
    }
}
