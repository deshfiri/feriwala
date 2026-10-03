<?php

namespace App\Domain\Order\Actions;

use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Order\Models\OrderItem;
use App\Domain\Order\Models\OrderProceedsSettlement;
use App\Domain\Supplier\Actions\EvaluateSupplierPayableEligibility;
use App\Domain\Wallet\Actions\OpenWallet;
use App\Domain\Wallet\Data\PostingContext;
use App\Domain\Wallet\Enums\LedgerTransactionType;
use App\Domain\Wallet\WalletService;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * A Non-Conditional reseller's earning on one order line becomes real only
 * when both are true: the order reached Delivered, and the COD cash was
 * actually collected (D-new). Neither fact alone credits anything — the two
 * entry points below record their own fact, idempotently, and only the
 * second one to arrive computes the earning and credits the wallet.
 *
 * Mirrors {@see EvaluateSupplierPayableEligibility}'s
 * exact shape, by the Project Owner's own explicit instruction: `Delivered`
 * and "COD cash settled to Banij" are not the same financial event, so
 * crediting a reseller's wallet from `Delivered` alone would be exactly the
 * mistake that pattern already exists to prevent on the supplier side.
 *
 * A confirmed collection below the line's own recoverable cost is **flagged
 * for review, never silently credited as zero** — the Project Owner's own
 * instruction, because zeroing it would not financially recover Banij's cost
 * and would hide the shortfall.
 */
class EvaluateOrderProceedsEligibility
{
    public function __construct(
        protected WalletService $wallet,
        protected OpenWallet $openWallet,
        protected RecordAuditLog $audit,
        protected DatabaseManager $database,
    ) {}

    public function markDelivered(OrderItem $item, ?CarbonImmutable $at = null): OrderProceedsSettlement
    {
        return $this->apply($item, deliveredAt: $at ?? CarbonImmutable::now());
    }

    public function markCodCollected(OrderItem $item, Money $amountCollected, ?CarbonImmutable $at = null): OrderProceedsSettlement
    {
        return $this->apply($item, codCollectedAt: $at ?? CarbonImmutable::now(), codAmountCollected: $amountCollected);
    }

    protected function apply(
        OrderItem $item,
        ?CarbonImmutable $deliveredAt = null,
        ?CarbonImmutable $codCollectedAt = null,
        ?Money $codAmountCollected = null,
    ): OrderProceedsSettlement {
        try {
            return $this->database->transaction(
                fn () => $this->applyLocked($item, $deliveredAt, $codCollectedAt, $codAmountCollected)
            );
        } catch (UniqueConstraintViolationException $exception) {
            // `markDelivered()` and `markCodCollected()` can race to create
            // the first row for the same line. The unique index on
            // `order_item_id` settles it; the loser simply retries against
            // the row the winner created.
            if (OrderProceedsSettlement::query()->where('order_item_id', $item->id)->doesntExist()) {
                throw $exception;
            }

            return $this->database->transaction(
                fn () => $this->applyLocked($item, $deliveredAt, $codCollectedAt, $codAmountCollected)
            );
        }
    }

    protected function applyLocked(
        OrderItem $item,
        ?CarbonImmutable $deliveredAt,
        ?CarbonImmutable $codCollectedAt,
        ?Money $codAmountCollected,
    ): OrderProceedsSettlement {
        $settlement = OrderProceedsSettlement::query()->where('order_item_id', $item->id)->first();

        if ($settlement === null) {
            $settlement = OrderProceedsSettlement::create([
                'order_id' => $item->order_id,
                'order_item_id' => $item->id,
                'business_account_id' => $item->order->business_account_id,
                'currency_code' => $item->currency_code,
                'resale_amount' => $item->resale_amount,
                'recovered_amount' => $item->line_total,
                'created_at' => now(),
            ]);
        }

        /** @var OrderProceedsSettlement $locked */
        $locked = OrderProceedsSettlement::query()->lockForUpdate()->findOrFail($settlement->id);

        // Only a settlement still waiting on both facts takes one —
        // already-eligible rows are left exactly as they are.
        if ($locked->eligible_at !== null) {
            return $locked;
        }

        if ($deliveredAt !== null && $locked->delivered_at === null) {
            $locked->forceFill(['delivered_at' => $deliveredAt]);
        }

        if ($codCollectedAt !== null && $locked->cod_collected_at === null) {
            $locked->forceFill(['cod_collected_at' => $codCollectedAt, 'cod_amount_collected' => $codAmountCollected]);
        }

        if ($locked->delivered_at !== null && $locked->cod_collected_at !== null) {
            $this->settle($locked);
        } elseif ($locked->isDirty()) {
            $locked->save();
        }

        $settlement->setRawAttributes($locked->getAttributes(), sync: true);

        return $locked;
    }

    /**
     * Both facts are in: compute the earning against what was actually
     * collected — never the originally declared estimate — credit it when
     * positive, and flag rather than silently zero a shortfall.
     */
    protected function settle(OrderProceedsSettlement $locked): void
    {
        /** @var Money $collected */
        $collected = $locked->cod_amount_collected;
        $earning = $collected->minus($locked->recovered_amount);
        $shortfall = $earning->isNegative();
        $resellerEarning = $shortfall ? Money::zero($earning->currency) : $earning;

        $locked->forceFill([
            'eligible_at' => CarbonImmutable::now(),
            'reseller_earning' => $resellerEarning,
            'flagged_for_review' => $shortfall,
        ])->save();

        $this->audit->handle(new AuditEntry(
            action: 'order_proceeds_settlement.eligible',
            auditableType: OrderProceedsSettlement::class,
            auditableId: $locked->id,
            accountId: $locked->business_account_id,
            after: [
                'cod_amount_collected' => $collected->toDecimal(),
                'recovered_amount' => $locked->recovered_amount->toDecimal(),
                'reseller_earning' => $resellerEarning->toDecimal(),
                'flagged_for_review' => $shortfall,
            ],
            module: PermissionModule::Order->value,
            isSensitive: $shortfall,
        ));

        if ($shortfall || ! $earning->isPositive()) {
            return;
        }

        $account = $locked->businessAccount;
        $wallet = $this->openWallet->handle($account, Currency::from($locked->currency_code));

        $transaction = $this->wallet->credit($wallet, LedgerTransactionType::SalesCredit, $earning, new PostingContext(
            source: 'order_proceeds_settlement',
            description: "Reseller earning for order {$locked->order->reference}",
            idempotencyKey: 'order-proceeds-settlement:'.$locked->public_id,
        ));

        $locked->forceFill(['wallet_transaction_id' => $transaction->id])->save();
    }
}
