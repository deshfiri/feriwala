<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Wallet\Actions\ConvertWalletBalance;
use App\Domain\Wallet\Exceptions\WalletOperationRefused;
use App\Domain\Wallet\Models\Wallet;
use App\Domain\Wallet\Policies\WalletPolicy;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Money\Currency;
use App\Support\Money\DecimalAmount;
use App\Support\Money\Rules\DecimalAmountRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Turning an account's platform credit into withdrawable money (D-new).
 *
 * Behind `wallet.convert_balance`, two-factor, a freshly confirmed password and
 * a reason, for the same reason a manual adjustment is: a person is deciding
 * that money which could only be spent inside Banij may now leave it. The
 * action checks the permission again, so no other caller can skip it.
 */
class WalletConversionController extends Controller
{
    public function __construct(
        protected ConvertWalletBalance $conversion,
    ) {}

    public function store(Request $request, string $wallet): RedirectResponse
    {
        $actor = $request->user();

        abort_if(! $actor instanceof User, 403);
        abort_unless(WalletPolicy::canConvertBalance($actor), 403);

        /** @var Wallet $record */
        $record = Wallet::query()->where('public_id', $wallet)->firstOrFail();

        $currency = Currency::from($record->currency_code);

        $validated = $request->validate([
            'amount' => ['required', new DecimalAmountRule($currency)],
            'reason' => ['required', 'string', 'min:10', 'max:1000'],

            // Minted by the form once per attempt, so a double submit converts
            // once.
            'idempotency_key' => ['required', 'string', 'min:8', 'max:64'],
        ]);

        try {
            $this->conversion->handle(
                $record,
                $actor,
                DecimalAmount::parse($validated['amount'], $currency),
                $validated['reason'],
                $validated['idempotency_key'],
            );
        } catch (WalletOperationRefused $refused) {
            return back()->withErrors(['amount' => $refused->getMessage()]);
        }

        return back()->with('success', __('wallet.admin.converted'));
    }
}
