<?php

namespace App\Integrations\Payment\Gateways;

use App\Integrations\Payment\Contracts\PaymentGateway;
use App\Integrations\Payment\Data\GatewayCapability;
use App\Integrations\Payment\Data\GatewayResult;
use App\Integrations\Payment\Data\RefundIntent;
use App\Integrations\Payment\Data\RefundResult;
use App\Integrations\Payment\Exceptions\GatewayCapabilityMissing;
use App\Integrations\Payment\Exceptions\GatewayUnavailable;
use App\Support\Money\Currency;

/**
 * What every driver gets for free, and what it must still answer itself.
 *
 * The useful part is what this **refuses**. A provider with no refund endpoint
 * inherits a `refund()` that throws rather than one that quietly does nothing or
 * invents a request — because the alternative to refusing is guessing at
 * somebody else's protocol, and a guess about a refund is a guess about real
 * money.
 *
 * The same applies to a driver that has been written but whose protocol could
 * not be confirmed against the provider's own documentation: it declares no
 * capabilities, so every operation refuses, and it is disabled in config. That
 * is a deliberate state, not an unfinished one — §26.4 is better served by a
 * gateway that says "not available" than by one that sends a malformed request
 * to a payment provider.
 */
abstract class Gateway implements PaymentGateway
{
    /**
     * @return array<int, GatewayCapability>
     */
    abstract public function capabilities(): array;

    /**
     * This provider's credentials.
     *
     * Every answer about naming, mode and configuration comes from here, so a
     * driver cannot end up reporting one provider's name while reading
     * another's settings — or call itself configured while the screen lists a
     * field it never asked for.
     */
    abstract protected function credentials(): GatewayCredentials;

    public function name(): string
    {
        return $this->credentials()->gateway();
    }

    public function isSandbox(): bool
    {
        return $this->credentials()->isSandbox();
    }

    /**
     * @return array<int, string>
     */
    public function requiredConfiguration(): array
    {
        return $this->credentials()->requiredKeys();
    }

    /**
     * @return array<int, string>
     */
    public function missingConfiguration(): array
    {
        return $this->credentials()->missing();
    }

    public function supports(GatewayCapability $capability): bool
    {
        return in_array($capability, $this->capabilities(), true);
    }

    /**
     * BDT unless a driver says otherwise (D4).
     *
     * Every Bangladeshi provider here takes taka and the base ledger is in
     * taka; the international ones override this with what their merchant
     * account actually accepts.
     *
     * @return array<int, Currency>
     */
    public function supportedCurrencies(): array
    {
        return [Currency::BDT];
    }

    /**
     * Status lookup is refused unless the provider offers one.
     *
     * @throws GatewayCapabilityMissing
     */
    public function status(string $reference): GatewayResult
    {
        throw GatewayCapabilityMissing::for($this->name(), GatewayCapability::StatusQuery);
    }

    /**
     * Refunding is refused unless the driver implements it.
     *
     * @throws GatewayCapabilityMissing
     */
    public function refund(RefundIntent $intent): RefundResult
    {
        throw GatewayCapabilityMissing::for(
            $this->name(),
            $intent->isFull() ? GatewayCapability::RefundFull : GatewayCapability::RefundPartial,
        );
    }

    /**
     * @throws GatewayCapabilityMissing
     */
    public function refundStatus(string $gatewayRefundReference): RefundResult
    {
        throw GatewayCapabilityMissing::for($this->name(), GatewayCapability::RefundStatus);
    }

    /**
     * Whether nothing is missing.
     *
     * Expressed through {@see missingConfiguration()} so the two can never
     * disagree — a gateway that reported itself configured while a screen listed
     * a missing field would be the worst of both.
     */
    public function isConfigured(): bool
    {
        return $this->missingConfiguration() === [];
    }

    /**
     * Refuse before dialling out if anything is missing.
     *
     * Worth doing up front rather than letting the first credential read throw
     * wherever it happens to sit. A driver that authenticates successfully and
     * only then finds it cannot build the request has made a pointless call to
     * a payment provider — and reports the wrong problem, because "your
     * credentials were rejected" is what an operator would read.
     *
     * @throws GatewayUnavailable
     */
    protected function requireConfigured(): void
    {
        if ($this->missingConfiguration() !== []) {
            throw GatewayUnavailable::missingCredentials($this->name());
        }
    }

    /**
     * Guard an operation before attempting it.
     *
     * @throws GatewayCapabilityMissing
     */
    protected function requireCapability(GatewayCapability $capability): void
    {
        if (! $this->supports($capability)) {
            throw GatewayCapabilityMissing::for($this->name(), $capability);
        }
    }
}
