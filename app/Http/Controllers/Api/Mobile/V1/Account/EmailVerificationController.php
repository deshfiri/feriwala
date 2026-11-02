<?php

namespace App\Http\Controllers\Api\Mobile\V1\Account;

use App\Http\Controllers\Api\Mobile\V1\MobileController;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Confirming the email address (§5.1, P1-8). Reachable before activation.
 */
class EmailVerificationController extends MobileController
{
    /**
     * Resend the verification email
     *
     * Sends a fresh verification link to the signed-in person's address. `409`
     * with `code: email_already_verified` when there is nothing to verify. The
     * link opens the web verification page. Limited to six requests a minute.
     *
     * @response 202 array{message: string}
     */
    public function resend(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return new JsonResponse([
                'code' => 'email_already_verified',
                'message' => __('Your email address is already verified.'),
            ], Response::HTTP_CONFLICT);
        }

        $user->sendEmailVerificationNotification();

        return new JsonResponse(['message' => __('A new verification link has been sent.')], Response::HTTP_ACCEPTED);
    }
}
