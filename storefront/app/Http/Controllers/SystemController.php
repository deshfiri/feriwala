<?php

namespace App\Http\Controllers;

use App\Erp\Client;
use App\Erp\ErpException;
use App\Http\Middleware\LoadStorefront;
use App\Services\ApiCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Throwable;

class SystemController extends Controller
{
    /**
     * The delivery-area directory, proxied so the browser never holds
     * credentials. The same for every visitor, so it is cached for an hour.
     */
    public function areas(Request $request, Client $erp, ApiCache $cache): JsonResponse
    {
        $parent = request_string($request, 'parent');

        if ($parent !== '' && preg_match('/^[a-z]+:[0-9:]+$/', $parent) !== 1) {
            return response()->json(['data' => []], 422);
        }

        try {
            $payload = $cache->remember('areas:'.($parent === '' ? 'root' : $parent), 'areas', 3600, fn (): array => $erp->get('/delivery/areas', $parent === '' ? [] : ['parent' => $parent]));
        } catch (ErpException $exception) {
            return response()->json(['data' => []], $exception->status === 404 ? 404 : 503);
        }

        return response()
            ->json(['data' => is_array($payload['data'] ?? null) ? $payload['data'] : []])
            ->header('Cache-Control', 'public, max-age=300');
    }

    public function locale(Request $request): RedirectResponse
    {
        $locale = request_string($request, 'locale');

        if (in_array($locale, (array) config('storefront.locales'), true)) {
            Cookie::queue(Cookie::make(LoadStorefront::LOCALE_COOKIE, $locale, 60 * 24 * 365, '/', null, $request->isSecure(), true, false, 'lax'));
        }

        return redirect(own_page_or($request));
    }

    public function about(): View
    {
        return view('pages.about', ['title' => t('page.about')]);
    }

    public function contact(): View
    {
        return view('pages.contact', ['title' => t('page.contact')]);
    }

    /**
     * Liveness: unauthenticated and revealing nothing but "up".
     */
    public function health(): JsonResponse
    {
        try {
            DB::select('SELECT 1');
        } catch (Throwable) {
            return response()->json(['status' => 'down'], 503);
        }

        return response()->json(['status' => 'ok']);
    }

    /**
     * Create or update the tables from the browser, for hosting with no shell
     * (cPanel). Runs before any session or shop lookup, because neither works
     * until the tables exist. Idempotent. When MIGRATE_TOKEN is set it must be
     * passed as ?token=; error detail is shown only to a caller who holds it (or
     * with APP_DEBUG) and is always logged.
     */
    public function migrate(Request $request): Response
    {
        $expected = (string) config('storefront.migrate_token');
        $given = request_string($request, 'token');

        if ($expected !== '' && ! hash_equals($expected, $given)) {
            return response('Not found', 404)->header('Content-Type', 'text/plain');
        }

        $trusted = $expected !== '' || config('app.debug');

        try {
            Artisan::call('migrate', ['--force' => true]);
        } catch (Throwable $throwable) {
            report($throwable);

            return response(
                'Migration failed.'.($trusted ? "\n".$throwable->getMessage() : ' Check the database settings in .env.'),
                500,
            )->header('Content-Type', 'text/plain');
        }

        return response(trim(Artisan::output())."\n")->header('Content-Type', 'text/plain');
    }

    public function robots(): Response
    {
        $base = rtrim((string) config('app.url'), '/');

        return response(
            "User-agent: *\nDisallow: /cart\nDisallow: /checkout\nDisallow: /order/\nDisallow: /track\nDisallow: /payment/\nDisallow: /api/\n"
            .($base !== '' ? "Sitemap: {$base}/sitemap.xml\n" : ''),
        )->header('Content-Type', 'text/plain');
    }
}
