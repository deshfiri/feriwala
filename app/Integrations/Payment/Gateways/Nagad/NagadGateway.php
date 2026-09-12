<?php

namespace App\Integrations\Payment\Gateways\Nagad;

use App\Integrations\Payment\Data\GatewayCapability;
use App\Integrations\Payment\Data\GatewayRedirect;
use App\Integrations\Payment\Data\GatewayResult;
use App\Integrations\Payment\Data\PaymentIntent;
use App\Integrations\Payment\Exceptions\GatewayCapabilityMissing;
use App\Integrations\Payment\Gateways\Gateway;
use App\Integrations\Payment\Gateways\GatewayCredentials;
use Illuminate\Http\Request;

/**
 * Nagad (§26, D7).
 *
 * **This driver declares no capabilities and does nothing.** Like EPS, that is a
 * decision rather than an unfinished job.
 *
 * Nagad's Merchant API integration guide is issued to registered merchants; it
 * is not published. What is publicly known is the shape: a merchant registers
 * through the portal for a merchant id and Nagad's RSA public key, signs
 * sensitive data with their own private key, encrypts it with Nagad's, and has
 * their server IP whitelisted.
 *
 * That shape is exactly why this cannot be finished from the outside. An RSA
 * signing and encryption scheme has a great many details that all have to be
 * right — which fields are signed, in what order, with what padding, what digest
 * — and none of them are inferable. The unofficial packages that implement it
 * were written by people who had the document; copying from them means trusting
 * somebody else's reading of a specification neither of us can check, on the
 * thing that proves a payment request came from us.
 *
 * So everything here refuses, and config ships it disabled. It cannot be
 * switched on either: enabling requires the ability to verify with the provider.
 * What exists is the part that is safe without the protocol — encrypted storage
 * for the keys Nagad issues, and an honest entry on the gateway screen.
 *
 * To finish it: register as a merchant, obtain the Nagad Merchant API
 * integration guide, and implement against that document.
 */
class NagadGateway extends Gateway
{
    public function __construct(
        protected NagadCredentials $credentials,
    ) {}

    /**
     * Nothing, deliberately.
     *
     * @return array<int, GatewayCapability>
     */
    public function capabilities(): array
    {
        return [];
    }

    /**
     * @throws GatewayCapabilityMissing
     */
    public function initiate(PaymentIntent $intent): GatewayRedirect
    {
        throw GatewayCapabilityMissing::for($this->name(), GatewayCapability::Initiate);
    }

    /**
     * @throws GatewayCapabilityMissing
     */
    public function handleCallback(Request $request): GatewayResult
    {
        throw GatewayCapabilityMissing::for($this->name(), GatewayCapability::Initiate);
    }

    /**
     * Fails closed, like every unverifiable notification.
     */
    public function verifyWebhookSignature(Request $request): bool
    {
        return false;
    }

    /**
     * @throws GatewayCapabilityMissing
     */
    public function verify(string $gatewayReference): GatewayResult
    {
        throw GatewayCapabilityMissing::for($this->name(), GatewayCapability::Verify);
    }

    protected function credentials(): GatewayCredentials
    {
        return $this->credentials;
    }
}
