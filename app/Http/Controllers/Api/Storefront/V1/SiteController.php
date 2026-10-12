<?php

namespace App\Http\Controllers\Api\Storefront\V1;

use App\Domain\Kyc\KycRestrictions;
use App\Domain\Website\CodTerms;
use App\Domain\Website\Enums\DomainRoutingStatus;
use App\Domain\Website\Enums\WebsiteStatus;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsiteDomain;
use App\Domain\Website\WebsiteImageStore;
use App\Integrations\Payment\PaymentGatewayManager;
use App\Support\Localization\Locale;
use App\Support\Money\Currency;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * How the storefront should present the calling website (D30, contract §5.4).
 *
 * The website is the credential's and nobody else's. What comes back is what
 * a customer could read on the shop anyway — its name and addresses, whether it
 * is open, its branding and public contact details, its locales and currency,
 * and how it takes payment — and nothing about the owner's account, package,
 * wallet, suppliers, other websites, or any secret.
 *
 * "Accepting orders" is the same answer order submission gives: a live website
 * whose owner is not barred from new orders. Why it is not accepting them is
 * deliberately not said (contract §6.1).
 */
class SiteController extends StorefrontController
{
    public function __construct(
        protected WebsiteImageStore $images,
        protected CodTerms $cod,
        protected KycRestrictions $kycRestrictions,
        protected PaymentGatewayManager $gateways,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $website = $this->website($request);
        $currency = Currency::tryFrom($website->currency_code) ?? Currency::base();

        $accepting = $website->status->isLive()
            && ! $this->kycRestrictions->blocksNewOrders($website->businessAccount);

        $codEnabled = $this->cod->enabledFor($website);
        $methods = array_values(array_filter([
            $this->gateways->availableFor($currency) !== [] ? 'online' : null,
            $codEnabled ? 'cod' : null,
        ]));

        return new JsonResponse([
            'data' => [
                'id' => $website->public_id,
                'name' => $website->name,
                'status' => $this->publicStatus($website->status),
                'accepting_orders' => $accepting,
                'maintenance_message' => $website->status === WebsiteStatus::Maintenance
                    ? $website->maintenance_message
                    : null,
                'hosts' => $this->hosts($website),
                'branding' => [
                    'theme' => $website->theme->value,
                    'primary_color' => $website->primary_color,
                    'secondary_color' => $website->secondary_color,
                    'logo_url' => $this->images->url($website->logo_path),
                    'banner_url' => $this->images->url($website->banner_path),
                    'tagline' => $website->tagline,
                    'about' => $website->about,
                ],
                'contact' => [
                    'email' => $website->contact_email,
                    'phone' => $website->contact_phone,
                    'address' => $website->contact_address,
                ],
                'locale' => [
                    'default' => Locale::default()->value,
                    'available' => array_map(fn (Locale $locale) => $locale->value, Locale::cases()),
                ],
                'currency' => $currency->value,
                'checkout' => [
                    'payment_methods' => $methods,
                    'cod' => [
                        'enabled' => $codEnabled,
                        'maximum' => $codEnabled ? $this->cod->maximumFor($website, $currency)?->jsonSerialize() : null,
                    ],
                    'guest_checkout' => true,
                ],
            ],
        ]);
    }

    /**
     * Open, under maintenance, still being built, or unavailable — never the
     * internal reason a website is down.
     */
    protected function publicStatus(WebsiteStatus $status): string
    {
        return match (true) {
            $status->isLive() => 'open',
            $status === WebsiteStatus::Maintenance => 'maintenance',
            $status->isBeingBuilt() => 'preparing',
            default => 'unavailable',
        };
    }

    /**
     * Where the website answers: its subdomain always, and each of its active
     * custom domains, primary first.
     *
     * @return array{primary: string, subdomain: string, aliases: list<string>}
     */
    protected function hosts(Website $website): array
    {
        $subdomain = $website->subdomain.'.'.config('website.storefront_domain');

        $active = $website->domains()
            ->where('routing_status', DomainRoutingStatus::Active->value)
            ->get()
            ->filter(fn (WebsiteDomain $domain) => $domain->serves())
            ->pluck('domain')
            ->all();

        $primary = $website->domain !== null && in_array($website->domain, $active, true)
            ? $website->domain
            : ($active[0] ?? $subdomain);

        return [
            'primary' => $primary,
            'subdomain' => $subdomain,
            'aliases' => array_values(array_filter($active, fn (string $host) => $host !== $primary)),
        ];
    }
}
