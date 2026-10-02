<?php

namespace App\Http\Controllers\Erp;

use App\Concerns\ResolvesBusinessAccount;
use App\Domain\Account\Actions\SkipMobileVerificationIfNotRequired;
use App\Domain\Account\OnboardingProgress;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The activation status screen (§33.4, §5.4).
 *
 * Reachable throughout onboarding and after it — someone who has just been
 * activated should be able to see that, not hit a 404 on the page that has been
 * guiding them.
 */
class OnboardingController extends Controller
{
    use ResolvesBusinessAccount;

    public function status(Request $request, OnboardingProgress $progress, SkipMobileVerificationIfNotRequired $skip): Response
    {
        $account = $this->businessAccountFor($request);

        /*
         * Every unactivated account is redirected back to this exact page
         * (§5.4's middleware), which is what makes it the one reliable place
         * to catch an account still waiting on a mobile-verification step
         * nobody can complete after an administrator switched it off -- a
         * no-op for everyone else.
         */
        $skip->handle($account);

        return Inertia::render('onboarding/status', [
            'progress' => $progress->for($account->fresh() ?? $account),
        ]);
    }
}
