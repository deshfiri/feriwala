<?php

namespace App\Http\Responses;

use App\Support\Navigation\HomeRoute;
use Illuminate\Http\JsonResponse;
use Laravel\Passkeys\Contracts\PasskeyLoginResponse as PasskeyLoginResponseContract;
use Symfony\Component\HttpFoundation\Response;

class PasskeyLoginResponse implements PasskeyLoginResponseContract
{
    public function __construct(
        protected HomeRoute $home,
    ) {}

    public function toResponse($request): Response
    {
        $redirect = $this->home->urlFor($request->user());

        return $request->wantsJson()
            ? new JsonResponse(['redirect' => redirect()->intended($redirect)->getTargetUrl()], 200)
            : redirect()->intended($redirect);
    }
}
