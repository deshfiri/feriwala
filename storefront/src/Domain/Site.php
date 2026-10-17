<?php

declare(strict_types=1);

namespace Storefront\Domain;

use Storefront\App;
use Storefront\Erp\ErpException;
use Storefront\Support\ApiCache;
use Storefront\Support\Env;

/**
 * How the ERP says this website should be presented (`GET /site`).
 *
 * Read through a short cache and dropped by the `website.*` webhooks. When the
 * ERP cannot be reached the shop still renders from a conservative fallback —
 * and says it cannot take orders, because it cannot know.
 */
final class Site
{
    public const TAG = 'site';

    /**
     * @return array<string, mixed>
     */
    public static function get(): array
    {
        try {
            $payload = ApiCache::remember('site', self::TAG, Env::int('CACHE_TTL_SITE', 300), function (): array {
                $response = App::erp()->get('/site');

                return is_array($response['data'] ?? null) ? $response['data'] : [];
            });
        } catch (ErpException|\RuntimeException) {
            $payload = [];
        }

        return self::normalise($payload);
    }

    /**
     * @param  array<string, mixed>  $site
     * @return array<string, mixed>
     */
    public static function normalise(array $site): array
    {
        $branding = is_array($site['branding'] ?? null) ? $site['branding'] : [];
        $contact = is_array($site['contact'] ?? null) ? $site['contact'] : [];
        $locale = is_array($site['locale'] ?? null) ? $site['locale'] : [];
        $checkout = is_array($site['checkout'] ?? null) ? $site['checkout'] : [];
        $cod = is_array($checkout['cod'] ?? null) ? $checkout['cod'] : [];

        return [
            'known' => $site !== [],
            'name' => is_string($site['name'] ?? null) && $site['name'] !== '' ? $site['name'] : app_name_fallback(),
            // open | maintenance | preparing | unavailable — anything else is read as unavailable.
            'status' => is_string($site['status'] ?? null) ? $site['status'] : ($site === [] ? 'unavailable' : 'open'),
            'accepting_orders' => (bool) ($site['accepting_orders'] ?? false),
            'maintenance_message' => is_string($site['maintenance_message'] ?? null) ? $site['maintenance_message'] : null,
            'branding' => [
                'primary_color' => self::colour($branding['primary_color'] ?? null, '#1f2937'),
                'secondary_color' => self::colour($branding['secondary_color'] ?? null, '#c2410c'),
                'logo_url' => is_string($branding['logo_url'] ?? null) ? $branding['logo_url'] : null,
                'banner_url' => is_string($branding['banner_url'] ?? null) ? $branding['banner_url'] : null,
                'banner_link' => self::bannerLink($branding['banner_link_url'] ?? null),
                'tagline' => is_string($branding['tagline'] ?? null) ? $branding['tagline'] : null,
                'about' => is_string($branding['about'] ?? null) ? $branding['about'] : null,
            ],
            'contact' => [
                'email' => is_string($contact['email'] ?? null) ? $contact['email'] : null,
                'phone' => is_string($contact['phone'] ?? null) ? $contact['phone'] : null,
                'address' => is_string($contact['address'] ?? null) ? $contact['address'] : null,
            ],
            'default_locale' => is_string($locale['default'] ?? null) ? $locale['default'] : 'en',
            'currency' => is_string($site['currency'] ?? null) ? $site['currency'] : 'BDT',
            'payment_methods' => array_values(array_filter(
                is_array($checkout['payment_methods'] ?? null) ? $checkout['payment_methods'] : [],
                'is_string',
            )),
            'cod' => [
                'enabled' => (bool) ($cod['enabled'] ?? false),
                'maximum' => is_array($cod['maximum'] ?? null) ? $cod['maximum'] : null,
            ],
            // Whether a cash-on-delivery customer confirms with an SMS code. Only
            // what the ERP said: when it is silent (an older ERP) the code is asked for.
            'sms_verification_required' => (bool) ($checkout['sms_verification_required'] ?? true),
        ];
    }

    /**
     * Where the banner goes when clicked, or null for a plain picture.
     *
     * The ERP only sends an address it has vetted; this checks again because a
     * link ends up in an `href`. A page on this shop (`/products`) or an https
     * address — plain http only on a developer machine. Anything else, including
     * `javascript:`, protocol-relative `//host`, embedded credentials and control
     * characters, is dropped and the banner is just a picture.
     *
     * @return array{url: string, external: bool}|null
     */
    public static function bannerLink(mixed $value): ?array
    {
        if (! is_string($value) || $value === '' || strlen($value) > 2048 || preg_match('/[\x00-\x20\x7f\\\\]/', $value) === 1) {
            return null;
        }

        if (str_starts_with($value, '/')) {
            return str_starts_with($value, '//') ? null : ['url' => $value, 'external' => false];
        }

        $parts = parse_url($value);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);
        $local = Env::get('APP_ENV', 'production') === 'local';

        return $scheme === 'https' || ($scheme === 'http' && $local)
            ? ['url' => $value, 'external' => true]
            : null;
    }

    /**
     * A hex colour or the fallback — it is written into a stylesheet, so
     * nothing else is allowed through.
     */
    private static function colour(mixed $value, string $fallback): string
    {
        return is_string($value) && preg_match('/^#[0-9a-fA-F]{6}$/', $value) === 1 ? $value : $fallback;
    }
}

/**
 * @internal
 */
function app_name_fallback(): string
{
    return (string) Env::get('APP_NAME', 'Store');
}
