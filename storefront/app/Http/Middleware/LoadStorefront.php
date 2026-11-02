<?php

namespace App\Http\Middleware;

use App\Services\Basket;
use App\Services\Catalog;
use App\Services\Site;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * What every shop page needs before it runs: the visitor's language, the shop's
 * own status (an unavailable shop answers every page with that, and nothing
 * else), and the figures the layout shows — the shop, the basket count, the menu.
 */
class LoadStorefront
{
    public const LOCALE_COOKIE = 'sf_locale';

    public function __construct(private readonly Site $siteReader, private readonly Catalog $catalog) {}

    public function handle(Request $request, Closure $next): Response
    {
        $site = $this->siteReader->get();

        app()->setLocale($this->locale($request, (string) $site['default_locale']));

        $status = (string) ($site['status'] ?? 'unavailable');

        if (! (($site['known'] ?? false) && $status === 'open')) {
            View::share(['site' => $site, 'cartCount' => 0, 'categories' => [], 'locale' => app()->getLocale()]);

            return response()
                ->view('errors.closed', [
                    'closedStatus' => ($site['known'] ?? false) ? $status : 'unreachable',
                    'message' => $site['maintenance_message'] ?? null,
                ], 503)
                ->header('Retry-After', '120');
        }

        $basket = Basket::forToken($request->cookie(Basket::COOKIE));

        // The categories for the menu; a failure reading them never fails a page.
        View::share([
            'site' => $site,
            'cartCount' => $basket->count(),
            'categories' => array_slice($this->catalog->categories(), 0, 10),
            'locale' => app()->getLocale(),
            'currentPath' => '/'.ltrim($request->path(), '/'),
            'searchTerm' => request_string($request, 'q'),
        ]);

        return $next($request);
    }

    private function locale(Request $request, string $shopDefault): string
    {
        $supported = (array) config('storefront.locales');
        $cookie = $request->cookie(self::LOCALE_COOKIE);

        if (is_string($cookie) && in_array($cookie, $supported, true)) {
            return $cookie;
        }

        return in_array($shopDefault, $supported, true) ? $shopDefault : 'en';
    }
}
