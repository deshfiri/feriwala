<?php

namespace App\Http\Responses;

use App\Support\Navigation\HomeRoute;
use Illuminate\Http\JsonResponse;
use Laravel\Fortify\Contracts\RegisterResponse as RegisterResponseContract;
use Symfony\Component\HttpFoundation\Response;

class RegisterResponse implements RegisterResponseContract
{
    public function __construct(
        protected HomeRoute $home,
    ) {}

    public function toResponse($request): Response
    {
        /*
         * The same resolver as login (D23). Registering with an invitation
         * creates no business account, so a fixed `/dashboard` would drop that
         * person into the §5.4 funnel instead of the invitation they came for.
         */
        return $request->wantsJson()
            ? new JsonResponse(['two_factor' => false], 201)
            : redirect()->intended($this->home->urlFor($request->user()));
    }
}
