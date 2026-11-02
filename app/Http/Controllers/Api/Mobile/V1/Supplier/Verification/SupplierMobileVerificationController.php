<?php

namespace App\Http\Controllers\Api\Mobile\V1\Supplier\Verification;

use App\Domain\Account\Exceptions\ResendTooSoon;
use App\Domain\Account\MobileVerificationRequirement;
use App\Domain\Notification\Exceptions\SmsEventSwitchedOff;
use App\Domain\Supplier\Actions\SendSupplierMobileVerificationCode;
use App\Domain\Supplier\Actions\VerifySupplierMobile;
use App\Domain\Supplier\Models\Supplier;
use App\Http\Controllers\Api\Mobile\V1\MobileController;
use App\Http\Requests\Mobile\Supplier\Verification\VerifySupplierMobileRequest;
use App\Http\Resources\Mobile\Supplier\Auth\SupplierResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * A Supplier confirming their mobile number with an SMS code (D25).
 *
 * Every call answers `409 code: mobile_verification_not_required` while an
 * administrator has the requirement switched off; the web redirects away.
 */
class SupplierMobileVerificationController extends MobileController
{
    public function __construct(
        protected MobileVerificationRequirement $requirement,
    ) {}

    /**
     * Mobile verification status
     *
     * The mobile number on file and whether it is verified, and whether the
     * platform requires verifying it at all.
     *
     * @response array{mobile: string, mobile_verified: bool, required: bool}
     */
    public function show(Request $request): JsonResponse
    {
        /** @var Supplier $supplier */
        $supplier = $request->user();

        return new JsonResponse([
            'mobile' => $supplier->mobile,
            'mobile_verified' => $supplier->mobile_verified_at !== null,
            'required' => $this->requirement->isRequired(),
        ]);
    }

    /**
     * Send a mobile verification code
     *
     * Sends a code by SMS to the supplier's mobile number. Responds `422` on
     * `mobile` when asked again before the cooldown ends or when an
     * administrator has switched these SMS codes off, and `409
     * mobile_verification_not_required` when the platform does not require
     * mobile verification.
     *
     * @response array{status: 'mobile-code-sent'}
     */
    public function send(Request $request, SendSupplierMobileVerificationCode $action): JsonResponse
    {
        /** @var Supplier $supplier */
        $supplier = $request->user();

        if ($refusal = $this->refuseWhenNotRequired()) {
            return $refusal;
        }

        try {
            $action->handle($supplier);
        } catch (ResendTooSoon $exception) {
            throw ValidationException::withMessages(['mobile' => $exception->getMessage()]);
        } catch (SmsEventSwitchedOff) {
            throw ValidationException::withMessages(['mobile' => __('sms.event_switch.code_off')]);
        }

        return new JsonResponse(['status' => 'mobile-code-sent']);
    }

    /**
     * Verify the mobile number
     *
     * Checks the SMS code. On success the number is marked verified and the
     * supplier moves on towards KYC; the updated supplier is returned. Responds
     * `422` on `code` when it is wrong or expired.
     */
    public function verify(VerifySupplierMobileRequest $request, VerifySupplierMobile $action): JsonResponse|SupplierResource
    {
        /** @var Supplier $supplier */
        $supplier = $request->user();

        if ($refusal = $this->refuseWhenNotRequired()) {
            return $refusal;
        }

        if (! $action->handle($supplier, $request->validated('code'))) {
            throw ValidationException::withMessages(['code' => __('This code is incorrect or has expired.')]);
        }

        return new SupplierResource($supplier->refresh());
    }

    protected function refuseWhenNotRequired(): ?JsonResponse
    {
        if ($this->requirement->isRequired()) {
            return null;
        }

        return new JsonResponse([
            'code' => 'mobile_verification_not_required',
            'message' => __('Mobile verification is not required.'),
        ], 409);
    }
}