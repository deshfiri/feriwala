<?php

namespace App\Http\Controllers\Api\Mobile\V1\Supplier\Account;

use App\Domain\Account\Actions\RequestAccountDeletion;
use App\Domain\Account\Actions\WithdrawAccountDeletion;
use App\Domain\Account\Models\AccountDeletionRequest;
use App\Domain\Account\Queries\OwnDeletionRequest;
use App\Domain\Supplier\Models\Supplier;
use App\Http\Controllers\Api\Mobile\V1\MobileController;
use App\Http\Requests\Mobile\Supplier\Account\RequestSupplierDeletionRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * A Supplier asking to have its own account deleted. A Supplier cannot delete
 * itself: it asks, and an authorised member of staff decides.
 */
class AccountDeletionController extends MobileController
{
    /**
     * Ask for the account to be deleted
     *
     * Needs the current password and a reason. Puts a request in the staff
     * queue; asking again while one is open returns that same request.
     * Throttled to 5 a minute. Responds `422` on `current_password` or `reason`.
     *
     * @response 201 array{data: array{id: string, status: string, reason: string, requested_at: string, decision_note: string|null}|null}
     */
    public function store(RequestSupplierDeletionRequest $request, RequestAccountDeletion $requestDeletion, OwnDeletionRequest $deletion): JsonResponse
    {
        /** @var Supplier $supplier */
        $supplier = $request->user();

        try {
            $requestDeletion->handle($supplier, $request->validated('reason'));
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['reason' => $exception->getMessage()]);
        }

        return new JsonResponse(['data' => $deletion->for($supplier)], 201);
    }

    /**
     * Withdraw the deletion request
     *
     * Withdraws the supplier's pending request. Succeeds quietly when there is
     * none.
     */
    public function destroy(Request $request, WithdrawAccountDeletion $withdraw): Response
    {
        /** @var Supplier $supplier */
        $supplier = $request->user();

        $pending = AccountDeletionRequest::query()
            ->where('supplier_id', $supplier->id)
            ->where('status', 'pending')
            ->first();

        if ($pending !== null) {
            try {
                $withdraw->handle($pending, $supplier);
            } catch (InvalidArgumentException $exception) {
                throw ValidationException::withMessages(['reason' => $exception->getMessage()]);
            }
        }

        return response()->noContent();
    }
}