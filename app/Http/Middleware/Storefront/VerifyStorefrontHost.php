<?php

namespace App\Http\Middleware\Storefront;

use App\Domain\Website\Api\StorefrontError;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Queries\ResolveWebsiteHost;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A stated storefront host must belong to the credential's website (D30 §2,
 * contract §3.5).
 *
 * The shared storefront serves many websites from one deployment, and picks
 * each request's credential from the domain it was reached on. This is the
 * ERP checking that pairing independently: when the request states the host
 * it is serving, the ERP resolves that host itself — the website's subdomain,
 * or one of its verified, active domains — and refuses the request unless it
 * is the website that owns the credential.
 *
 * Additive and optional: without the header nothing changes, and with it the
 * credential is still the tenancy boundary. The host can only narrow a
 * request, never choose a website, so a forged `Host` or `X-Forwarded-Host`
 * reaching the storefront cannot move a request to somebody else's shop.
 */
class VerifyStorefrontHost
{
    public const HEADER = 'X-Feriwala-Storefront-Host';

    public function __construct(
        protected ResolveWebsiteHost $hosts,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $stated = $request->headers->get(self::HEADER);

        if ($stated === null) {
            return $next($request);
        }

        $website = $request->attributes->get(LogStorefrontRequest::WEBSITE);
        $resolved = $this->hosts->resolve($stated);

        if (! $website instanceof Website || $resolved === null || $resolved->website->id !== $website->id) {
            return StorefrontError::respond(
                $request,
                403,
                'host_mismatch',
                'The stated storefront host does not belong to this website.',
            );
        }

        return $next($request);
    }
}
