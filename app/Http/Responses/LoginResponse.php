<?php

namespace App\Http\Responses;

use App\Support\Navigation\HomeRoute;
use Illuminate\Http\JsonResponse;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Symfony\Component\HttpFoundation\Response;

class LoginResponse implements LoginResponseContract
{
    public function __construct(
        protected HomeRoute $home,
    ) {}

    public function toResponse($request): Response
    {
        // Not a fixed path. `/dashboard` is business ERP, and a Feriwala staff
        // member has no business — sending them there put them through the
        // §5.4 gate and into a 403 at the front door (D23).
        return $request->wantsJson()
            ? new JsonResponse(['two_factor' => false], 200)
            : redirect()->intended($this->home->urlFor($request->user()));
    }
}
