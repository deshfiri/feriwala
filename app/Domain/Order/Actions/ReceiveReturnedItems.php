<?php

namespace App\Domain\Order\Actions;

use App\Domain\Access\Enums\PermissionAction;
use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Access\PermissionCatalogue;
use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Order\Data\ReturnedLine;
use App\Domain\Order\Enums\OrderStatusChangeSource;
use App\Domain\Order\Enums\ReturnStatus;
use App\Domain\Order\Exceptions\ReturnRefused;
use App\Domain\Order\Models\OrderReturn;
use App\Domain\Order\Models\OrderReturnItem;
use App\Models\User;
use App\Support\StatusHistory\StatusChange;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DatabaseManager;

/**
 * The goods arrived: count them, judge them, put them where they belong
 * (§19, §19.1, §20, P6-12).
 *
 * **For a return placed before the case workflow (D35) only.** A version 2 case
 * is received, inspected and only then restocked — stock never moves before a
 * person has approved the goods — through {@see ReceiveReturnParcel},
 * {@see StartReturnInspection} and {@see FinalizeReturnInspection}. This is the
 * original single step, kept for the returns still in flight under it.
 *
 * Three things happen here, and the order matters. What actually came back is
 * counted per line — never more than was approved. Each line is given a
 * **disposition** by the person holding the goods: back on sale, damaged, or
 * held for inspection. Only then does stock move, and it moves through
 * {@see RestoreReturnedStock}, like every other change, so the movement carries
 * who, why and what the figures were before and after.
 *
 * Putting goods back on sale is an inventory change as much as a returns
 * decision, so it asks for both: `order.edit` to move the return along, and
 * `inventory.edit` to change what is in stock.
 */
class ReceiveReturnedItems
{
    public function __construct(
        protected RestoreReturnedStock $stock,
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

            // The case workflow has its own, slower road to restocking.
            if ($locked->usesCaseWorkflow() || $locked->status !== ReturnStatus::Approved) {
                throw ReturnRefused::notInThatState();
            }

            $arrivals = $this->arrivals($locked, $lines);

            foreach ($arrivals as [$item, $line]) {
                $this->stock->handle($item, $line, $warehouse, $actor);
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
