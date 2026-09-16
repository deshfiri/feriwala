<?php

namespace App\Http\Middleware\Storefront;

use App\Domain\Website\Api\StorefrontError;
use App\Domain\Website\Enums\CredentialScope;
use App\Domain\Website\Models\WebsiteCredential;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Least privilege, route by route (contract §3.4, §17.3).
 *
 * A credential carries exactly the scopes it was issued with. An authenticated
 * caller asking for something outside them is a `403` — distinct from the `401`
 * of not being who it claims — because the storefront did prove itself and
 * simply may not do this.
 */
class RequireStorefrontScope
{
    public function handle(Request $request, Closure $next, string $scope): Response
    {
        $credential = $request->attributes->get(LogStorefrontRequest::CREDENTIAL);
        $required = CredentialScope::tryFrom($scope);

        if (! $credential instanceof WebsiteCredential || $required === null || ! $credential->allows($required)) {
            return StorefrontError::respond(
                $request,
                403,
                'insufficient_scope',
                'This credential is not permitted to do that.',
                ['required_scope' => $scope],
            );
        }

        return $next($request);
    }
}
