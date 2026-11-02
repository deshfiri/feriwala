<?php

namespace App\Http\Middleware\Mobile;

use App\Domain\Supplier\Enums\SupplierStatus;
use App\Domain\Supplier\Models\Supplier;
use App\Models\User;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The mobile counterpart of {@see \App\Http\Middleware\EnsureIdentityHasPlatformAccess}
 * (§6, D23): is this token held by the right kind of login, and may that login
 * use the platform at all?
 *
 * Two audiences, because Feriwala has two separate account models (D25) that
 * Sanctum would otherwise treat alike: `mobile.access:user` for Client, Partner
 * and staff logins (`User`), `mobile.access:supplier` for Suppliers. A token
 * for one is refused on the other's routes — a controller written for a `User`
 * must never be handed a `Supplier`.
 *
 * A bearer token outlives the moment it was issued, so a suspension has to bite
 * on the next request, not at the next sign-in. The web gate ends a session;
 * this one revokes the token it arrived with, which is the same act for a
 * client that has no session.
 */
class EnsureMobileIdentityHasAccess
{
    public function handle(Request $request, Closure $next, string $audience = 'user'): Response
    {
        $login = $request->user();

        $expected = $audience === 'supplier' ? Supplier::class : User::class;

        if (! $login instanceof $expected) {
            return new JsonResponse([
                'code' => 'wrong_audience',
                'message' => __('This sign-in cannot be used here.'),
            ], Response::HTTP_FORBIDDEN);
        }

        if ($this->mayUsePlatform($login)) {
            return $next($request);
        }

        $login->currentAccessToken()?->delete();

        return new JsonResponse([
            'code' => 'account_unavailable',
            'message' => __('This account is not available. Contact support if you believe this is a mistake.'),
        ], Response::HTTP_FORBIDDEN);
    }

    /**
     * A Supplier that is suspended may still sign in to see its own status (the
     * web lets it reach its dashboard), so only a closed one loses its tokens.
     */
    protected function mayUsePlatform(User|Supplier $login): bool
    {
        return $login instanceof User
            ? $login->hasPlatformAccess()
            : $login->status !== SupplierStatus::Closed;
    }
}
