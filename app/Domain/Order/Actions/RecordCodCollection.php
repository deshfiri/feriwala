<?php

namespace App\Domain\Order\Actions;

use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Order\Models\OrderItem;
use App\Domain\Order\Models\OrderProceedsSettlement;
use App\Models\User;
use App\Support\Money\Money;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;

/**
 * Staff confirm the cash actually collected for a Non-Conditional order
 * line's COD delivery (D-new) — the authoritative event the two-fact
 * eligibility gate waits on, which did not exist anywhere in this codebase
 * before this batch.
 *
 * Manual, staff-entered, following this codebase's own established
 * precedent for a fact with no real integration yet (see {@see
 * \App\Domain\Courier\Actions\RecordManualCourierStatusUpdate}'s own
 * docblock). A real courier cash-reconciliation webhook, when one exists,
 * calls this same action with the same effect — nothing downstream changes.
 */
class RecordCodCollection
{
    public function __construct(
        protected EvaluateOrderProceedsEligibility $eligibility,
        protected RecordAuditLog $audit,
        protected DatabaseManager $database,
    ) {}

    public function handle(User $actor, OrderItem $item, Money $amountCollected): OrderProceedsSettlement
    {
        if ($amountCollected->isNegative()) {
            throw new InvalidArgumentException('The amount collected cannot be negative.');
        }

        if (! $item->order->isNonConditional()) {
            throw new InvalidArgumentException('Only a Non-Conditional order\'s line has a COD collection to confirm.');
        }

        return $this->database->transaction(function () use ($actor, $item, $amountCollected) {
            $settlement = $this->eligibility->markCodCollected($item, $amountCollected);

            $this->audit->handle(new AuditEntry(
                action: 'order_item.cod_collection_recorded',
                actorId: $actor->id,
                auditableType: OrderItem::class,
                auditableId: $item->id,
                after: ['cod_amount_collected' => $amountCollected->toDecimal()],
                accountId: $item->order->business_account_id,
                module: PermissionModule::Order->value,
            ));

            return $settlement;
        });
    }
}
