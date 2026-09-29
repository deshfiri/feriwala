<?php

namespace App\Http\Controllers;

use App\Domain\Bank\Queries\BdBankLookups;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The bank directory's cached lookups behind the payout-method form's
 * Bank -> District -> Branch cascade (§39: never the whole directory on one
 * page). District selection itself reuses the existing
 * {@see LocationLookupController}; this resolves only the bank list and,
 * once both a bank and a district are chosen, that bank's branches there.
 *
 * Registered once under the `web` guard and once under `auth:supplier` —
 * the same controller either way, since reference data carries no owner to
 * scope by.
 */
class BankLookupController extends Controller
{
    public function __construct(protected BdBankLookups $lookups) {}

    public function banks(): JsonResponse
    {
        return response()->json(['data' => $this->lookups->selectableBanks()]);
    }

    public function branches(Request $request, string $bankCode): JsonResponse
    {
        $validated = $request->validate([
            'district_id' => ['required', 'integer'],
        ]);

        return response()->json([
            'data' => $this->lookups->branchesFor($bankCode, (int) $validated['district_id']),
        ]);
    }
}
