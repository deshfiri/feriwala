<?php

namespace App\Http\Controllers\Api\Mobile\V1\Supplier\Account;

use App\Domain\Supplier\Models\Supplier;
use App\Http\Controllers\Api\Mobile\V1\MobileController;
use App\Http\Requests\Mobile\Supplier\Account\UpdateSupplierPasswordRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * A Supplier changing their own password (D25).
 */
class SecurityController extends MobileController
{
    /**
     * Change the password
     *
     * Needs the current password. Every other phone signed in as this
     * supplier is signed out; the token making this request stays valid.
     * Responds `422` on `current_password` when it is wrong.
     */
    public function updatePassword(UpdateSupplierPasswordRequest $request): Response
    {
        /** @var Supplier $supplier */
        $supplier = $request->user();

        $supplier->update(['password' => $request->validated('password')]);

        $current = $supplier->currentAccessToken();
        $others = $supplier->tokens();

        if ($current instanceof PersonalAccessToken) {
            $others->whereKeyNot($current->getKey());
        }

        $others->delete();

        return response()->noContent();
    }
}