<?php

namespace App\Domain\Damage\Actions;

use App\Domain\Damage\Exceptions\DamageRefused;
use App\Domain\Damage\Models\DamageCharge;
use App\Domain\Damage\Models\OrderDamage;
use App\Domain\Order\Enums\ReturnChargeKind;
use App\Domain\Order\Enums\ReturnChargeStatus;
use App\Domain\Order\Enums\ReturnParty;
use App\Domain\Wallet\Data\PostingContext;
use App\Domain\Wallet\Exceptions\WalletOperationRefused;
use App\Domain\Wallet\Models\Wallet;
use App\Domain\Wallet\WalletPaymentMethod;
use App\Domain\Wallet\WalletService;
use App\Models\User;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\UniqueConstraintViolationException;
use InvalidArgumentException;

/**
 * Put a damage's cost on whoever bears it, once (D35).
 *
 * Three outcomes, by who bears it and whether they can pay:
 *
 *   - **Banij** bears it: recorded as Banij accounting, and nothing else. There
 *     is no platform wallet, and a Partner is never debited for a Banij fault.
 *   - **The Partner** bears it and the wallet can cover it: debited through
 *     {@see WalletService}, which spends platform credit first and withdrawable
 *     money after, writes its immutable ledger entry in the same transaction and
 *     refuses to overdraw.
 *   - **The Partner** bears it and the wallet cannot: an **outstanding**
 *     liability. A balance is never forced negative, so the amount is owed, shown
 *     as owed, and collected by {@see SettleOutstandingDamageCharge} when the
 *     money is there.
 *
 * A charge the Partner *asked for* — the delivery of their own damaged item —
 * is not a liability to be run up: it is posted with `$allowOutstanding` false,
 * and refused when the wallet cannot cover it.
 *
 * **Once, whatever arrives twice.** The idempotency key names the case, the kind
 * and the attribution revision, and is unique on the charge row, on the wallet
 * transaction and on the ledger entry. A retry finds the first charge; two
 * identical requests racing are settled by the index. A charge of nothing is not
 * posted at all.
 */
class PostDamageCharge
{
    public function __construct(
        protected WalletService $wallet,
        protected DatabaseManager $database,
    ) {}

    /**
     * @param  int  $revision  the attribution revision this charge is posted under; a corrected attribution posts anew
     *
     * @throws DamageRefused
     */
    public function handle(
        OrderDamage $damage,
        ReturnChargeKind $kind,
        Money $amount,
        ReturnParty $bearer,
        string $reason,
        ?User $actor = null,
        int $revision = 0,
        bool $allowOutstanding = true,
    ): ?DamageCharge {
        if ($amount->isNegative()) {
            throw new InvalidArgumentException('A charge cannot be negative.');
        }

        if ($amount->isZero()) {
            return null;
        }

        $key = $this->key($damage, $kind, $revision);

        if ($existing = $this->find($key)) {
            return $existing;
        }

        try {
            return $this->database->transaction(function () use ($damage, $kind, $amount, $bearer, $reason, $actor, $key, $allowOutstanding) {
                // The case is locked while its money is written, so two charges for
                // it are never worked out against each other's half-finished state.
                OrderDamage::query()->lockForUpdate()->findOrFail($damage->id);

                [$status, $transactionId] = $bearer === ReturnParty::Banij
                    ? [ReturnChargeStatus::Recorded, null]
                    : $this->debit($damage, $kind, $amount, $reason, $actor, $key, $allowOutstanding);

                return DamageCharge::create([
                    'order_damage_id' => $damage->id,
                    'business_account_id' => $damage->business_account_id,
                    'kind' => $kind,
                    'entry_type' => DamageCharge::CHARGE,
                    'bearer' => $bearer,
                    'amount' => $amount,
                    'currency_code' => $amount->currency->value,
                    'status' => $status,
                    'wallet_transaction_id' => $transactionId,
                    'reason' => $reason,
                    'actor_id' => $actor?->id,
                    'idempotency_key' => $key,
                    'posted_at' => CarbonImmutable::now(),
                ]);
            });
        } catch (UniqueConstraintViolationException $exception) {
            $existing = $this->find($key);

            if ($existing === null) {
                throw $exception;
            }

            return $existing;
        }
    }

    /**
     * The key a charge is posted under.
     */
    public function key(OrderDamage $damage, ReturnChargeKind $kind, int $revision = 0): string
    {
        return 'damage-charge:'.$damage->public_id.':'.$kind->value.':r'.$revision;
    }

    /**
     * Debit the Partner's wallet, or say it could not.
     *
     * @return array{0: ReturnChargeStatus, 1: int|null}
     *
     * @throws DamageRefused
     */
    protected function debit(OrderDamage $damage, ReturnChargeKind $kind, Money $amount, string $reason, ?User $actor, string $key, bool $allowOutstanding): array
    {
        /** @var Wallet|null $wallet */
        $wallet = WalletPaymentMethod::walletFor($damage->businessAccount);

        if ($wallet === null || ! WalletPaymentMethod::canCover($damage->businessAccount, $amount)) {
            return $this->cannotCover($amount, $allowOutstanding);
        }

        try {
            $transaction = $this->wallet->debit(
                $wallet,
                $kind->ledgerType(),
                $amount,
                new PostingContext(
                    source: 'order_damage',
                    description: $kind->label().' — '.$damage->reference,
                    idempotencyKey: $key,
                    reason: $reason,
                    actorId: $actor?->id,
                ),
            );
        } catch (WalletOperationRefused) {
            // A wallet that cannot take the debit right now — a hold, a restriction,
            // a balance that moved under the lock — leaves the amount owed, never
            // unrecorded and never forced.
            return $this->cannotCover($amount, $allowOutstanding);
        }

        return [ReturnChargeStatus::Posted, $transaction->id];
    }

    /**
     * @return array{0: ReturnChargeStatus, 1: int|null}
     *
     * @throws DamageRefused
     */
    protected function cannotCover(Money $amount, bool $allowOutstanding): array
    {
        if (! $allowOutstanding) {
            throw DamageRefused::insufficientBalance($amount->toDecimal());
        }

        return [ReturnChargeStatus::Outstanding, null];
    }

    protected function find(string $key): ?DamageCharge
    {
        /** @var DamageCharge|null $charge */
        $charge = DamageCharge::query()->where('idempotency_key', $key)->first();

        return $charge;
    }
}
