<?php

namespace App\Http\Controllers\Api\Mobile\V1\Supplier\Password;

use App\Domain\Supplier\Models\Supplier;
use App\Http\Controllers\Api\Mobile\V1\MobileController;
use App\Http\Requests\Mobile\Supplier\Password\SupplierResetPasswordRequest;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Completing a Supplier password reset, against the `suppliers` broker only (D25).
 */
class NewSupplierPasswordController extends MobileController
{
    /**
     * Reset the password
     *
     * Sets a new password from the emailed reset token. Every token the
     * supplier holds is revoked, so a phone that was signed in with the old
     * password must sign in again. Responds `422` on `email` for an invalid or
     * expired token.
     *
     * @unauthenticated
     *
     * @response array{message: string}
     */
    public function store(SupplierResetPasswordRequest $request): JsonResponse
    {
        $status = Password::broker('suppliers')->reset(
            $request->safe()->only(['email', 'password', 'password_confirmation', 'token']),
            function (Supplier $supplier, string $password) {
                $supplier->forceFill([
                    'password' => $password,
                    'remember_token' => Str::random(60),
                ])->save();

                $supplier->tokens()->delete();

                event(new PasswordReset($supplier));
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => [__($status)]]);
        }

        return new JsonResponse(['message' => __($status)]);
    }
}