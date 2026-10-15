<?php

declare(strict_types=1);

namespace Storefront;

use Storefront\Controllers\CartController;
use Storefront\Controllers\CatalogController;
use Storefront\Controllers\CheckoutController;
use Storefront\Controllers\OrderController;
use Storefront\Controllers\SystemController;
use Storefront\Domain\Cart;
use Storefront\Domain\Catalog;
use Storefront\Domain\Site;
use Storefront\Erp\ErpException;
use Storefront\Http\Csrf;
use Storefront\Http\Request;
use Storefront\Http\Response;
use Storefront\Http\Router;
use Storefront\Http\Session;
use Storefront\Http\View;
use Storefront\Support\Env;
use Storefront\Support\I18n;

/**
 * One request, in order: session, language, routing, the CSRF check on anything
 * that changes state, and the shop's own status (an unavailable shop answers
 * every page with that, and nothing else).
 */
final class Kernel
{
    /** Paths that must work whatever the shop's status: they are the ERP's and the monitor's. */
    private const ALWAYS = ['/health', '/webhooks/feriwala'];

    public static function router(): Router
    {
        $router = new Router;

        $router->get('/', fn (Request $r) => CatalogController::home($r));
        $router->get('/products', fn (Request $r) => CatalogController::listing($r, null));
        $router->get('/category/{slug}', fn (Request $r, array $p) => CatalogController::listing($r, $p['slug']));
        $router->get('/search', fn (Request $r) => CatalogController::listing($r, null));
        $router->get('/product/{slug}', fn (Request $r, array $p) => CatalogController::show($r, $p['slug']));

        $router->get('/cart', fn (Request $r) => CartController::show($r));
        $router->post('/cart/add', fn (Request $r) => CartController::add($r));
        $router->post('/cart/update', fn (Request $r) => CartController::update($r));
        $router->post('/cart/remove', fn (Request $r) => CartController::remove($r));

        $router->get('/checkout', fn (Request $r) => CheckoutController::form($r));
        $router->post('/checkout', fn (Request $r) => CheckoutController::submit($r));
        $router->get('/checkout/{token}', fn (Request $r, array $p) => CheckoutController::review($r, $p['token']));
        $router->post('/checkout/{token}/place', fn (Request $r, array $p) => CheckoutController::place($r, $p['token']));

        $router->get('/order/{id}', fn (Request $r, array $p) => OrderController::show($r, $p['id']));
        $router->post('/order/{id}/pay', fn (Request $r, array $p) => OrderController::pay($r, $p['id']));
        $router->post('/order/{id}/cancel', fn (Request $r, array $p) => OrderController::cancel($r, $p['id']));
        $router->post('/order/{id}/code', fn (Request $r, array $p) => OrderController::sendCode($r, $p['id']));
        $router->post('/order/{id}/confirm', fn (Request $r, array $p) => OrderController::confirm($r, $p['id']));
        $router->get('/order/{id}/return', fn (Request $r, array $p) => OrderController::returnForm($r, $p['id']));
        $router->post('/order/{id}/return', fn (Request $r, array $p) => OrderController::returnSubmit($r, $p['id']));
        $router->get('/payment/return', fn (Request $r) => OrderController::paymentReturn($r));
        $router->get('/track', fn (Request $r) => OrderController::trackForm($r));
        $router->post('/track', fn (Request $r) => OrderController::trackSubmit($r));

        $router->get('/api/areas', fn (Request $r) => SystemController::areas($r));
        $router->post('/locale', fn (Request $r) => SystemController::locale($r));
        $router->get('/about', fn (Request $r) => SystemController::about($r));
        $router->get('/contact', fn (Request $r) => SystemController::contact($r));
        $router->get('/health', fn (Request $r) => SystemController::health($r));
        $router->get('/robots.txt', fn (Request $r) => SystemController::robots($r));
        $router->post('/webhooks/feriwala', fn (Request $r) => SystemController::webhook($r));

        return $router;
    }

    public static function handle(Request $request): Response
    {
        $isWebhook = $request->path === '/webhooks/feriwala';

        // A webhook is signed, not cookie-bearing: no session is started for it.
        if (! $isWebhook) {
            Session::start($request->isSecure());
        }

        $site = in_array($request->path, self::ALWAYS, true) ? [] : Site::get();

        I18n::boot($request->cookies['sf_locale'] ?? null, (string) ($site['default_locale'] ?? 'en'));

        $matched = self::router()->match($request->method, $request->path);

        if ($matched === 404) {
            return self::page('errors/404', 404);
        }

        if ($matched === 405) {
            return Response::text('Method not allowed', 405)->header('Allow', 'GET, POST');
        }

        if ($request->method === 'POST' && ! $isWebhook && ! Csrf::valid($request)) {
            Session::flash('error', t('error.csrf'));

            return Response::redirect(self::back($request));
        }

        if (! in_array($request->path, self::ALWAYS, true)) {
            $closed = self::closed($site);

            if ($closed !== null) {
                return $closed;
            }

            self::share($request, $site);
        }

        try {
            return ($matched['handler'])($request, $matched['params']);
        } catch (ErpException $exception) {
            return self::unavailable($exception);
        }
    }

    /**
     * @param  array<string, mixed>  $site
     */
    private static function closed(array $site): ?Response
    {
        $status = (string) ($site['status'] ?? 'unavailable');

        if (($site['known'] ?? false) && $status === 'open') {
            return null;
        }

        View::share(['site' => $site, 'cartCount' => 0, 'categories' => [], 'locale' => I18n::locale()]);

        return View::render('errors/closed', [
            'closedStatus' => ($site['known'] ?? false) ? $status : 'unreachable',
            'message' => $site['maintenance_message'] ?? null,
        ], true, 503)->header('Retry-After', '120');
    }

    /**
     * @param  array<string, mixed>  $site
     */
    private static function share(Request $request, array $site): void
    {
        $cart = Cart::forToken($request->cookies[Cart::COOKIE] ?? null);

        // The categories for the menu; a failure reading them never fails a page.
        View::share([
            'site' => $site,
            'cartCount' => $cart->count(),
            'categories' => array_slice(Catalog::categories(), 0, 10),
            'locale' => I18n::locale(),
            'currentPath' => $request->path,
            'searchTerm' => $request->string('q'),
        ]);
    }

    public static function page(string $template, int $status = 200): Response
    {
        return View::render($template, [], true, $status);
    }

    public static function unavailable(ErpException $exception): Response
    {
        // Logged for the operator, never shown: an upstream message or request
        // id is not for a customer.
        error_log(sprintf('[storefront] ERP %s (%d) request_id=%s: %s', $exception->errorCode, $exception->status, $exception->requestId ?? '-', $exception->getMessage()));

        $status = $exception->status === 404 ? 404 : 503;

        return View::render($status === 404 ? 'errors/404' : 'errors/unavailable', [], true, $status);
    }

    /**
     * Where to send somebody back to: the page they came from when it is ours.
     */
    public static function back(Request $request, string $fallback = '/'): string
    {
        $referer = $request->header('referer');

        if ($referer !== null) {
            $parts = parse_url($referer);
            $appHost = parse_url((string) Env::get('APP_URL', ''), PHP_URL_HOST);
            $host = (string) ($parts['host'] ?? '');
            $own = explode(':', (string) ($request->headers['host'] ?? ''))[0];

            if ($host !== '' && ($host === $appHost || $host === $own)) {
                return ($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '');
            }
        }

        return $fallback;
    }
}
