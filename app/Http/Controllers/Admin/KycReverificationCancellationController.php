<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Kyc\Actions\CancelKycReverification;
use App\Domain\Kyc\Models\KycSubmission;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Withdrawing a re-verification nobody has answered (§7.2).
 *
 * Its own controller rather than a verb on the review queue, for the same
 * reason {@see KycUpdateRequestController} is separate: the queue decides
 * rounds a business has *submitted*, and this acts on one it has not. A
 * reviewer approving an unanswered round to make it go away is exactly the
 * lie in the record this exists to prevent.
 *
 * Every question about whether the round may be withdrawn — unanswered, not
 * already decided, not already withdrawn — is settled by the action under its
 * row lock. Re-checking here would be a second copy, and the one that drifts
 * is always the one further from the transaction.
 */
class KycReverificationCancellationController extends Controller
{
    public function __invoke(
        Request $request,
        KycSubmission $submission,
        CancelKycReverification $cancel,
    ): RedirectResponse {
        Gate::authorize('cancelUpdate', $submission);

        $validated = $request->validate([
            /*
             * Required, and it stays in the account history. A requirement
             * that appeared and then vanished with no explanation is worse
             * than one that was never made — the business was notified, and
             * may have been restricted, while it stood.
             */
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        /** @var User $actor */
        $actor = $request->user();

        try {
            $cancel->handle($submission, $actor, $validated['reason']);
        } catch (InvalidArgumentException $exception) {
            // An answered or already-withdrawn round is a rejected form, not
            // a 500 — two staff reaching the same round is ordinary.
            throw ValidationException::withMessages(['reason' => $exception->getMessage()]);
        }

        return back()->with('success', __('kyc.request.withdrawn'));
    }
}
