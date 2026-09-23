<?php

namespace App\Domain\Order\Actions;

use App\Domain\Access\Enums\PermissionAction;
use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Access\PermissionCatalogue;
use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Inventory\Data\MovementContext;
use App\Domain\Inventory\Enums\StockBucket;
use App\Domain\Inventory\Enums\StockMovementType;
use App\Domain\Inventory\Exceptions\InventoryRefused;
use App\Domain\Inventory\Models\StockItem;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\StockLedger;
use App\Domain\Order\Data\ReturnedLine;
use App\Domain\Order\Enums\OrderStatusChangeSource;
use App\Domain\Order\Enums\ReturnStatus;
use App\Domain\Order\Exceptions\ReturnRefused;
use App\Domain\Order\Models\OrderReturn;
use App\Domain\Order\Models\OrderReturnItem;
use App\Domain\Supplier\Actions\ReverseSupplierPayable;
use App\Domain\Supplier\Models\SupplierOfferStock;
use App\Domain\Supplier\SupplierStockLedger;
use App\Models\User;
use App\Support\StatusHistory\StatusChange;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DatabaseManager;

/**
 * The goods arrived: count them, judge them, put them where they belong
 * (§19, §19.1, §20, P6-12).
 *
 * Three things happen here, and the order matters. What actually came back is
 * counted per line — never more than was approved. Each line is given a
 * **disposition** by the person holding the goods: back on sale, damaged, or
 * held for inspection. Only then does stock move, and it moves through the
 * stock ledger like every other change, so the movement carries who, why and
 * what the figures were before and after.
 *
 * **Once, and only once.** The movement that restored a line is written onto
 * that line, and the column is unique: a second receipt of the same line has
 * nowhere to put its movement, so restoring twice is impossible rather than
 * unlikely. The ledger's own idempotency key says the same thing from the
 * other side.
 *
 * Restoring takes the units out of **sold** stock, because that is where
 * delivered goods are (§19). An order whose units were never recorded as sold
 * — because fulfilment has not recorded the delivery (§20, P6-17) — is refused
 * in those words rather than having a source invented for it.
 *
 * Putting goods back on sale is an inventory change as much as a returns
 * decision, so it asks for both: `order.edit` to move the return along, and
 * `inventory.edit` to change what is in stock.
 *
 * **A Supplier-backed line restores into Supplier stock, not the warehouse**
 * (D25, P13-22): its order item names the offer it was allocated to, so its
 * units go back through {@see SupplierStockLedger} instead, and the return
 * item's `supplier_stock_movement_id` — never both columns — is what makes
 * that restoration recorded exactly once. The same quantity also reverses the
 * line's Supplier payable, through {@see ReverseSupplierPayable}, in the same
 * transaction.
 */
class ReceiveReturnedItems
{
    public function __construct(
        protected StockLedger $ledger,
        protected SupplierStockLedger $supplierLedger,
        protected ReverseSupplierPayable $supplierPayables,
        protected AnnounceReturnStatus $announcements,
        protected RecordAuditLog $audit,
        protected DatabaseManager $database,
    ) {}

    /**
     * @param  array<int, ReturnedLine>  $lines  what arrived, per returned line
     *
     * @throws ReturnRefused
     * @throws AuthorizationException
     */
    public function handle(User $actor, OrderReturn $return, array $lines, Warehouse $warehouse, ?string $note = null): OrderReturn
    {
        $this->authorize($actor);

        $received = $this->database->transaction(function () use ($actor, $return, $lines, $warehouse, $note) {
            /** @var OrderReturn $locked */
            $locked = OrderReturn::query()->lockForUpdate()->findOrFail($return->id);
            $locked->load('items.orderItem');

            if ($locked->status !== ReturnStatus::Approved) {
                throw ReturnRefused::notInThatState();
            }

            $arrivals = $this->arrivals($locked, $lines);

            foreach ($arrivals as [$item, $line]) {
                $this->receive($item, $line, $warehouse, $actor);
            }

            $locked->forceFill([
                'received_by' => $actor->id,
                'received_at' => CarbonImmutable::now(),
            ]);

            $locked->moveTo(
                ReturnStatus::Received,
                new StatusChange(
                    actorId: $actor->id,
                    reason: 'The returned goods arrived and were inspected.',
                    internalNote: $note,
                    publicNote: 'returns.notes.received',
                ),
                OrderStatusChangeSource::Staff,
            );

            $this->announcements->handle($locked->refresh()->load('items'));

            return $locked;
        });

        $this->audit->handle(new AuditEntry(
            action: 'order_return.received',
            actorId: $actor->id,
            auditableType: OrderReturn::class,
            auditableId: $received->id,
            after: [
                'reference' => $received->reference,
                'warehouse' => $warehouse->code,
                'lines' => $received->items->map(fn (OrderReturnItem $item) => [
                    'sku' => $item->orderItem->sku,
                    'received' => $item->received_quantity,
                    'disposition' => $item->disposition?->value,
                ])->all(),
            ],
            note: $note,
            accountId: $received->business_account_id,
            module: PermissionModule::Order->value,
        ));

        $return->setRawAttributes($received->getAttributes(), sync: true);

        return $received;
    }

