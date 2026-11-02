<?php

namespace App\Http\Controllers\Api\Mobile\V1\Onboarding;

use App\Concerns\ResolvesBusinessAccount;
use App\Domain\Billing\Queries\AccountSpendSummary;
use App\Http\Controllers\Api\Mobile\V1\MobileController;
use App\Http\Resources\Mobile\Onboarding\DashboardResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The business ERP's home summary (§33.3).
 */
class DashboardController extends MobileController
{
    use ResolvesBusinessAccount;

    /**
     * Dashboard
     *
     * A summary for the home screen: greeting, the account's standing and the one
     * thing to do about it, the usable wallet balance, and six months of spend.
     * Activated accounts only (`403 account_not_activated` otherwise). The login's
     * email must be verified (`403 code: email_unverified`). Every money value is
     * calculated on the server.
     */
    public function show(Request $request, AccountSpendSummary $spend): DashboardResource|JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User || $user->email_verified_at === null) {
            return new JsonResponse([
                'code' => 'email_unverified',
                'message' => __('Verify your email address to continue.'),
            ], 403);
        }

        $account = $this->businessAccountFor($request);

        return new DashboardResource([
            'account' => $account,
            'user' => $user,
            'spend' => $spend->forAccount($account),
        ]);
    }
}
