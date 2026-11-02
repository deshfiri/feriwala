<?php

namespace App\Http\Controllers\Api\Mobile\V1\Supplier\Verification;

use App\Domain\Account\MobileVerificationRequirement;
use App\Domain\Supplier\Actions\AdvanceSupplierPastVerification;
use App\Domain\Supplier\Models\Supplier;
use App\Http\Controllers\Api\Mobile\V1\MobileController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Where a Supplier stands on verifying email and mobile, and resending the
 * email. The signed link in the email itself (`verification/verify/{id}/{hash}`)
 * stays a web-only route: it opens in the browser from the inbox and needs no
 * app.
 */
class SupplierEmailVerificationController extends MobileController
{
    /**
     * Verification status
     *
     * Whether email and mobile are verified and whether the mobile step is
     * required at all. Moves the supplier on when everything required is
     * already done (for example after the mobile requirement was switched off).
     * `is_complete` means nothing more is awaited before KYC.
     *
     * @response array{email_verified: bool, mobile_verified: bool, mobile_required: bool, is_complete: bool}
     */
    public function show(Request $request, AdvanceSupplierPastVerification $advance, MobileVerificationRequirement $requirement): JsonResponse
    {
        /** @var Supplier $supplier */
        $supplier = $request->user();

        $advance->handle($supplier);
        $supplier->refresh();

        return new JsonResponse([
            'email_verified' => $supplier->hasVerifiedEmail(),
            'mobile_verified' => $supplier->mobile_verified_at !== null,
            'mobile_required' => $requirement->isRequired(),
            'is_complete' => $advance->isComplete($supplier),
        ]);
    }

    /**
     * Resend the verification email
     *
     * Sends the email verification link again. Throttled to 6 a minute. When
     * the email is already verified nothing is sent and `status` says so.
     *
     * @response array{status: 'verification-link-sent'|'already-verified'}
     */
    public function send(Request $request): JsonResponse
    {
        /** @var Supplier $supplier */
        $supplier = $request->user();

        if ($supplier->hasVerifiedEmail()) {
            return new JsonResponse(['status' => 'already-verified']);
        }

        $supplier->sendEmailVerificationNotification();

        return new JsonResponse(['status' => 'verification-link-sent']);
    }
}