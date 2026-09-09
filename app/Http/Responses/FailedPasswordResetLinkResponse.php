<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Password;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse as FailedPasswordResetLinkRequestResponseContract;
use Symfony\Component\HttpFoundation\Response;

/**
 * What the "forgot password" form says when it could not send a link (§6, §31.3).
 *
 * The same thing it says when it could. Fortify's own response reports the
 * broker's status on the email field, which turns the form into a way of asking
 * whether an address has an account here: "we can't find a user with that email
 * address" for one address and "check your inbox" for another is an enumeration
 * oracle that needs no credentials and no rate limit to work through a list.
 *
 * It is the same reasoning P1-14 applied to the sign-in form, and leaving this
 * one talkative would have undone it — the reset form is the easier target,
 * because it never asks for a password at all.
 *
 * Two statuses reach here, and both are covered on purpose:
 *
 *   - **No such user.** The case above.
 *   - **Throttled.** Only a real address can be throttled, so saying so
 *     identifies one just as clearly. A legitimate person asking twice in a
 *     minute is told a link is on its way and has one already; that is a small
 *     cost against handing out a membership list.
 *
 * Validation failures never reach this — a malformed address is refused by the
 * request before the broker is asked — so nothing here hides a mistake the
 * person could fix.
 */
class FailedPasswordResetLinkResponse implements FailedPasswordResetLinkRequestResponseContract
{
    public function __construct(
        protected string $status,
    ) {}

    public function toResponse($request): Response
    {
        $message = trans(Password::RESET_LINK_SENT);

        return $request->wantsJson()
            ? new JsonResponse(['message' => $message], 200)
            : back()->with('status', $message);
    }
}
