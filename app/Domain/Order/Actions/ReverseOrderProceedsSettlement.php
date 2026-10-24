<?php

namespace App\Domain\Order\Actions;

use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Order\Models\OrderProceedsSettlement;
use App\Domain\Order\Models\OrderReturnItem;
use App\Domain\Wallet\Data\PostingContext;
use App\Domain\Wallet\Enums\LedgerDirection;
use App\Domain\Wallet\Enums\LedgerTransactionType;
use App\Domain\Wallet\Exceptions\WalletOperationRefused;
use App\Domain\Wallet\Models\LedgerEntry;
use App\Domain\Wallet\Models\WalletTransaction;
use App\Domain\Wallet\WalletService;
use App\Support\Money\Money;
use Illuminate\Database\DatabaseManager;
use Illuminate\Log\LogManager;

/**
 * Takes back what a returned order line earned its partner (D-new).
 *
 * A profit credited to a partner's wallet is only the partner's while the sale
 * stands. When the goods come back, the part of the profit that belonged to the
 * returned units is reversed with a **new** ledger entry out of the same kind of
 * money it was credited as, pointing at the entry it answers. Nothing already
 * written is touched.
 *
 * Proportional and exact: the amount to take back is worked out for **all** the
 * units returned so far and the part already taken back is subtracted, so a
 * series of partial returns converges on the credited profit to the last unit
 * and can never take back more than was credited.
 *
 * Idempotent on the return line: the same return received twice reverses once.
 * Never allowed to fail the return itself — the goods are back and that fact
 * stands. When the wallet cannot cover it (the partner already withdrew the
 * money), the settlement is flagged for review and the failure is written
 * loudly rather than swallowed, the way a refund's wallet reversal is.
 *
 * A line that credited nothing — not yet settled, a shortfall, or a profit of
 * zero — has nothing to take back, and a line that is not a Non-Conditional
 * storefront line has no settlement at all.
 */
class ReverseOrderProceedsSettlement
{
    public function __construct(
        protected WalletService $wallet,
        protected RecordAuditLog $audit,
        protected DatabaseManager $database,
        protected LogManager $log,
    ) {}

    public function forReturn(OrderReturnItem $returnItem): ?WalletTransaction
    {
        $orderItem = $returnItem->orderItem;

        /** @var OrderProceedsSettlement|null $settlement */
        $settlement = OrderProceedsSettlement::query()->where('order_item_id', $orderItem->id)->first();

        if ($settlement === null || $settlement->wallet_transaction_id === null) {
            return null;
        }

        $key = 'proceeds-reversal:'.$returnItem->public_id;

        if ($existing = WalletTransaction::query()->where('idempotency_key', $key)->first()) {
            return $existing;
        }

        try {
            return $this->database->transaction(fn () => $this->reverse($settlement, $returnItem, $key));
        } catch (WalletOperationRefused $refused) {
            $this->flag($settlement, $returnItem, $refused);

            return null;
        }
    }

    protected function reverse(OrderProceedsSettlement $settlement, OrderReturnItem $returnItem, string $key): ?WalletTransaction
    {
        /** @var OrderProceedsSettlement $locked */
        $locked = OrderProceedsSettlement::query()->lockForUpdate()->findOrFail($settlement->id);

        $earning = $locked->reseller_earning;

        if ($earning === null || ! $earning->isPositive()) {
            return null;
        }

        $quantity = (int) $locked->orderItem->quantity;
        $returned = min($quantity, (int) OrderReturnItem::query()
            ->where('order_item_id', $locked->order_item_id)
            ->sum('received_quantity'));

        // What all the returned units are worth of the credited profit, less
        // what earlier returns already took back. The last unit takes the rest,
        // so rounding never leaves a remainder behind.
        $target = $returned >= $quantity
            ? $earning
            : $earning->allocate([$returned, $quantity - $returned])[0];

        $amount = $target->minus($locked->reversed_amount);

        if (! $amount->isPositive()) {
            return null;
        }

        $original = WalletTransaction::query()->findOrFail($locked->wallet_transaction_id);

        $transaction = $this->wallet->debit(
            $original->wallet,
            LedgerTransactionType::ReturnAdjustment,
            $amount,
            new PostingContext(
                source: 'order_return',
                description: "Reseller earning reversed — return on order {$locked->order->reference}",
                idempotencyKey: $key,
                reason: 'The goods were returned: the profit on them no longer stands.',
                direction: LedgerDirection::Debit,
                correctsLedgerEntryId: LedgerEntry::query()
                    ->where('wallet_transaction_id', $original->id)
                    ->value('id'),
            ),

            // Out of the same kind of money the profit was credited as.
            $original->dominantBalanceType(),
        );

        $locked->forceFill(['reversed_amount' => $locked->reversed_amount->plus($amount)])->save();

        $this->audit->handle(new AuditEntry(
            action: 'order_proceeds_settlement.reversed',
            auditableType: OrderProceedsSettlement::class,
            auditableId: $locked->id,
            accountId: $locked->business_account_id,
            after: [
                'amount' => $amount->toDecimal(),
                'reversed_total' => $locked->reversed_amount->toDecimal(),
                'credited' => $earning->toDecimal(),
                'return_item' => $returnItem->public_id,
            ],
            module: PermissionModule::Order->value,
        ));

        return $transaction;
    }

    protected function flag(OrderProceedsSettlement $settlement, OrderReturnItem $returnItem, WalletOperationRefused $refused): void
    {
        $settlement->forceFill(['flagged_for_review' => true])->save();

        $this->log->channel('wallet')->critical('Could not reverse the profit on a returned order line', [
            'settlement' => $settlement->public_id,
            'return_item' => $returnItem->public_id,
            'business_account' => $settlement->business_account_id,
            'error' => $refused->getMessage(),
        ]);
    }
}
