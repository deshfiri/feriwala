<?php

namespace App\Domain\Order\Actions;

use App\Domain\Order\Enums\ReturnChargeKind;
use App\Domain\Order\Enums\ReturnChargeStatus;
use App\Domain\Order\Enums\ReturnParty;
use App\Domain\Order\Models\OrderReturn;
use App\Domain\Order\Models\ReturnCharge;
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
 * Put a charge on whoever bears it, once (D35).
 *
 * Three outcomes, by who bears it and whether they can pay:
 *
 *   - **Banij** bears it: recorded as Banij accounting, and nothing else. There
 *     is no platform wallet, and a Partner is never debited for a Banij fault.
 *   - **The Partner** bears it and the wallet can cover it: debited through
 *     {@see WalletService}, which spends platform credit first and withdrawable
 *     money after, writes its immutable ledger entry in the same transaction,
 *     and refuses to overdraw. The charge records that transaction.
 *   - **The Partner** bears it and the wallet cannot: an **outstanding**
 *     liability. A balance is never forced negative — that is a loan nobody
 *     agreed to — so the amount is owed, shown as owed, and collected by
 *     {@see SettleOutstandingReturnCharge} when the money is there.
 *
 * **Once, whatever arrives twice.** The idempotency key names the case, the kind
 * and the attribution revision, and is unique on the charge row, on the wallet
 * transaction and on the ledger entry. A retry finds the first charge; two
 * identical requests racing are settled by the index.
 *
 * A charge of nothing is not posted at all.
 */
class PostReturnCharge
{
    public function __construct(
        protected WalletService $wallet,
        protected DatabaseManager $database,
    ) {}

    /**
     * @param  string  $subject  distinguishes two charges of one kind on one case (a damage record, say)
     * @param  int  $revision  the attribution revision this charge is posted under; a corrected attribution posts anew
     */
    public function handle(
        OrderReturn $case,
        ReturnChargeKind $kind,
        Money $amount,
        ReturnParty $bearer,
        string $reason,
        ?User $actor = null,
        string $subject = '',
        int $revision = 0,
    ): ?ReturnCharge {
        if ($amount->isNegative()) {
            throw new InvalidArgumentException('A charge cannot be negative.');
        }

        if ($amount->isZero()) {
            return null;
        }

        $key = $this->key($case, $kind, $subject, $revision);

        if ($existing = $this->find($key)) {
            return $existing;
        }

        try {
            return $this->database->transaction(function () use ($case, $kind, $amount, $bearer, $reason, $actor, $key) {
                // The case is locked while its money is written, so two charges for
                // it are never worked out against each other's half-finished state.
                OrderReturn::query()->lockForUpdate()->findOrFail($case->id);

                [$status, $transactionId] = $bearer === ReturnParty::Banij
                    ? [ReturnChargeStatus::Recorded, null]
                    : $this->debit($case, $kind, $amount, $reason, $actor, $key);

                return ReturnCharge::create([
                    'order_return_id' => $case->id,
                    'business_account_id' => $case->business_account_id,
                    'kind' => $kind,
                    'entry_type' => ReturnCharge::CHARGE,
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
    public function key(OrderReturn $case, ReturnChargeKind $kind, string $subject = '', int $revision = 0): string
    {
        return implode(':', array_filter([
            'return-charge', $case->public_id, $kind->value, $subject !== '' ? $subject : null, 'r'.$revision,
        ], fn (?string $part) => $part !== null));
    }

    /**
     * Debit the Partner's wallet, or say it could not.
     *
     * @return array{0: ReturnChargeStatus, 1: int|null}
     */
    protected function debit(OrderReturn $case, ReturnChargeKind $kind, Money $amount, string $reason, ?User $actor, string $key): array
    {
        /** @var Wallet|null $wallet */
        $wallet = WalletPaymentMethod::walletFor($case->businessAccount);

        if ($wallet === null || ! WalletPaymentMethod::canCover($case->businessAccount, $amount)) {
            return [ReturnChargeStatus::Outstanding, null];
        }

        try {
            $transaction = $this->wallet->debit(
                $wallet,
                $kind->ledgerType(),
                $amount,
                new PostingContext(
                    source: 'order_return',
                    description: $kind->label().' — '.$case->reference,
                    idempotencyKey: $key,
                    reason: $reason,
                    actorId: $actor?->id,
                ),
            );
        } catch (WalletOperationRefused) {
            // A wallet that cannot take the debit right now — a hold, a restriction,
            // a balance that moved under the lock — leaves the amount owed, never
            // unrecorded and never forced.
            return [ReturnChargeStatus::Outstanding, null];
        }

        return [ReturnChargeStatus::Posted, $transaction->id];
    }

    protected function find(string $key): ?ReturnCharge
    {
        /** @var ReturnCharge|null $charge */
        $charge = ReturnCharge::query()->where('idempotency_key', $key)->first();

        return $charge;
    }
}
