<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Kyc\Actions\RequestKycUpdate;
use App\Domain\Kyc\Models\KycSubmission;
use App\Http\Controllers\Controller;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Asking a trading business for fresh KYC (§7.2).
 *
 * Its own controller rather than another verb on the review queue: the subject
 * is the **account**, not a submission, and there is no submission to act on
 * until this creates one. Hanging it off `admin/kyc/{submission}` would mean
 * naming a round that has nothing to do with the request.
 */
class KycUpdateRequestController extends Controller
{
    public function __invoke(
        Request $request,
        BusinessAccount $account,
        RequestKycUpdate $requestUpdate,
    ): RedirectResponse {
        Gate::authorize('requestUpdate', [KycSubmission::class, $account]);

        $validated = $request->validate([
            // Internal: why we asked. Never shown to the account holder.
            'reason' => ['required', 'string', 'max:1000'],

            // What they are told to do. Required, because "update your KYC"
            // with no explanation produces the same documents back.
            'instructions' => ['required', 'string', 'max:1000'],

            // §7.2 offers a deadline beside the standing window — "within seven
            // days" is a different instruction from the configured default.
            'deadline' => ['nullable', 'date', 'after:today'],

            /*
             * Which documents to ask for. Absent means everything that applies
             * to this account, which is the common case; a present-but-empty
             * array is a request for nothing and is refused by the action rather
             * than quietly widened back to everything.
             */
            'document_type_ids' => ['sometimes', 'array'],
            'document_type_ids.*' => ['string', 'max:26'],
        ]);

        /** @var User $actor */
        $actor = $request->user();

        try {
            $requestUpdate->handle(
                account: $account,
                requestedBy: $actor,
                reason: $validated['reason'],
                instructions: $validated['instructions'],
                deadline: isset($validated['deadline'])
                    ? CarbonImmutable::parse($validated['deadline'])
                    : null,
                documentTypeIds: $validated['document_type_ids'] ?? null,
            );
        } catch (InvalidArgumentException $exception) {
            // A round already in progress is a rejected form, not a 500.
            throw ValidationException::withMessages(['reason' => $exception->getMessage()]);
        }

        return back()->with('success', __('Verification update requested.'));
    }
}
