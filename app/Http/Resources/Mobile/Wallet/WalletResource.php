<?php

namespace App\Http\Resources\Mobile\Wallet;

use App\Domain\Wallet\Models\Wallet;
use App\Http\Resources\Mobile\MoneyResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The account's wallet, worked out on the server (§23, §36.1).
 *
 * The same figures {@see Wallet::toBalances()} gives the web screen, with their
 * shapes declared. The app displays them and never adds them up.
 *
 * @mixin Wallet
 */
class WalletResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'currency' => $this->currency_code,
            /** Everything held, before any claim against it. */
            'total' => new MoneyResource($this->total),
            /** What the account can use right now. */
            'usable' => new MoneyResource($this->usableBalance()),
            /** Platform credit: spendable inside Feriwala, never withdrawable. */
            'platform_credit' => new MoneyResource($this->platformCreditAvailable()),
            /** Exactly what may be withdrawn now. */
            'withdrawable' => new MoneyResource($this->availableForWithdrawal()),
            'platform_credit_held' => new MoneyResource($this->platformCredit()),
            'withdrawable_held' => new MoneyResource($this->withdrawableHolding()),
            'reserved_platform_credit' => new MoneyResource($this->reservedPlatformCredit()),
            'reserved_withdrawable' => new MoneyResource($this->reservedWithdrawable()),
            'required_deposit' => new MoneyResource($this->required_deposit),
            /** The part that has to stay: spendable on nothing, withdrawable never. */
            'minimum_balance' => new MoneyResource($this->minimum_balance),
            'reserved' => new MoneyResource($this->reserved),
            'pending' => new MoneyResource($this->pending),
            'hold' => new MoneyResource($this->hold),
            'cod_receivable' => new MoneyResource($this->cod_receivable),
            'deposit_usable_for_charges' => $this->deposit_usable_for_charges,
            'meets_required_deposit' => $this->meetsRequiredDeposit(),
            'meets_obligation' => $this->meetsObligation(),
            'shortfall' => new MoneyResource($this->shortfall()),
            'obligation_shortfall' => new MoneyResource($this->obligationShortfall()),
            'deposit_due_at' => $this->deposit_due_at?->toIso8601String(),
            /** The wallet's state (§24.3) with a label, so the app never infers it from figures or colour. */
            'state' => $this->balance_state->value,
            'state_label' => $this->balance_state->label(),
            'state_tone' => $this->balance_state->tone(),
            'grace_ends_at' => $this->grace_ends_at?->toIso8601String(),
            'shortfall_since' => $this->shortfall_since?->toIso8601String(),
        ];
    }
}
