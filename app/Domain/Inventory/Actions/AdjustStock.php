<?php

namespace App\Domain\Inventory\Actions;

use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Inventory\Data\MovementContext;
use App\Domain\Inventory\Enums\StockAdjustmentKind;
use App\Domain\Inventory\Enums\StockMovementType;
use App\Domain\Inventory\Exceptions\InventoryRefused;
use App\Domain\Inventory\Models\StockAdjustment;
use App\Domain\Inventory\Models\StockItem;
use App\Domain\Inventory\Policies\InventoryPolicy;
use App\Domain\Inventory\StockLedger;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Change central stock by hand, with a reason (§19, P3-24).
 *
 * §19: only the Admin or an authorised user directly modifies central stock —
 * asked here as well as at the controller, because a controller is not the only
 * way in. The change goes through the stock ledger like every other, so the same
 * row lock and the same "not more than the bucket holds" check apply, and it is
 * recorded three ways in one transaction: the adjustment (who, why, which kind),
 * the movement it made (every bucket before and after), and the audit log.
 *
 * The adjustment's id is taken from its sequence before the movement is written,
 * so each can name the other even though neither table may ever be updated.
 */
class AdjustStock
{
    /** A reason shorter than this is not a reason anybody can act on later. */
    public const MINIMUM_REASON = 10;

    public function __construct(
        protected StockLedger $ledger,
        protected RecordAuditLog $audit,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws InventoryRefused when the source bucket holds fewer units
     * @throws InvalidArgumentException when the quantity or reason is missing
     */
    public function handle(
        User $actor,
        StockItem $item,
        StockAdjustmentKind $kind,
        int $quantity,
        string $reason,
    ): StockAdjustment {
        InventoryPolicy::authorize(InventoryPolicy::canEdit($actor));

        $reason = trim($reason);

        if ($quantity <= 0) {
            throw new InvalidArgumentException('An adjustment moves at least one unit.');
        }

        if (mb_strlen($reason) < self::MINIMUM_REASON) {
            throw new InvalidArgumentException('An adjustment needs a reason of at least '.self::MINIMUM_REASON.' characters.');
        }

        return DB::transaction(function () use ($actor, $item, $kind, $quantity, $reason) {
            $id = (int) DB::scalar("SELECT nextval(pg_get_serial_sequence('stock_adjustments', 'id'))");

            $movement = $this->ledger->move(
                $item,
                $kind->source(),
                $kind->destination(),
                $quantity,
                StockMovementType::Adjustment,
                new MovementContext(
                    reason: $reason,
                    actorId: $actor->id,
                    sourceType: 'stock_adjustment',
                    sourceId: $id,
                ),
            );

            $adjustment = StockAdjustment::create([
                'id' => $id,
                'stock_item_id' => $item->id,
                'stock_movement_id' => $movement->id,
                'kind' => $kind,
                'quantity' => $quantity,
                'reason' => $reason,
                'actor_id' => $actor->id,
            ]);

            $this->audit->handle(new AuditEntry(
                action: 'inventory.stock_adjusted',
                actorId: $actor->id,
                auditableType: StockItem::class,
                auditableId: $item->id,
                before: $movement->before,
                after: $movement->after,
                reason: $reason,
                note: $kind->value.': '.$quantity,
                module: 'inventory',
            ));

            return $adjustment;
        });
    }
}
