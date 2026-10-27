<?php

namespace App\Domain\Damage\Actions;

use App\Domain\Damage\Models\DamageCharge;
use App\Domain\Order\Enums\ReturnChargeStatus;
use App\Domain\Wallet\Data\PostingContext;
use App\Domain\Wallet\Exceptions\WalletOperationRefused;
use App\Domain\Wallet\WalletPaymentMethod;
use App\Domain\Wallet\WalletService;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;

/**
 * Collect a damage liability the Partner's wallet could not cover when it was
 * raised (D35).
 *
 * Run when somebody asks — staff from the case, or a sweep once money has
 * arrived — and a no-op until the wallet can cover the amount. The debit is
 * posted through {@see WalletService} under its own idempotency key, so asking
 * twice, or two sweeps meeting, collects once; the charge row then records the
 * wallet transaction and is marked settled.
 */
class SettleOutstandingDamageCharge
{
    public function __construct(
        protected WalletService $wallet,
        protected DatabaseManager $database,
    ) {}

    /**
     * @return bool whether this call collected it
     */
    public function handle(DamageCharge $charge, ?User $actor = null): bool
    {
        return $this->database->transaction(function () use ($charge, $actor) {
            /** @var DamageCharge $locked */
            $locked = DamageCharge::query()->lockForUpdate()->findOrFail($charge->id);

            if ($locked->status !== ReturnChargeStatus::Outstanding || $locked->isReversal()) {
                return false;
            }

            $account = $locked->businessAccount;
            $wallet = WalletPaymentMethod::walletFor($account);

            if ($wallet === null || ! WalletPaymentMethod::canCover($account, $locked->amount)) {
                return false;
            }

            try {
                $transaction = $this->wallet->debit(
                    $wallet,
                    $locked->kind->ledgerType(),
                    $locked->amount,
                    new PostingContext(
                        source: 'order_damage',
                        description: $locked->kind->label().' — '.$locked->damage->reference,
                        idempotencyKey: $locked->idempotency_key.':settle',
                        reason: 'Collected: '.$locked->reason,
                        actorId: $actor?->id,
                    ),
                );
            } catch (WalletOperationRefused) {
                return false;
            }

            $locked->forceFill([
                'status' => ReturnChargeStatus::Settled,
                'wallet_transaction_id' => $transaction->id,
                'settled_at' => CarbonImmutable::now(),
            ])->save();

            return true;
        });
    }
}
