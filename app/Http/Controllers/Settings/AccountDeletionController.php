<?php

namespace App\Http\Controllers\Settings;

use App\Domain\Account\Actions\RequestAccountDeletion;
use App\Domain\Account\Actions\WithdrawAccountDeletion;
use App\Domain\Account\Models\AccountDeletionRequest;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Asking to have your own account deleted.
 *
 * Nobody deletes their own account here — there is no such action. A person asks,
 * confirms with their password, and an authorised member of staff decides. They
 * can change their mind until it is decided.
 *
 * Self-scoped: it acts on the signed-in person's own request and takes no
 * identifier from the address.
 */
class AccountDeletionController extends Controller
{
    public function store(Request $request, RequestAccountDeletion $requestDeletion): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user('web');

        $validated = $request->validate([
            'current_password' => ['required', 'current_password:web'],
            'reason' => ['required', 'string', 'min:'.RequestAccountDeletion::MINIMUM_REASON, 'max:1000'],
        ]);

        try {
            $requestDeletion->handle($user, $validated['reason']);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['reason' => $exception->getMessage()]);
        }

        return back()->with('success', __('deletion.requested'));
    }

    public function destroy(Request $request, WithdrawAccountDeletion $withdraw): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user('web');

        $pending = AccountDeletionRequest::query()->where('user_id', $user->id)->where('status', 'pending')->first();

        if ($pending !== null) {
            try {
                $withdraw->handle($pending, $user);
            } catch (InvalidArgumentException $exception) {
                throw ValidationException::withMessages(['reason' => $exception->getMessage()]);
            }
        }

        return back()->with('success', __('deletion.withdrawn'));
    }
}
