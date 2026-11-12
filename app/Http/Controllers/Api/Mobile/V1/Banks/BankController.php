<?php

namespace App\Http\Controllers\Api\Mobile\V1\Banks;

use App\Domain\Bank\Queries\BdBankLookups;
use App\Http\Controllers\Api\Mobile\V1\MobileController;
use App\Http\Requests\Mobile\Banks\BankBranchesRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
     * Every bank a payout method may use, by name, with its `id`, `bank_code`,
     * `slug`, `aliases` and `branch_count`. Submit the chosen `bank_code` with the
     * payout method. Add `?include=branches` to get every bank with all of its
     * branches in the one answer (each branch carries its own `id`,
     * `routing_number`, codes, district, SWIFT code, address and contact
     * details) — a large response, cached on the server, meant to be kept on the
     * device.
     *
     * @response array{data: list<array{id: int, bank_code: string, name: string, slug: string, aliases: list<string>, branch_count: int, branches?: list<array<string, mixed>>}>}
     */
    public function index(Request $request): JsonResponse
    {
        return new JsonResponse([
            'data' => $request->query('include') === 'branches'
                ? $this->lookups->banksWithBranches()
                : $this->lookups->selectableBanks(),
        ]);
    }

    /**
     * List a bank's branches
     *
     * The bank's active branches, by name: in one district when `district_id` (a
     * district `id` from the locations lookup) is given, every branch of the bank
     * when it is left out. An unknown bank or a district with no branches gives an
     * empty list. Each branch has its `id`, `bank_code`, `routing_number`,
     * `branch_code`, `district_id` and names, `swift_code`, `address`,
     * `telephone`, `email` and `fax`. Submit the chosen `routing_number` with the
     * payout method.
     *
     * @response array{data: list<array{id: int, bank_code: string, routing_number: string, name: string, slug: string, branch_code: string|null, original_branch_code: string|null, district_id: int|null, district_name_en: string|null, district_name_bn: string|null, district_source_name: string, swift_code: string|null, address: string|null, telephone: string|null, email: string|null, fax: string|null}>}
     */
    public function branches(BankBranchesRequest $request, string $bankCode): JsonResponse
    {
        $district = $request->validated('district_id');

        return new JsonResponse([
            'data' => $this->lookups->branchesFor($bankCode, $district === null ? null : (int) $district),
        ]);
    }
}
