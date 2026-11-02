<?php

namespace App\Http\Controllers\Api\Mobile\V1\Onboarding;

use App\Concerns\ResolvesBusinessAccount;
use App\Domain\Account\Actions\SkipMobileVerificationIfNotRequired;
use App\Domain\Account\OnboardingProgress;
use App\Http\Controllers\Api\Mobile\V1\MobileController;
use App\Http\Resources\Mobile\Onboarding\OnboardingProgressResource;
use Illuminate\Http\Request;

/**
 * The activation status (§33.4, §5.4).
 */
class OnboardingController extends MobileController
{
    use ResolvesBusinessAccount;

    /**
     * Activation status
     *
     * The stepper: which step the account is on, which are done, the one thing to
     * do next, and the reviewer's feedback when KYC was sent back. Reachable
     * before and after activation. A login with no business account (platform
     * staff, an invitee) gets `403` `This area is for business accounts.`
     * Fetching it also moves an account past a mobile-verification step an
     * administrator has since switched off.
     */
    public function status(Request $request, OnboardingProgress $progress, SkipMobileVerificationIfNotRequired $skip): OnboardingProgressResource
    {
        $account = $this->businessAccountFor($request);

        $skip->handle($account);

        return new OnboardingProgressResource($progress->for($account->fresh() ?? $account));
    }
}
