<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Wallet\Actions\CorrectLedgerEntry;
use App\Domain\Wallet\Enums\LedgerDirection;
use App\Domain\Wallet\Exceptions\WalletOperationRefused;
use App\Domain\Wallet\Models\Wallet;
use App\Domain\Wallet\Models\WalletTransaction;
use App\Domain\Wallet\Policies\WalletPolicy;
use App\Http\Controllers\Controller;
use App\Http\Middleware\RequireTwoFactorForSensitiveRoles;
use App\Models\User;
use App\Support\Money\Currency;
use App\Support\Money\DecimalAmount;
use App\Support\Money\Rules\DecimalAmountRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Moving money by hand, and undoing a posting that has already happened (§23.2,
 * §32.2).
 *
 * The only two operations in the wallet where a **person** rather than an event
 * decides a balance should change, so they are the two that carry §32.2's
 * escalation. Four things stand in front of each:
 *
 *   - a permission of its own — deciding a credit and undoing yesterday's
 *     mistake are different jobs, and `wallet.adjust_wallet` is not
 *     `wallet.reverse_transaction`;
 *   - two-factor authentication, enforced on the whole panel for every role that
 *     holds a sensitive action ({@see RequireTwoFactorForSensitiveRoles});
 *   - a freshly confirmed password, on these routes specifically, because a
 *     session left open on a shared desk must not be able to post money;
 *   - a reason, which is required rather than encouraged and goes to the audit
 *     log with the actor's name.
 *
 * Neither operation edits anything. An adjustment is a new posting; a reversal
 * is a new posting pointing at the entry it answers. §23.2 allows nothing else,
 * and the database will not permit it in any case.
 */
class WalletAdjustmentController extends Controller
{
    public function __construct(
        protected CorrectLedgerEntry $corrections,
    ) {}

    /**
     * Post a manual adjustment against a wallet (§23.1).
     */
    public function store(Request $request, string $wallet): RedirectResponse
    {
        $actor = $this->actor($request);
        $record = $this->wallet($wallet);

        abort_unless(WalletPolicy::canAdjust($actor), 403);

        $currency = Currency::from($record->currency_code);

        $validated = $request->validate([
            // Decimal Taka (§36.1) — every human-facing money field in the
            // panel takes them the same way; nothing here parses minor units
            // the browser was never asked to send.
            'amount' => ['required', new DecimalAmountRule($currency)],
            'direction' => ['required', Rule::enum(LedgerDirection::class)],

            // Not `nullable`. A balance that changed for no recorded reason is
            // the first thing an auditor asks about (§32.2).
            'reason' => ['required', 'string', 'min:10', 'max:1000'],

            // Staff-only, and never shown to the account holder (§23.2).
            'internal_note' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $transaction = $this->corrections->adjust(
                wallet: $record,
                actor: $actor,
                amount: DecimalAmount::parse($validated['amount'], $currency),
                direction: LedgerDirection::from($validated['direction']),
                reason: $validated['reason'],
                internalNote: $validated['internal_note'] ?? null,
            );
        } catch (WalletOperationRefused $refused) {
            // A refusal is an answer to what was asked — most often "there is
            // not that much in the wallet" — not a failure of the request.
            return back()->withErrors(['amount' => $refused->getMessage()]);
        }

        return back()->with('success', __('wallet.admin.adjusted', [
            'reference' => $transaction->reference,
        ]));
    }

    /**
     * Reverse what a transaction posted (§23.2).
     */
    public function reverse(Request $request, string $wallet, string $transaction): RedirectResponse
    {
        $actor = $this->actor($request);
        $record = $this->wallet($wallet);

        abort_unless(WalletPolicy::canReverse($actor), 403);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ]);

        $movement = WalletTransaction::query()
            ->where('wallet_id', $record->id)
            ->where('public_id', $transaction)
            ->with('ledgerEntries')
            ->firstOrFail();

        $entry = $movement->ledgerEntries->last();

        if ($entry === null) {
            /*
             * A hold or a reservation that has not been captured moved no
             * value, so there is nothing to reverse — releasing it is the
             * operation that applies, and it belongs to whatever placed it.
             */
            return back()->withErrors(['reason' => __('wallet.admin.nothing_to_reverse')]);
        }

        try {
            $reversal = $this->corrections->reverse($entry, $actor, $validated['reason']);
        } catch (WalletOperationRefused $refused) {
            return back()->withErrors(['reason' => $refused->getMessage()]);
        }

        return back()->with('success', __('wallet.admin.reversed', [
            'reference' => $reversal->reference,
        ]));
    }

    protected function wallet(string $publicId): Wallet
    {
        /** @var Wallet $wallet */
        $wallet = Wallet::query()->where('public_id', $publicId)->firstOrFail();

        return $wallet;
    }

    protected function actor(Request $request): User
    {
        $actor = $request->user();

        abort_if(! $actor instanceof User, 403);

        return $actor;
    }
}
