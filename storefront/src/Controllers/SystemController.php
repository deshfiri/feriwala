<?php

declare(strict_types=1);

namespace Storefront\Controllers;

use Storefront\App;
use Storefront\Domain\Webhooks;
use Storefront\Erp\ErpException;
use Storefront\Http\Request;
use Storefront\Http\Response;
use Storefront\Http\View;
use Storefront\Kernel;
use Storefront\Support\ApiCache;
use Storefront\Support\Db;
use Storefront\Support\Env;
use Storefront\Support\I18n;
use Storefront\Support\Migrator;

final class SystemController
{
    /**
     * The delivery-area directory, proxied so the browser never holds
     * credentials. The same for every visitor, so it is cached for an hour.
     */
    public static function areas(Request $request): Response
    {
        $parent = $request->string('parent');

        if ($parent !== '' && preg_match('/^[a-z]+:[0-9:]+$/', $parent) !== 1) {
            return Response::json(['data' => []], 422);
        }

        try {
            $payload = ApiCache::remember('areas:'.($parent === '' ? 'root' : $parent), 'areas', 3600, function () use ($parent): array {
                return App::erp()->get('/delivery/areas', $parent === '' ? [] : ['parent' => $parent]);
            });
        } catch (ErpException $exception) {
            return Response::json(['data' => []], $exception->status === 404 ? 404 : 503);
        }

        $response = Response::json(['data' => is_array($payload['data'] ?? null) ? $payload['data'] : []]);
        $response->headers['Cache-Control'] = 'public, max-age=300';

        return $response;
    }

    public static function locale(Request $request): Response
    {
        $locale = $request->string('locale');

        if (in_array($locale, I18n::SUPPORTED, true)) {
            setcookie('sf_locale', $locale, [
                'expires' => time() + 60 * 60 * 24 * 365,
                'path' => '/',
                'secure' => $request->isSecure(),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }

        return Response::redirect(Kernel::back($request));
    }

    public static function about(Request $request): Response
    {
        return View::render('pages/about', ['title' => t('page.about')]);
    }

    public static function contact(Request $request): Response
    {
        return View::render('pages/contact', ['title' => t('page.contact')]);
    }

    /**
     * Liveness: unauthenticated and revealing nothing but "up".
     */
    public static function health(Request $request): Response
    {
        try {
            Db::value('SELECT 1');
        } catch (\Throwable) {
            return Response::json(['status' => 'down'], 503);
        }

        return Response::json(['status' => 'ok']);
    }

    /**
     * Create or update the tables from the browser, for hosting with no shell
     * (cPanel). Runs before any session or shop lookup, because neither works
     * until the tables exist. Idempotent: each migration is applied once, and
     * the SQL is only the files shipped in database/migrations. When
     * MIGRATE_TOKEN is set it must be passed as ?token=; error detail is shown
     * only to a caller who holds it (or with APP_DEBUG) and is always logged.
     */
    public static function migrate(Request $request): Response
    {
        $expected = (string) Env::get('MIGRATE_TOKEN', '');
        $given = $request->string('token');

        if ($expected !== '' && ! hash_equals($expected, $given)) {
            return Response::text('Not found', 404);
        }

        $trusted = $expected !== '' || Env::bool('APP_DEBUG');

        try {
            $applied = Migrator::run(dirname(__DIR__, 2).'/database/migrations');
        } catch (\Throwable $throwable) {
            error_log('[storefront] migrate failed: '.$throwable->getMessage());

            return Response::text(
                'Migration failed.'.($trusted ? "\n".$throwable->getMessage() : ' Check the database settings in .env.'),
                500,
            );
        }

        return Response::text($applied === [] ? "Nothing to migrate. The database is up to date.\n" : "Migrated: ".implode(', ', $applied)."\n");
    }

    public static function robots(Request $request): Response
    {
        $base = rtrim((string) Env::get('APP_URL', ''), '/');

        return Response::text(
            "User-agent: *\nDisallow: /cart\nDisallow: /checkout\nDisallow: /order/\nDisallow: /track\nDisallow: /payment/\nDisallow: /api/\n"
            .($base !== '' ? "Sitemap: {$base}/sitemap.xml\n" : ''),
        );
    }

    public static function webhook(Request $request): Response
    {
        $result = Webhooks::receive(
            $request->body,
            $request->header('x-feriwala-signature'),
            $request->header('x-feriwala-timestamp'),
            $request->header('x-feriwala-delivery'),
            $request->header('x-feriwala-event'),
        );

        return Response::json($result['body'], $result['status']);
    }
}
