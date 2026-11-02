<?php

namespace App\Http\Controllers\Api\Mobile\V1\Supplier\Password;

use App\Http\Controllers\Api\Mobile\V1\MobileController;
use App\Http\Requests\Mobile\Supplier\Password\SupplierForgotPasswordRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

/**
 * Requesting a Supplier password reset link, against the `suppliers` broker
 * only (D25) — a client's email never receives a supplier reset.
 */
class SupplierPasswordResetLinkController extends MobileController
{
    /**
     * Email a password reset link
     *
     * Sends a reset link to a supplier email address. The link carries the
     * token the app then sends to `POST /supplier/reset-password`. Responds
     * `422` on `email` when no link can be sent (unknown address, or asked too
     * recently), exactly as the web form does.
     *
     * @unauthenticated
     *
     * @response array{message: string}
     */
    public function store(SupplierForgotPasswordRequest $request): JsonResponse
    {
        $status = Password::broker('suppliers')->sendResetLink($request->safe()->only('email'));

        if ($status !== Password::RESET_LINK_SENT) {
            throw ValidationException::withMessages(['email' => [__($status)]]);
        }

        return new JsonResponse(['message' => __($status)]);
    }
}