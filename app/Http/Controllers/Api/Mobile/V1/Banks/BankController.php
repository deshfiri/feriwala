<?php

namespace App\Http\Controllers\Api\Mobile\V1\Banks;

use App\Domain\Bank\Queries\BdBankLookups;
use App\Http\Controllers\Api\Mobile\V1\MobileController;
use App\Http\Requests\Mobile\Banks\BankBranchesRequest;
use Illuminate\Http\JsonResponse;

/**
 * The bank directory behind the bank payout-method form's Bank, District, Branch cascade (§39).
 *
 * Reference data shared by every account, so no owner scoping applies. Cached on
 * the server and invalidated by each re-import of the directory.
 */
class BankController extends MobileController
{
    public function __construct(protected BdBankLookups $lookups) {}

    /**
     * List banks
     *
     * Every bank a payout method may use, by name. Submit the chosen `bank_code`
     * with the payout method.
     *
     * @response array{data: list<array{bank_code: string, name: string, slug: string}>}
     */
    public function index(): JsonResponse
    {
        return new JsonResponse(['data' => $this->lookups->selectableBanks()]);
    }

    /**
     * List a bank's branches
     *
     * The bank's active branches in one district (a district `id` from the
     * locations lookup), by name. An unknown bank or a district with no branches
     * gives an empty list. Submit the chosen `routing_number` with the payout
     * method. `422` when `district_id` is missing.
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
