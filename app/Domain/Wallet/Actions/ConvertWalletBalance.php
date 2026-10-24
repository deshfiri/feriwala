<?php

namespace App\Domain\Wallet\Actions;

use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Wallet\Data\PostingContext;
use App\Domain\Wallet\Exceptions\WalletOperationRefused;
use App\Domain\Wallet\Models\Wallet;
use App\Domain\Wallet\Models\WalletTransaction;
use App\Domain\Wallet\Policies\WalletPolicy;
use App\Domain\Wallet\WalletService;
use App\Models\User;
use App\Support\Money\Money;

/**
 * Turns an account's platform credit into withdrawable money (D-new).
 *
 * The one and only way platform credit becomes withdrawable. There is no
 * reverse: nothing in an existing workflow needs money to go the other way, and
 * a one-way door is the point.
 *
 * Authorised here, in the action, not only on the route: a conversion moves
 * money out of the platform's reach, so the permission is checked wherever it
 * is called from. The posting itself is {@see WalletService::convertToWithdrawable()},
 * which writes the two ledger entries atomically under the wallet's lock,
 * refuses more than the available platform credit, and shares one conversion
 * reference between them. This adds the actor, the required reason and the audit
 * record.
 *
 * Idempotent: the key is derived from the caller's own, so a double submit
 * converts once.
 */
class ConvertWalletBalance
{
    public function __construct(
        protected WalletService $wallet,
        protected RecordAuditLog $audit,
    ) {}

    /**
     * @return array{debit: WalletTransaction, credit: WalletTransaction}
     *
     * @throws WalletOperationRefused
     */
    public function handle(Wallet $wallet, User $actor, Money $amount, string $reason, string $idempotencyKey): array
    {
        if (! WalletPolicy::canConvertBalance($actor)) {
            throw WalletOperationRefused::notPermitted();
        }

        $transactions = $this->wallet->convertToWithdrawable($wallet, $amount, new PostingContext(
            source: 'balance_conversion',
            description: 'Platform credit converted to withdrawable',
            idempotencyKey: 'wallet-conversion:'.$wallet->public_id.':'.$idempotencyKey,
            reason: $reason,
            actorId: $actor->id,
        ));

        $this->audit->handle(new AuditEntry(
            action: 'wallet.balance_converted',
            actorId: $actor->id,
            auditableType: WalletTransaction::class,
            auditableId: $transactions['debit']->id,
            after: [
                'amount' => $amount->toDecimal(),
                'from' => 'platform_credit',
                'to' => 'withdrawable',
                'conversion_reference' => $transactions['debit']->conversion_reference,
                'debit_reference' => $transactions['debit']->reference,
                'credit_reference' => $transactions['credit']->reference,
            ],
            reason: $reason,
            accountId: $wallet->business_account_id,
            module: 'wallet',
            // Money that could not leave the platform now can.
            isSensitive: true,
        ));

        return $transactions;
    }
}
