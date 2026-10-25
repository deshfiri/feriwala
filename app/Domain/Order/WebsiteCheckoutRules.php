<?php

namespace App\Domain\Order;

use App\Domain\Kyc\KycRestrictions;
use App\Domain\Order\Exceptions\WebsiteOrderRefused;
use App\Domain\Website\CodTerms;
use App\Domain\Website\Models\Website;
use App\Domain\Website\WebsiteAddresses;
use App\Integrations\Payment\PaymentGatewayManager;
use App\Support\Money\Currency;
use App\Support\Money\Money;

/**
 * The rules a storefront checkout is held to, stated once (contract §6.1, D30, P2).
 *
 * Order placement ({@see Actions\PlaceWebsiteOrder}) and the advisory checkout
 * quote both ask here, so a quote can never promise what placement would
 * refuse, nor refuse what placement would take. Product, price and quantity
 * rules live in {@see Queries\PriceWebsiteOrder}, which both also share.
 *
 * Nothing here writes. Every refusal is the same {@see WebsiteOrderRefused}
 * placement has always raised, with the same code.
 */
class WebsiteCheckoutRules
{
    /** The payment methods a storefront may ask for. */
    public const METHODS = ['online', 'cod'];

    public function __construct(
        protected CodTerms $cod,
        protected KycRestrictions $kycRestrictions,
        protected PaymentGatewayManager $gateways,
        protected WebsiteAddresses $addresses,
    ) {}

    /**
     * The website takes orders at all: it is live, and its owner is not barred
     * from new orders.
     *
     * A KYC hold answers exactly as a paused shop does. This is reached by a
     * partner's website and ultimately its customer, and neither is entitled to
     * learn that the merchant is under verification.
     *
     * @throws WebsiteOrderRefused
     */
    public function assertAcceptingOrders(Website $website): void
    {
        if (! $website->status->isLive()) {
            throw WebsiteOrderRefused::notAcceptingOrders();
        }

        if ($this->kycRestrictions->blocksNewOrders($website->businessAccount)) {
            throw WebsiteOrderRefused::notAcceptingOrders();
        }
    }

    /**
     * What a storefront may say about payment, and what it may not (D12).
     *
     * A method the ERP offers; cash on delivery only where this website takes
     * it; never a claim that the order is already paid; and a return address
     * on this website only.
     *
     * @throws WebsiteOrderRefused
     */
    public function assertPaymentRequest(Website $website, string $method, ?string $claimedStatus = null, ?string $returnUrl = null): void
    {
        if (! in_array($method, self::METHODS, true)) {
            throw WebsiteOrderRefused::paymentMethodUnavailable($method);
        }

        if ($method === 'cod' && ! $this->cod->enabledFor($website)) {
            throw WebsiteOrderRefused::codNotAvailable();
        }

        if ($claimedStatus !== null && $claimedStatus !== 'pending') {
            throw WebsiteOrderRefused::paymentUnverified();
        }

        if ($returnUrl !== null && ! $this->addresses->allows($website, $returnUrl)) {
            throw WebsiteOrderRefused::returnUrlNotAllowed();
        }
    }

    /**
     * Coupons are not offered on websites yet.
     *
     * @throws WebsiteOrderRefused
     */
    public function assertNoCoupon(?string $couponCode): void
    {
        if ($couponCode !== null && $couponCode !== '') {
            throw WebsiteOrderRefused::couponNotApplicable($couponCode);
        }
    }

    public function codEnabled(Website $website): bool
    {
        return $this->cod->enabledFor($website);
    }

    /**
     * The most this website takes on delivery, or null for no limit.
     */
    public function codMaximum(Website $website, Currency $currency): ?Money
    {
        return $this->cod->maximumFor($website, $currency);
    }

    /**
     * Whether an order of `$total` may be paid on delivery here.
     */
    public function codAllows(Website $website, Money $total): bool
    {
        if (! $this->codEnabled($website)) {
            return false;
        }

        $maximum = $this->codMaximum($website, $total->currency);

        return $maximum === null || ! $total->greaterThan($maximum);
    }

    /**
     * @throws WebsiteOrderRefused
     */
    public function assertWithinCodLimit(Website $website, Money $total): void
    {
        $maximum = $this->codMaximum($website, $total->currency);

        if ($maximum !== null && $total->greaterThan($maximum)) {
            throw WebsiteOrderRefused::codLimitExceeded($maximum);
        }
    }

    /**
     * The payment methods this website offers for `$currency`: online when a
     * gateway can take it, cash on delivery when the website allows it.
     *
     * @return list<string>
     */
    public function paymentMethods(Website $website, Currency $currency): array
    {
        return array_values(array_filter([
            $this->gateways->availableFor($currency) !== [] ? 'online' : null,
            $this->codEnabled($website) ? 'cod' : null,
        ]));
    }

    /**
     * The gateways switched on in the ERP that can take `$currency`, for a
     * customer to choose between.
     *
     * @return list<array{name: string, label: string}>
     */
    public function gatewayChoices(Currency $currency): array
    {
        return array_map(fn (string $name): array => [
            'name' => $name,
            'label' => (string) config("payment.gateways.{$name}.label", $name),
        ], $this->gateways->availableFor($currency));
    }

    /**
     * The gateway an online order is paid through: the one asked for, if it is
     * switched on, and otherwise the first that is.
     *
     * @throws WebsiteOrderRefused
     */
    public function gatewayFor(?string $requested, Currency $currency): string
    {
        $available = $this->gateways->availableFor($currency);

        if ($requested !== null) {
            if (! in_array($requested, $available, true)) {
                throw WebsiteOrderRefused::paymentMethodUnavailable($requested);
            }

            return $requested;
        }

        return $available[0] ?? throw WebsiteOrderRefused::paymentMethodUnavailable('online');
    }
}
