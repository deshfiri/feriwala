<?php

namespace App\Domain\Order\Actions;

use App\Domain\Billing\Data\PartnerGatewayCandidate;
use App\Domain\Billing\Enums\GatewayOwnership;
use App\Domain\Billing\Enums\PartnerGatewayFallbackReason;
use App\Domain\Billing\PartnerGatewayEligibility;
use App\Domain\Inventory\Models\StockReservation;
use App\Domain\Order\Data\WebsiteOrderGatewayChoice;
use App\Domain\Order\Data\WebsiteOrderQuote;
use App\Domain\Order\Exceptions\WebsiteOrderRefused;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderItem;
use App\Domain\Order\WebsiteCheckoutRules;
use App\Domain\Website\Models\Website;
use App\Integrations\Payment\Gateways\GatewayCredentials;
use App\Integrations\Payment\PaymentGatewayManager;
use App\Support\Money\Currency;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Collection;
use Throwable;

/**
 * Decides, at placement, which gateway a website order's payment goes to (D36).
 *
 * A Storefront with no Partner gateway simply uses Feriwala's, as it always did.
 * A Storefront that has one gets Model B only when **everything** holds, and the
 * answer is made here — before any payment is stamped and before any gateway is
 * contacted:
 *
 *   - Admin allows Partner gateways, and this provider (gateway side, in
 *     {@see PartnerGatewayEligibility});
 *   - the account is Non-Conditional;
 *   - every line is filled from central stock and has a defined frozen amount —
 *     an order that mixes lines that qualify with lines that do not, does not;
 *   - the Storefront did not ask for a different provider by name;
 *   - and the immutable receivable snapshot was written completely.
 *
 * Anything else takes Feriwala's gateway, with the reason as a name from a fixed
 * list. The snapshot is written last and in a savepoint: if it cannot be written,
 * nothing of it remains, the order is Feriwala's, and no Partner payment ever
 * existed to be contacted.
 *
 * **Final.** The choice is made once. A Partner payment that later fails to
 * start is never moved to Feriwala's gateway, because by then the customer may
 * be paying it.
 */
class ChooseWebsiteOrderGateway
{
    public function __construct(
        protected PartnerGatewayEligibility $partnerGateways,
        protected FreezePlatformReceivable $receivables,
        protected WebsiteCheckoutRules $rules,
        protected PaymentGatewayManager $gateways,
        protected DatabaseManager $database,
    ) {}

    /**
     * @param  array<int, StockReservation>  $reservations  keyed by the line's position
     *
     * @throws WebsiteOrderRefused when Feriwala's own gateway has to be used and none can take the order
     */
    public function handle(Website $website, Order $order, WebsiteOrderQuote $quote, array $reservations, ?string $requested): WebsiteOrderGatewayChoice
    {
        $candidate = $this->partnerGateways->candidate($website, $quote->currency);

        if (! $candidate->hasPartnerGateway()) {
            return $this->platform($quote->currency, $requested);
        }

        $reason = $candidate->reason
            ?? $this->orderReason($website, $order, $reservations, $requested, $candidate)
            ?? $this->freeze($order, $quote);

        if ($reason !== null || $candidate->version === null) {
            return $this->platform(
                $quote->currency,
                $requested,
                $reason ?? PartnerGatewayFallbackReason::ConfigurationUnavailable,
                $candidate->provider,
            );
        }

        return new WebsiteOrderGatewayChoice(
            gateway: $candidate->version->provider,
            mode: $candidate->version->mode,
            ownership: GatewayOwnership::Partner,
            versionId: $candidate->version->id,
            partnerProvider: $candidate->version->provider,
        );
    }

    /**
     * What stops this order, as opposed to this gateway, being Model B.
     *
     * @param  array<int, StockReservation>  $reservations
     */
    protected function orderReason(Website $website, Order $order, array $reservations, ?string $requested, PartnerGatewayCandidate $candidate): ?PartnerGatewayFallbackReason
    {
        if ($requested !== null && $requested !== $candidate->provider) {
            return PartnerGatewayFallbackReason::RequestedOtherProvider;
        }

        // The account's type as it is at placement. A Storefront order does not
        // snapshot it on the order, so it is read from the account that is placing it.
        if (! $website->businessAccount->isNonConditional()) {
            return PartnerGatewayFallbackReason::AccountNotNonConditional;
        }

        /** @var Collection<int, OrderItem> $items */
        $items = $order->items()->orderBy('line_number')->get();

        foreach ($items as $item) {
            $reservation = $reservations[$item->line_number - 1] ?? null;

            // Partner-owned or partner-sourced stock has no defined Banij entitlement.
            if ($reservation === null || $reservation->isPartnerSourced()) {
                return PartnerGatewayFallbackReason::LineNotCentralStock;
            }

            if ($item->platform_retained_amount === null && $item->product_cost === null) {
                return PartnerGatewayFallbackReason::LineAmountUndefined;
            }

            if ($item->currency_code !== $order->currency_code) {
                return PartnerGatewayFallbackReason::CurrencyMismatch;
            }
        }

        return $items->isEmpty() ? PartnerGatewayFallbackReason::LineAmountUndefined : null;
    }

    /**
     * Write the receivable snapshot, completely or not at all.
     *
     * In a savepoint, so a refusal or a database error leaves nothing behind and
     * the surrounding placement carries on as a Feriwala-gateway order.
     */
    protected function freeze(Order $order, WebsiteOrderQuote $quote): ?PartnerGatewayFallbackReason
    {
        try {
            $this->database->transaction(fn () => $this->receivables->handle($order->refresh(), $quote));
        } catch (Throwable) {
            return PartnerGatewayFallbackReason::SnapshotIncomplete;
        }

        return null;
    }

    /**
     * Feriwala's own gateway, chosen exactly as it always was.
     *
     * The one difference: a Storefront that asked for its own Partner provider by
     * name, and fell back for another reason, may be asking for something Feriwala
     * does not offer. That is not a refusal — the order is Feriwala's and takes
     * Feriwala's default — whereas a name the Storefront gave for any other reason
     * is still refused when Feriwala cannot take it.
     *
     * @throws WebsiteOrderRefused
     */
    protected function platform(Currency $currency, ?string $requested, ?PartnerGatewayFallbackReason $reason = null, ?string $partnerProvider = null): WebsiteOrderGatewayChoice
    {
        try {
            $gateway = $this->rules->gatewayFor($requested, $currency);
        } catch (WebsiteOrderRefused $refused) {
            if ($reason === null || $requested === null || $requested !== $partnerProvider) {
                throw $refused;
            }

            $gateway = $this->rules->gatewayFor(null, $currency);
        }

        return new WebsiteOrderGatewayChoice(
            gateway: $gateway,
            mode: $this->gateways->driver($gateway)->isSandbox() ? GatewayCredentials::SANDBOX : GatewayCredentials::LIVE,
            ownership: GatewayOwnership::Platform,
            fallbackReason: $reason,
            partnerProvider: $partnerProvider,
        );
    }
}
