<?php

namespace App\Http\Controllers\Api\Mobile\V1\Account;

use App\Actions\Fortify\ResetUserPassword;
use App\Domain\Account\Actions\EndAuthenticatedSessions;
use App\Domain\Account\Models\AuthenticatedSession;
use App\Http\Controllers\Api\Mobile\V1\MobileController;
use App\Http\Requests\Mobile\Account\ForgotPasswordRequest;
use App\Http\Requests\Mobile\Account\ResetPasswordRequest;
use App\Models\User;
use App\Notifications\Account\PasswordChanged;
use App\Support\Security\LoginThrottle;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Forgotten passwords: ask for a link, then choose a new password with it.
 *
 * The same broker, the same {@see ResetUserPassword} action and the same
 * "answer the same whether or not the address has an account" rule (§31.3) as the
 * web form.
 */
class PasswordResetController extends MobileController
{
    public function __construct(
        protected ResetUserPassword $resetPassword,
        protected EndAuthenticatedSessions $endSessions,
    ) {}

    /**
     * Send a password reset link
     *
     * Emails a reset link (valid 60 minutes by default) when the address belongs to an
     * account. The answer is `200` with the same message either way, so the form
     * cannot be used to find out who has an account. Limited to five requests a
     * minute (`429`).
     *
     * The link opens the web reset page; the token and email in it are what
     * `POST /password/reset` takes.
     *
     * @unauthenticated
     *
     * @response array{message: string}
     */
    public function sendLink(ForgotPasswordRequest $request): JsonResponse
    {
        Password::broker(config('fortify.passwords'))->sendResetLink([
            'email' => LoginThrottle::normalize($request->validated('email')),
        ]);

        return new JsonResponse(['message' => trans(Password::RESET_LINK_SENT)]);
    }

    /**
     * Reset the password
     *
     * Sets a new password from a reset link's `token` and `email`. Every bearer
     * token and web session of the account is ended, so the person signs in again
     * with the new password; they are told by email. `422` for an invalid or
     * expired token, or a password the policy refuses. Limited to five requests a
     * minute (`429`).
     *
     * @unauthenticated
     *
     * @response array{message: string}
     */
    public function reset(ResetPasswordRequest $request): JsonResponse
    {
        $status = Password::broker(config('fortify.passwords'))->reset(
            [
                'email' => LoginThrottle::normalize($request->validated('email')),
                'password' => $request->validated('password'),
                'password_confirmation' => $request->validated('password_confirmation'),
                'token' => $request->validated('token'),
            ],
            function (User $user) use ($request): void {
                $this->resetPassword->reset($user, $request->validated());

                $user->forceFill(['remember_token' => Str::random(60)])->save();
                $user->tokens()->delete();
                $this->endSessions->all($user, AuthenticatedSession::ENDED_PASSWORD_CHANGED);
                $user->notify(new PasswordChanged(viaReset: true));

                event(new PasswordReset($user));
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => trans($status)]);
        }

        return new JsonResponse(['message' => trans($status)]);
    }
}
