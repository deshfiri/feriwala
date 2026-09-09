<?php

namespace App\Http\Responses;

use App\Support\Security\LoginThrottle;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\LockoutResponse as LockoutResponseContract;
use Laravel\Fortify\Fortify;
use Symfony\Component\HttpFoundation\Response;

/**
 * What a throttled sign-in gets back (§6).
 *
 * The same fact told two ways, because two very different clients ask.
 *
 * An API client is given **429** and a `Retry-After` header, which is the only
 * form of "come back later" anything automated can act on — a message in a body
 * is not something a retry loop reads.
 *
 * A browser is given the message. Inertia sends `Accept: text/html`, so a 429
 * would replace the sign-in screen with an error modal and lose what the person
 * typed; the validation error instead lands under the email field where every
 * other sign-in failure already appears, on the screen they are already looking
 * at. The exception still carries 429 for anything that reads the status.
 *
 * The wait is stated in seconds because "try again later" is not actionable,
 * and it comes from whichever limit is actually holding the request.
 */
class LockoutResponse implements LockoutResponseContract
{
    public function __construct(
        protected LoginThrottle $throttle,
    ) {}

    /**
     * @throws ValidationException
     */
    public function toResponse($request): Response
    {
        $seconds = $this->throttle->availableIn($request);

        /*
         * Fortify's own wording, translated. It says how long to wait and
         * nothing else — in particular it never says whether the address being
         * tried exists, which is exactly the question a lockout message is
         * tempted to answer.
         */
        $message = (string) trans('auth.throttle', [
            'seconds' => $seconds,
            'minutes' => (int) ceil($seconds / 60),
        ]);

        if ($request->expectsJson()) {
            return new JsonResponse([
                'message' => $message,
                'errors' => [Fortify::username() => [$message]],
            ], 429, ['Retry-After' => (string) $seconds]);
        }

        throw ValidationException::withMessages([
            Fortify::username() => [$message],
        ])->status(429);
    }
}
