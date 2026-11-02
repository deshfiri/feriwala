<?php

namespace App\Http\Controllers\Api\Mobile\V1\Wholesale\Concerns;

use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Catalog\Enums\SalesChannel;
use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What every wholesale controller needs: the signed-in person, and the package
 * check that the web does with `abort_unless(..., 403)`.
 *
 * Expects the using class to hold a `ProductEligibility` as `$eligibility`.
 */
trait GuardsWholesaleFacility
{
    /**
     * @throws HttpResponseException 403 `wholesale_not_allowed` when the package does not include wholesale
     */
    protected function requireWholesale(BusinessAccount $account): void
    {
        if (! $this->eligibility->allowsChannel($account, SalesChannel::Wholesale)) {
            throw new HttpResponseException(new JsonResponse([
                'code' => 'wholesale_not_allowed',
                'message' => __('wholesale.cart.not_included'),
            ], 403));
        }
    }

    protected function person(Request $request): User
    {
        $user = $request->user();

        abort_if(! $user instanceof User, 403);

        return $user;
    }
}
