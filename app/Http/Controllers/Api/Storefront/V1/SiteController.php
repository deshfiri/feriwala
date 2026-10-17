<?php

namespace App\Http\Controllers\Api\Storefront\V1;

use App\Domain\Order\Exceptions\WebsiteOrderRefused;
use App\Domain\Order\WebsiteCheckoutRules;
use App\Domain\Website\BannerLink;
use App\Domain\Website\CheckoutVerification;
use App\Domain\Website\Enums\DomainRoutingStatus;
use App\Domain\Website\Enums\WebsiteStatus;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsiteDomain;
use App\Domain\Website\WebsiteImageStore;
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
        protected WebsiteCheckoutRules $checkout,
        protected CheckoutVerification $verification,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $website = $this->website($request);
        $currency = Currency::tryFrom($website->currency_code) ?? Currency::base();

        // The same answers checkout and order placement give (P2).
        try {
            $this->checkout->assertAcceptingOrders($website);
            $accepting = true;
        } catch (WebsiteOrderRefused) {
            $accepting = false;
        }

        $codEnabled = $this->checkout->codEnabled($website);
        $methods = $this->checkout->paymentMethods($website, $currency);

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
                    // Where the banner image goes when clicked; null when it is a
                    // plain picture. Only an address the ERP has already vetted.
                    'banner_link_url' => $website->banner_link_url !== null && BannerLink::isAcceptable($website->banner_link_url)
                        ? $website->banner_link_url
                        : null,
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
                        'maximum' => $codEnabled ? $this->checkout->codMaximum($website, $currency)?->jsonSerialize() : null,
                    ],
                    'guest_checkout' => true,
                    // Whether a cash-on-delivery customer must confirm with an SMS
                    // code: the platform's policy, else this shop's own choice.
                    'sms_verification_required' => $this->verification->requiredFor($website),
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
