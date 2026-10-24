<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Supplier\Actions\AdjustSupplierWallet;
use App\Domain\Supplier\Actions\ConvertSupplierWalletBalance;
use App\Domain\Supplier\Exceptions\SupplierWalletOperationRefused;
use App\Domain\Supplier\Models\SupplierWallet;
use App\Domain\Wallet\Enums\BalanceType;
use App\Domain\Wallet\Enums\LedgerDirection;
use App\Domain\Wallet\Policies\WalletPolicy;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Money\Currency;
use App\Support\Money\DecimalAmount;
use App\Support\Money\Rules\DecimalAmountRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Moving money in a Supplier's wallet by hand, and turning its platform credit
 * into withdrawable money (D-new).
 *
 * The same two jobs, behind the same two permissions, as an account wallet's —
 * `wallet.adjust_wallet` and `wallet.convert_balance` — with a freshly confirmed
 * password and two-factor on the route, a reason that is required, and the
 * permission checked again inside the action. A top-up must say whether the
 * money is platform credit or withdrawable.
 */
class SupplierWalletAdjustmentController extends Controller
{
    public function __construct(
        protected AdjustSupplierWallet $adjustments,
        protected ConvertSupplierWalletBalance $conversions,
    ) {}

    public function adjust(Request $request, SupplierWallet $wallet): RedirectResponse
    {
        $actor = $this->actor($request);

        abort_unless(WalletPolicy::canAdjust($actor), 403);

        $currency = Currency::from($wallet->currency_code);

        $validated = $request->validate([
            'amount' => ['required', new DecimalAmountRule($currency)],
            'direction' => ['required', Rule::enum(LedgerDirection::class)],
            'balance_type' => [
                Rule::requiredIf(fn () => $request->input('direction') === LedgerDirection::Credit->value),
                'nullable',
                Rule::enum(BalanceType::class),
            ],
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
            'internal_note' => ['nullable', 'string', 'max:1000'],
            'idempotency_key' => ['required', 'string', 'min:8', 'max:64'],
        ]);

        try {
            $this->adjustments->handle(
                wallet: $wallet,
                actor: $actor,
                amount: DecimalAmount::parse($validated['amount'], $currency),
                direction: LedgerDirection::from($validated['direction']),
                reason: $validated['reason'],
                idempotencyKey: $validated['idempotency_key'],
                balanceType: isset($validated['balance_type']) ? BalanceType::from($validated['balance_type']) : null,
                internalNote: $validated['internal_note'] ?? null,
            );
        } catch (SupplierWalletOperationRefused $refused) {
            return back()->withErrors(['amount' => $refused->getMessage()]);
        }

        return back()->with('success', __('wallet.admin.supplier_adjusted'));
    }

    public function convert(Request $request, SupplierWallet $wallet): RedirectResponse
    {
        $actor = $this->actor($request);

        abort_unless(WalletPolicy::canConvertBalance($actor), 403);

        $currency = Currency::from($wallet->currency_code);

        $validated = $request->validate([
            'amount' => ['required', new DecimalAmountRule($currency)],
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
            'idempotency_key' => ['required', 'string', 'min:8', 'max:64'],
        ]);

        try {
            $this->conversions->handle(
                $wallet,
                $actor,
                DecimalAmount::parse($validated['amount'], $currency),
                $validated['reason'],
                $validated['idempotency_key'],
            );
        } catch (SupplierWalletOperationRefused $refused) {
            return back()->withErrors(['amount' => $refused->getMessage()]);
        }

        return back()->with('success', __('wallet.admin.converted'));
    }

    protected function actor(Request $request): User
    {
        $actor = $request->user();

        abort_if(! $actor instanceof User, 403);

        return $actor;
    }
}
