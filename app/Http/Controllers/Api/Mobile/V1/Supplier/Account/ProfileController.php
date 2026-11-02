<?php

namespace App\Http\Controllers\Api\Mobile\V1\Supplier\Account;

use App\Domain\Account\Queries\OwnDeletionRequest;
use App\Domain\Supplier\Models\Supplier;
use App\Http\Controllers\Api\Mobile\V1\MobileController;
use App\Http\Requests\Mobile\Supplier\Account\UpdateSupplierProfileRequest;
use App\Http\Resources\Mobile\Supplier\Account\SupplierProfileResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A Supplier's own profile (D25). Read from and written to the signed-in
 * Supplier only. Email and mobile are verified identifiers and are not editable.
 */
class ProfileController extends MobileController
{
    /**
     * Supplier profile
     *
     * The business details on file and the supplier's own open or refused
     * account-deletion request, if any.
     *
     * @response array{data: SupplierProfileResource, deletion_request: array{id: string, status: string, reason: string, requested_at: string, decision_note: string|null}|null}
     */
    public function show(Request $request, OwnDeletionRequest $deletion): JsonResponse
    {
        /** @var Supplier $supplier */
        $supplier = $request->user();

        return new JsonResponse([
            'data' => new SupplierProfileResource($supplier),
            'deletion_request' => $deletion->for($supplier),
        ]);
    }

    /**
     * Update the profile
     *
     * Changes the contact person, business address and preferred language
     * (`en` or `bn`). The language applies to later requests and to
     * notification wording.
     */
    public function update(UpdateSupplierProfileRequest $request): SupplierProfileResource
    {
        /** @var Supplier $supplier */
        $supplier = $request->user();

        $supplier->update($request->validated());

        return new SupplierProfileResource($supplier->refresh());
    }
}