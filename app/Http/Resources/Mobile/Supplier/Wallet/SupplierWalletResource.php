<?php

namespace App\Http\Resources\Mobile\Supplier\Wallet;

use App\Domain\Supplier\Models\SupplierWallet;
use App\Http\Resources\Mobile\MoneyResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The Supplier's wallet, worked out on the server (D25, P13-23).
 *
 * Exactly the figures the web screen shows. The app displays them and never
 * adds them up.
 *
 * @mixin SupplierWallet
 */
class SupplierWalletResource extends JsonResource
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
            /** What the supplier can use now. */
            'available' => new MoneyResource($this->availableBalance()),
            /** Platform credit: spendable inside Feriwala, never withdrawable. */
            'platform_credit' => new MoneyResource($this->platformCreditAvailable()),
            /** Exactly what may be withdrawn now. */
            'withdrawable' => new MoneyResource($this->availableForWithdrawal()),
            'available_for_withdrawal' => new MoneyResource($this->availableForWithdrawal()),
            'reserved' => new MoneyResource($this->reserved),
            'recovery' => new MoneyResource($this->recovery),
            'has_outstanding_recovery' => $this->hasOutstandingRecovery(),
        ];
    }
}