    /**
     * Match what arrived to the lines it is for, and refuse what does not fit.
     *
     * @param  array<int, ReturnedLine>  $lines
     * @return array<int, array{0: OrderReturnItem, 1: ReturnedLine}>
     *
     * @throws ReturnRefused
     */
    protected function arrivals(OrderReturn $return, array $lines): array
    {
        $arrivals = [];
        $total = 0;

        foreach ($lines as $line) {
            /** @var OrderReturnItem|null $item */
            $item = $return->items->firstWhere('public_id', $line->lineId);

            if ($item === null) {
                throw ReturnRefused::notInThatState();
            }

            $approved = (int) $item->approved_quantity;

            if ($line->quantity < 0 || $line->quantity > $approved) {
                throw ReturnRefused::moreThanApproved($item->orderItem->sku, $line->quantity, $approved);
            }

            $total += $line->quantity;
            $arrivals[] = [$item, $line];
        }

        if ($total <= 0) {
            throw ReturnRefused::noLines();
        }

        return $arrivals;
    }

    /**
     * Take one line's goods in and put them where the inspector said.
     *
     * @throws ReturnRefused
     */
    protected function receive(OrderReturnItem $item, ReturnedLine $line, Warehouse $warehouse, User $actor): void
    {
        if ($item->isRestored()) {
            // Already taken in under another request; its movement stands.
            return;
        }

        $item->forceFill([
            'received_quantity' => $line->quantity,
            'disposition' => $line->disposition,
            'warehouse_id' => $warehouse->id,
        ])->save();

        if ($line->quantity === 0) {
            // Nothing arrived for this line. Recorded, with nothing to move.
            return;
        }

        $orderItem = $item->orderItem;

        if ($orderItem->isSupplierBacked()) {
            $movementId = $this->restoreToSupplier($item, $line, $actor);

            $item->forceFill([
                'supplier_stock_movement_id' => $movementId,
                'restored_at' => CarbonImmutable::now(),
            ])->save();

            $this->supplierPayables->handle(
                $item,
                $line->quantity,
                'Returned on '.$item->orderReturn->reference.': '.$line->disposition->label().'.',
            );

            return;
        }

        $movement = $this->restore($item, $line, $warehouse, $actor);

        $item->forceFill([
            'stock_movement_id' => $movement,
            'restored_at' => CarbonImmutable::now(),
        ])->save();
    }

    /**
     * Move the units out of sold stock and into what they now are.
     *
     * @throws ReturnRefused
     */
    protected function restore(OrderReturnItem $item, ReturnedLine $line, Warehouse $warehouse, User $actor): int
    {
        $orderItem = $item->orderItem;

        /** @var StockItem $stock */
        $stock = StockItem::query()->firstOrCreate([
            'warehouse_id' => $warehouse->id,
            'product_id' => $orderItem->product_id,
            'product_variant_id' => $orderItem->product_variant_id,
        ]);

        try {
            $movement = $this->ledger->move(
                $stock,
                StockBucket::Sold,
                $line->disposition->bucket(),
                $line->quantity,
                StockMovementType::Adjustment,
                new MovementContext(
                    reason: 'Returned on '.$item->orderReturn->reference.': '.$line->disposition->label().'.',
                    actorId: $actor->id,
                    sourceType: 'order_return',
                    sourceId: $item->orderReturn->id,
                    // One restoration per returned line, whatever arrives twice.
                    idempotencyKey: 'order-return-item:'.$item->public_id,
                ),
            );
        } catch (InventoryRefused) {
            throw ReturnRefused::nothingSoldToRestore($orderItem->sku);
        }

        return $movement->id;
    }

    /**
     * The Supplier-stock twin of {@see restore()}: the same "sold" bucket
     * discipline, on the offer's own stock rather than a warehouse (D25,
     * P13-22).
     *
     * @throws ReturnRefused
     */
    protected function restoreToSupplier(OrderReturnItem $item, ReturnedLine $line, User $actor): int
    {
        $orderItem = $item->orderItem;

        /** @var SupplierOfferStock $stock */
        $stock = SupplierOfferStock::query()->firstOrCreate(
            ['supplier_offer_id' => $orderItem->supplier_offer_id],
            ['quantity' => 0],
        );

        try {
            $movement = $this->supplierLedger->move(
                $stock,
                StockBucket::Sold,
                $line->disposition->bucket(),
                $line->quantity,
                'return_received',
                reason: 'Returned on '.$item->orderReturn->reference.': '.$line->disposition->label().'.',
                idempotencyKey: 'order-return-item:'.$item->public_id,
            );
        } catch (InventoryRefused) {
            throw ReturnRefused::nothingSoldToRestore($orderItem->sku);
        }

        return $movement->id;
    }

    /**
     * @throws AuthorizationException
     */
    protected function authorize(User $actor): void
    {
        $mayMove = $actor->can(PermissionCatalogue::name(PermissionModule::Order, PermissionAction::Edit));
        $mayAdjustStock = $actor->can(PermissionCatalogue::name(PermissionModule::Inventory, PermissionAction::Edit));

        if (! $mayMove || ! $mayAdjustStock) {
            throw new AuthorizationException('Taking returned goods back into stock needs both order and inventory rights.');
        }
    }
}
