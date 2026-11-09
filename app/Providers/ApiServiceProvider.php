<?php

namespace App\Providers;

use App\Domain\Access\Enums\PlatformRole;
use App\Models\User;
use Dedoc\Scramble\Http\Middleware\RestrictedDocsAccess;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Dedoc\Scramble\Support\RouteInfo;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

/**
 * The mobile API (`/api/mobile/v1`) and the OpenAPI documents for both APIs.
 *
 * Two documents, one per audience, each rendered by Swagger UI:
 *
 *   /docs/mobile      the mobile app's API (bearer token)
 *   /docs/storefront  the partner storefront API (signed requests, frozen at v1)
 *
 * The documents are generated from the routes, form requests and resources, so
 * they cannot drift from the code. `/docs/{api}.json` is the raw OpenAPI file.
 */
class ApiServiceProvider extends ServiceProvider
{
    /**
     * @var array<string, string>
     */
    public const DOCUMENTS = [
        'mobile' => 'Feriwala Mobile API',
        'storefront' => 'Feriwala Storefront API',
    ];

    public function boot(): void
    {
        $this->configureRateLimiting();
        $this->configureDocumentation();
    }

    protected function configureRateLimiting(): void
    {
        // Per person once signed in, per address before that.
        RateLimiter::for('mobile', function (Request $request) {
            return Limit::perMinute((int) config('mobile.rate_limit_per_minute', 120))
                ->by('mobile|'.($request->user()?->getAuthIdentifier() ?? $request->ip()));
        });

        // Guessing a second-factor code: keyed on the challenge being answered,
        // so rotating addresses does not buy more attempts.
        RateLimiter::for('mobile-two-factor', function (Request $request) {
            return Limit::perMinute(5)->by('mobile-2fa|'.hash('sha256', (string) $request->input('challenge_token', $request->ip())));
        });
    }

    /**
     * The Swagger group a route sits under, taken from its name.
     *
     * `mobile.v1.wallet.transactions.show` is under "Wallet";
     * `mobile.v1.admin.orders.index` under "Admin · Orders". Naming a route is
     * therefore also how it is filed, and no endpoint needs a tag of its own.
     *
     * @return list<string>
     */
    public static function tagFor(string $routeName): array
    {
        $segments = explode('.', $routeName);

        // Drop the `mobile.v1` / `storefront.v1` prefix.
        $segments = array_slice($segments, 2);

        if ($segments === [] || $segments[0] === 'health') {
            return ['System'];
        }

        $label = fn (string $segment): string => match ($segment) {
            'auth' => 'Authentication',
            default => Str::headline($segment),
        };

        if (in_array($segments[0], ['admin', 'supplier'], true) && isset($segments[1])) {
            return [$label($segments[0]).' · '.$label($segments[1])];
        }

        return [$label($segments[0])];
    }

    protected function configureDocumentation(): void
    {
        Scramble::resolveTagsUsing(
            fn (RouteInfo $route): array => self::tagFor((string) $route->route->getName()),
        );

        Gate::define('viewApiDocs', function (?User $user): bool {
            if (config('scramble.public')) {
                return true;
            }

            return $user instanceof User
                && $user->hasAnyRole([PlatformRole::SuperAdmin->value, PlatformRole::SystemAdministrator->value]);
        });

        Scramble::configure()
            ->expose(ui: '/docs/mobile/scramble', document: '/docs/mobile.json')
            ->withDocumentTransformers(function (OpenApi $document): void {
                $document->info->title = self::DOCUMENTS['mobile'];
                $document->info->description = (string) file_get_contents(base_path('docs/api/mobile.md'));
                $document->secure(SecurityScheme::http('bearer', 'Sanctum token'));
            });

        Scramble::registerApi('storefront', [
            'api_path' => 'api/storefront/v1',
            'info' => ['version' => '1.0.0', 'description' => ''],
        ])
            ->expose(ui: '/docs/storefront/scramble', document: '/docs/storefront.json')
            ->withDocumentTransformers(function (OpenApi $document): void {
                $document->info->title = self::DOCUMENTS['storefront'];
                $document->info->description = (string) file_get_contents(base_path('docs/api/storefront.md'));
            });

        Route::middleware(['web', RestrictedDocsAccess::class])
            ->get('docs/{api}', fn (string $api) => view('docs.swagger', [
                'title' => self::DOCUMENTS[$api],
                // Relative on purpose: an absolute url() takes its scheme from the
                // request, which is `http` behind a TLS-terminating proxy and makes
                // the browser block the spec as mixed content.
                'specUrl' => "/docs/{$api}.json",
            ]))
            ->whereIn('api', array_keys(self::DOCUMENTS))
            ->name('docs.swagger');
    }
}
