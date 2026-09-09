<?php

namespace App\Http\Middleware;

use App\Support\Security\SessionPolicy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Puts the configured session lifetime into config before the session starts.
 *
 * It has to run **before** `StartSession`, which is why it is prepended to the
 * web group rather than appended: the handler reads `session.lifetime` when it
 * builds the store, and `StartSession` reads it again when it writes the cookie.
 * Setting it afterwards would change nothing at all this request and would look
 * like it worked.
 */
class ApplyConfiguredSessionLifetime
{
    public function __construct(
        protected SessionPolicy $policy,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        /*
         * One value drives both halves: Laravel expires the session *record*
         * and the session *cookie* from `session.lifetime`, so setting it here
         * keeps a browser from presenting an identifier the server has already
         * discarded.
         */
        config(['session.lifetime' => $this->policy->lifetimeInMinutes()]);

        return $next($request);
    }
}
