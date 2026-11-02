<?php

namespace App\Http\Controllers\Api\Mobile\V1\Supplier\Banks;

use App\Domain\Bank\Queries\BdBankLookups;
use App\Http\Controllers\Api\Mobile\V1\MobileController;
use App\Http\Requests\Mobile\Supplier\Banks\BankBranchesRequest;
use Illuminate\Http\JsonResponse;

/**
 * The bank directory behind the bank payout-method form. Reference data: it
 * carries no owner to scope by.
 */
class BankLookupController extends MobileController
{
    public function __construct(protected BdBankLookups $lookups) {}

    /**
     * Banks
     *
     * Every bank a payout method can use, by name. `bank_code` is what the
     * payout-method endpoints take.
     *
     * @response array{data: list<array{bank_code: string, name: string, slug: string}>}
     */
    public function banks(): JsonResponse
    {
        return new JsonResponse(['data' => $this->lookups->selectableBanks()]);
    }

    /**
     * Bank branches
     *
     * A bank's active branches in one district. Empty when the bank or district
     * does not resolve. `routing_number` is what the payout-method endpoints take.
     *
     * @response array{data: list<array{routing_number: string, name: string, branch_code: string|null}>}
     */
    public function branches(BankBranchesRequest $request, string $bankCode): JsonResponse
    {
        return new JsonResponse([
            'data' => $this->lookups->branchesFor($bankCode, (int) $request->validated('district_id')),
        ]);
    }
}