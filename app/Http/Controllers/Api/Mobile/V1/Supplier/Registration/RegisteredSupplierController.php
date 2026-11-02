<?php

namespace App\Http\Controllers\Api\Mobile\V1\Supplier\Registration;

use App\Domain\Supplier\Actions\RegisterSupplier;
use App\Http\Controllers\Api\Mobile\V1\MobileController;
use App\Http\Requests\Mobile\Supplier\Registration\RegisterSupplierRequest;
use App\Http\Resources\Mobile\Supplier\Auth\SupplierResource;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\JsonResponse;

/**
 * Supplier registration from the mobile app (D25, P13-1).
 *
 * Opens only a Supplier identity — never a User, a business account or a
 * membership. Like the web, it signs the new supplier in straight away; they
 * must still verify email and mobile, pass KYC and be approved before they may
 * submit a listing.
 */
class RegisteredSupplierController extends MobileController
{
    /**
     * Register a supplier
     *
     * Creates the supplier account, sends the email verification message and
     * returns the same sign-in payload as `POST /supplier/auth/login`, so the
     * app is signed in straight away. The new supplier starts at
     * `verification_pending`: email (and, when the platform requires it, mobile)
     * must be verified, then KYC submitted and approved. Responds `422` for a
     * duplicate email or mobile number, a weak password or a missing field.
     *
     * @unauthenticated
     *
     * @response 201 array{token: string, token_type: 'Bearer', expires_at: string|null, supplier: SupplierResource}
     */
    public function store(RegisterSupplierRequest $request, RegisterSupplier $register): JsonResponse
    {
        $supplier = $register->handle($request->validated());

        // Sends email verification through the queued listener, as on the web.
        event(new Registered($supplier));

        $token = $supplier->createToken($request->validated('device_name'));

        return new JsonResponse([
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $token->accessToken->expires_at?->toIso8601String(),
            'supplier' => new SupplierResource($supplier),
        ], 201);
    }
}