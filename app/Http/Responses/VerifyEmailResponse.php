<?php

namespace App\Http\Responses;

use App\Support\Navigation\HomeRoute;
use Laravel\Fortify\Contracts\VerifyEmailResponse as VerifyEmailResponseContract;
use Symfony\Component\HttpFoundation\Response;

/**
 * Where someone lands after confirming their email address (§5.1, D23).
 *
 * Fortify's default is `config('fortify.home')` — a fixed `/dashboard`, which
 * is the shape of bug that locked platform staff out at login: the dashboard is
 * business ERP, and a staff member has no business. The same resolver answers
 * here, so verification cannot reintroduce it.
 */
class VerifyEmailResponse implements VerifyEmailResponseContract
{
    public function __construct(
        protected HomeRoute $home,
    ) {}

    public function toResponse($request): Response
    {
        return redirect()
            ->to($this->home->urlFor($request->user()).'?verified=1');
    }
}
