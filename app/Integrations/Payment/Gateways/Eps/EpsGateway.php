<?php

namespace App\Integrations\Payment\Gateways\Eps;

use App\Integrations\Payment\Data\GatewayCapability;
use App\Integrations\Payment\Data\GatewayRedirect;
use App\Integrations\Payment\Data\GatewayResult;
use App\Integrations\Payment\Data\PaymentIntent;
use App\Integrations\Payment\Exceptions\GatewayCapabilityMissing;
use App\Integrations\Payment\Gateways\Gateway;
use App\Integrations\Payment\Gateways\GatewayCredentials;
use Illuminate\Http\Request;

/**
 * EPS — Easy Payment System (§26, D7).
 *
 * **This driver declares no capabilities and does nothing.** That is a decision,
 * not an unfinished job.
 *
 * EPS's protocol documentation is issued to merchants on request
 * (integration@eps.com.bd); it is not published. Their own SDK repositories,
 * linked from eps.com.bd, describe the shape of an integration — a JWT obtained
 * from credentials, an HMAC-SHA512 hash over request data, an initialise call
 * returning a redirect URL, a verification call by either transaction id — but
 * they do not state the endpoint paths, the request and response schemas, or,
 * critically, how the HMAC's signed string is composed.
 *
 * A signature is not something to infer. Getting the canonical string wrong
 * produces a driver that either cannot authenticate at all or, far worse,
 * accepts notifications it should not. Neither failure is one to discover with
 * somebody's money in the middle of it.
 *
 * So everything here refuses, and config ships it disabled. It cannot be
 * switched on either: enabling requires the ability to verify with the provider,
 * which this cannot do. What exists is the part that is safe to have without the
 * protocol — a place for the credentials EPS issues, and an honest entry on the
 * gateway screen saying the driver is not built.
 *
 * To finish it: obtain the EPS integration guide from EPS, then implement
 * against that document. Nothing here should be completed from an SDK's
 * behaviour or a third-party package.
 */
class EpsGateway extends Gateway
{
    public function __construct(
        protected EpsCredentials $credentials,
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
