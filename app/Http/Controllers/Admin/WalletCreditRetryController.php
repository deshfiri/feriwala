<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Billing\Models\Payment;
use App\Domain\Wallet\Actions\CreditSettledPayment;
use App\Domain\Wallet\Policies\WalletPolicy;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Putting confirmed money where it was paid to go (§24, §26.4, P2-19).
 *
 * Settling a payment and crediting a wallet are two writes and the second can
 * fail on its own. When it does the payment stays `Paid` — the provider's
 * evidence is real and rolling it back would destroy it — and the failure is
 * recorded beside it. This is how somebody clears that.
 *
 * Safe to press twice, and that is not a convenience: the credit is idempotent
 * on the payment's own reference, so a second attempt finds the posting already
 * made and hands back the same transaction rather than paying anybody twice.
 * Pressing it on a payment that already landed does nothing at all.
 */
class WalletCreditRetryController extends Controller
{
    public function __construct(
        protected CreditSettledPayment $credits,
    ) {}

    public function store(Request $request, string $payment): RedirectResponse
    {
        $actor = $request->user();

        abort_if(! $actor instanceof User, 403);

        // Moving money into a wallet by hand is an adjustment in everything but
        // name, so it asks for the same permission.
        abort_unless(WalletPolicy::canAdjust($actor), 403);

        $record = Payment::query()->where('public_id', $payment)->firstOrFail();

        if (! $this->credits->applies($record)) {
            return back()->withErrors([
                'payment' => __('wallet.admin.credit_retry.not_wallet_money'),
            ]);
        }

        if (! $record->status->isSettled()) {
            return back()->withErrors([
                'payment' => __('wallet.admin.credit_retry.not_settled'),
            ]);
        }

        try {
            $transaction = $this->credits->handle($record);
        } catch (Throwable $throwable) {
            // Still not possible. The flag stays, the reason is updated, and
            // nothing about the payment itself has changed.
            $this->credits->flagUnapplied($record, $throwable->getMessage());

            return back()->withErrors(['payment' => $throwable->getMessage()]);
        }

        if ($transaction === null) {
            return back()->withErrors([
                'payment' => (string) $record->fresh()?->wallet_credit_failure_reason,
            ]);
        }

        return back()->with('success', __('wallet.admin.credit_retry.applied', [
            'reference' => $transaction->reference,
        ]));
    }
}
