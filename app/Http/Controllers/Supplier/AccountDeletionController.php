<?php

namespace App\Http\Controllers\Supplier;

use App\Domain\Account\Actions\RequestAccountDeletion;
use App\Domain\Account\Actions\WithdrawAccountDeletion;
use App\Domain\Account\Models\AccountDeletionRequest;
use App\Domain\Supplier\Models\Supplier;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * A Supplier asking to have its own account deleted — the Supplier counterpart
 * of {@see \App\Http\Controllers\Settings\AccountDeletionController}. A Supplier
 * cannot delete itself; it asks, and an authorised member of staff decides.
 */
class AccountDeletionController extends Controller
{
    public function store(Request $request, RequestAccountDeletion $requestDeletion): RedirectResponse
    {
        /** @var Supplier $supplier */
        $supplier = $request->user('supplier');

        $validated = $request->validate([
            'current_password' => ['required', 'current_password:supplier'],
            'reason' => ['required', 'string', 'min:'.RequestAccountDeletion::MINIMUM_REASON, 'max:1000'],
        ]);

        try {
            $requestDeletion->handle($supplier, $validated['reason']);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['reason' => $exception->getMessage()]);
        }

        return back()->with('success', __('deletion.requested'));
    }

    public function destroy(Request $request, WithdrawAccountDeletion $withdraw): RedirectResponse
    {
        /** @var Supplier $supplier */
        $supplier = $request->user('supplier');

        $pending = AccountDeletionRequest::query()->where('supplier_id', $supplier->id)->where('status', 'pending')->first();

        if ($pending !== null) {
            try {
                $withdraw->handle($pending, $supplier);
            } catch (InvalidArgumentException $exception) {
                throw ValidationException::withMessages(['reason' => $exception->getMessage()]);
            }
        }

        return back()->with('success', __('deletion.withdrawn'));
    }
}
