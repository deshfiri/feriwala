<?php

namespace App\Http\Controllers\Api\Mobile\V1\Account;

use App\Domain\Account\Actions\SendMobileVerificationCode;
use App\Domain\Account\Actions\SkipMobileVerificationIfNotRequired;
use App\Domain\Account\Actions\VerifyMobile;
use App\Domain\Account\Exceptions\ResendTooSoon;
use App\Domain\Account\MobileVerificationRequirement;
use App\Domain\Account\VerificationCodes;
use App\Domain\Notification\Exceptions\SmsEventSwitchedOff;
use App\Http\Controllers\Api\Mobile\V1\MobileController;
use App\Http\Requests\Mobile\Account\VerifyMobileRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Confirming the mobile number an account registered with (§5.1, P1-9).
 *
 * The codes and their limits live in {@see VerificationCodes}. Nothing is sent
 * by reading the status: a code goes out only when it is asked for. Reachable
 * before activation.
 */
class MobileVerificationController extends MobileController
{
    public function __construct(
        protected VerificationCodes $codes,
        protected MobileVerificationRequirement $requirement,
        protected SkipMobileVerificationIfNotRequired $skip,
    ) {}

    /**
     * Mobile verification status
     *
     * Whether this person has a mobile number still to confirm, the number masked
     * (`+88017****5678`), whether a code is already on its way, and how long codes
     * live. `required: false` means an administrator switched the requirement off
     * or the number is already confirmed.
     *
     * @response array{data: array{mobile: string|null, verified: bool, required: bool, code_pending: bool, code_length: int, expires_in_minutes: int, resend_after_seconds: int}}
     */
    public function show(Request $request): JsonResponse
    {
        $user = $this->userFor($request);
        $this->skipIfNotRequired($user);

        $mobile = (string) $user->mobile;
        $pending = $this->hasSomethingToVerify($user);

        return new JsonResponse(['data' => [
            'mobile' => $user->mobile === null ? null : $this->mask($mobile),
            'verified' => $user->mobile_verified_at !== null,
            'required' => $pending,
            'code_pending' => $pending && $this->codes->isPending(SendMobileVerificationCode::PURPOSE, $mobile),
            'code_length' => VerificationCodes::LENGTH,
            'expires_in_minutes' => intdiv(VerificationCodes::TTL_SECONDS, 60),
            'resend_after_seconds' => $pending ? $this->codes->secondsUntilResend(SendMobileVerificationCode::PURPOSE, $mobile) : 0,
        ]]);
    }

    /**
     * Send a mobile verification code
     *
     * Texts a six-digit code to the number on file. `422` on `resend` while the
     * cooldown (60 seconds) has not passed, or when an administrator has switched
     * these texts off; `409` with `code: nothing_to_verify` when there is no
     * unconfirmed number or the requirement is off. Limited to three requests a
     * minute.
     *
     * @response 202 array{message: string, mobile: string}
     */
    public function send(Request $request, SendMobileVerificationCode $send): JsonResponse
    {
        $user = $this->userFor($request);
        $this->skipIfNotRequired($user);

        if (! $this->hasSomethingToVerify($user)) {
            return $this->nothingToVerify();
        }

        try {
            $send->handle($user);
        } catch (ResendTooSoon) {
            throw ValidationException::withMessages(['resend' => __('common.verify_mobile.wait')]);
        } catch (SmsEventSwitchedOff) {
            throw ValidationException::withMessages(['resend' => __('sms.event_switch.code_off')]);
        }

        $mobile = $this->mask((string) $user->mobile);

        return new JsonResponse([
            'message' => __('common.verify_mobile.sent', ['mobile' => $mobile]),
            'mobile' => $mobile,
        ], Response::HTTP_ACCEPTED);
    }

    /**
     * Verify the mobile number
     *
     * Checks the code. Wrong, expired, used-up and never-sent codes all answer the
     * same `422` on `code`, so the answer reveals nothing; five wrong guesses
     * destroy the code. On success the number is confirmed and the business moves
     * on in the onboarding funnel. `409` with `code: nothing_to_verify` when
     * there is nothing to confirm. Limited to ten requests a minute.
     *
     * @response array{message: string}
     */
    public function verify(VerifyMobileRequest $request, VerifyMobile $verify): JsonResponse
    {
        $user = $this->userFor($request);
        $this->skipIfNotRequired($user);

        if (! $this->hasSomethingToVerify($user)) {
            return $this->nothingToVerify();
        }

        if (! $verify->handle($user, (string) $request->validated('code'))) {
            throw ValidationException::withMessages(['code' => __('common.verify_mobile.invalid')]);
        }

        return new JsonResponse(['message' => __('common.verify_mobile.verified')]);
    }

    protected function userFor(Request $request): User
    {
        $user = $request->user();

        abort_unless($user instanceof User, 403);

        return $user;
    }

    /**
     * A number that is on file, not yet confirmed, and actually required.
     */
    protected function hasSomethingToVerify(User $user): bool
    {
        if (! $this->requirement->isRequired()) {
            return false;
        }

        return $user->mobile !== null && $user->mobile_verified_at === null;
    }

    protected function skipIfNotRequired(User $user): void
    {
        if ($user->businessAccount !== null) {
            $this->skip->handle($user->businessAccount);
        }
    }

    protected function nothingToVerify(): JsonResponse
    {
        return new JsonResponse([
            'code' => 'nothing_to_verify',
            'message' => __('There is no mobile number to verify.'),
        ], Response::HTTP_CONFLICT);
    }

    /**
     * `+8801712345678` becomes `+88017****5678`.
     */
    protected function mask(string $number): string
    {
        $length = strlen($number);

        if ($length <= 8) {
            return str_repeat('*', $length);
        }

        return substr($number, 0, 6).str_repeat('*', $length - 10).substr($number, -4);
    }
}
