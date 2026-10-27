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
 * Judge the returned goods (D35): the one decision that lets stock and money move.
 *
 * Every line is counted — never more than was approved — and given the
 * disposition its units end in: back on sale, damaged, or held for a closer
 * look. Approving moves those units through {@see RestoreReturnedStock}, which
 * is the same stock ledgers every other movement goes through, once per line
 * and never by writing a figure. Rejecting records why the goods were refused;
 * nothing is restocked, nothing is refunded, and the units the case claimed are
 * given back so the same goods may be asked for again.
 *
 * **Nothing moves before this.** Not a unit of stock, not a refund, not a charge
 * — a case that has not been inspected and approved has no financial or
 * inventory effect at all. That is the whole reason inspection is a stage.
 *
 * A damaged line is a finding as well as a disposition: it is what a damage
 * record is opened from (see the damage workflow), and who bears the loss is
 * decided there, not here.
 */
class FinalizeReturnInspection
{
    public const MINIMUM_NOTE = 10;

    public function __construct(
        protected RestoreReturnedStock $stock,
        protected ConfirmExchangeOrder $confirmExchange,
        protected CancelExchangeOrder $cancelExchange,
        protected AnnounceReturnStatus $announcements,
        protected RecordAuditLog $audit,
        protected DatabaseManager $database,
    ) {}

    /**
     * Approve the goods.
     *
     * @param  array<int, ReturnedLine>  $lines  one per line of the case: what arrived and where it goes
     *
     * @throws ReturnRefused
     * @throws AuthorizationException
     */
    public function approve(User $actor, OrderReturn $return, array $lines, Warehouse $warehouse, ?string $note = null): OrderReturn
    {
        $this->authorize($actor);

        $approved = $this->database->transaction(function () use ($actor, $return, $lines, $warehouse, $note) {
            $locked = $this->lockedUnderInspection($return);

            // Already approved by the request that got here first.
            if ($locked->status === ReturnStatus::InspectionApproved) {
                return $locked;
            }

            $arrivals = $this->arrivals($locked, $lines);

            foreach ($arrivals as [$item, $line]) {
                $this->stock->handle($item, $line, $warehouse, $actor);

                if ($line->note !== null) {
                    $item->forceFill(['inspection_note' => $line->note])->save();
                }
            }

            $locked->forceFill([
                'inspected_by' => $actor->id,
                'inspected_at' => CarbonImmutable::now(),
                'inspection_outcome' => 'approved',
                'inspection_note' => $note,
            ]);

            $locked->moveTo(
                ReturnStatus::InspectionApproved,
                new StatusChange(
                    actorId: $actor->id,
                    reason: 'The returned goods were inspected and approved.',
                    internalNote: $note,
                    publicNote: 'returns.notes.inspection_approved',
                ),
                OrderStatusChangeSource::Staff,
            );

            // An exchange's replacement is released only now, with the goods back on
            // the shelf: it may be filled from the very unit that came back, and if
            // there is not enough the whole approval is refused rather than made over
            // a replacement nobody can send.
            if ($locked->isExchange()) {
                $this->confirmExchange->handle($locked, $actor);
            }

            $this->announcements->handle($locked->refresh()->load('items'));

            return $locked;
        });

        $this->audit->handle(new AuditEntry(
            action: 'order_return.inspection_approved',
            actorId: $actor->id,
            auditableType: OrderReturn::class,
            auditableId: $approved->id,
            after: [
                'reference' => $approved->reference,
                'warehouse' => $warehouse->code,
                'lines' => $approved->items->map(fn (OrderReturnItem $item) => [
                    'sku' => $item->orderItem->sku,
                    'received' => $item->received_quantity,
                    'disposition' => $item->disposition?->value,
                ])->all(),
            ],
            note: $note,
            accountId: $approved->business_account_id,
            module: PermissionModule::ReturnCase->value,
        ));

        $return->setRawAttributes($approved->getAttributes(), sync: true);

        return $approved;
    }

    /**
     * Refuse the goods, with the reason.
     *
     * @throws ReturnRefused
     * @throws AuthorizationException
     */
    public function reject(User $actor, OrderReturn $return, string $reason): OrderReturn
    {
        $this->authorize($actor);

        $reason = trim($reason);

        if (mb_strlen($reason) < self::MINIMUM_NOTE) {
            throw ReturnRefused::decisionNeedsAReason();
        }

        $rejected = $this->database->transaction(function () use ($actor, $return, $reason) {
            $locked = $this->lockedUnderInspection($return);

            if ($locked->status === ReturnStatus::InspectionRejected) {
                return $locked;
            }

            $locked->forceFill([
                'inspected_by' => $actor->id,
                'inspected_at' => CarbonImmutable::now(),
                'inspection_outcome' => 'rejected',
                'inspection_note' => $reason,
            ]);

            $locked->moveTo(
                ReturnStatus::InspectionRejected,
                new StatusChange(
                    actorId: $actor->id,
                    reason: $reason,
                    publicNote: 'returns.notes.inspection_rejected',
                ),
                OrderStatusChangeSource::Staff,
            );

            // The goods were refused, so the replacement is not sent.
            if ($locked->isExchange()) {
                $this->cancelExchange->handle($locked, $reason, $actor);
            }

            $this->announcements->handle($locked->refresh()->load('items'));

            return $locked;
        });

        $this->audit->handle(new AuditEntry(
            action: 'order_return.inspection_rejected',
            actorId: $actor->id,
            auditableType: OrderReturn::class,
            auditableId: $rejected->id,
            after: ['reference' => $rejected->reference],
            reason: $reason,
            accountId: $rejected->business_account_id,
            module: PermissionModule::ReturnCase->value,
        ));

        $return->setRawAttributes($rejected->getAttributes(), sync: true);

        return $rejected;
    }

    /**
     * The case, locked, and only if it is being inspected (or already was).
     *
     * @throws ReturnRefused
     */
    protected function lockedUnderInspection(OrderReturn $return): OrderReturn
    {
        /** @var OrderReturn $locked */
        $locked = OrderReturn::query()->lockForUpdate()->findOrFail($return->id);
        $locked->load('items.orderItem');

        if (! $locked->usesCaseWorkflow()) {
            throw ReturnRefused::wrongWorkflow();
        }

        if (in_array($locked->status, [ReturnStatus::InspectionApproved, ReturnStatus::InspectionRejected], true)) {
            return $locked;
        }

        if (in_array($locked->status, [ReturnStatus::WaitingForParcel, ReturnStatus::ParcelReceived], true)) {
            throw ReturnRefused::parcelNotReceived();
        }

        if ($locked->status !== ReturnStatus::UnderInspection) {
            throw ReturnRefused::notInThatState();
        }

        return $locked;
    }

    /**
     * Match what arrived to every line of the case, or refuse.
     *
     * @param  array<int, ReturnedLine>  $lines
     * @return array<int, array{0: OrderReturnItem, 1: ReturnedLine}>
     *
     * @throws ReturnRefused
     */
    protected function arrivals(OrderReturn $return, array $lines): array
    {
        $byId = [];

        foreach ($lines as $line) {
            $byId[$line->lineId] = $line;
        }

        // Every line is judged: a case is not approved with part of it unseen.
        if (count($byId) !== $return->items->count()) {
            throw ReturnRefused::inspectionNeedsEveryLine();
        }

        $arrivals = [];
        $total = 0;

        foreach ($return->items as $item) {
            $line = $byId[$item->public_id] ?? throw ReturnRefused::inspectionNeedsEveryLine();
            $approved = (int) $item->approved_quantity;

            if ($line->quantity < 0 || $line->quantity > $approved) {
                throw ReturnRefused::moreThanApproved($item->orderItem->sku, $line->quantity, $approved);
            }

            $total += $line->quantity;
            $arrivals[] = [$item, $line];
        }

        // Approving nothing is a rejection, and says so.
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
        if (! $actor->can(PermissionCatalogue::name(PermissionModule::ReturnCase, PermissionAction::Inspect))) {
            throw new AuthorizationException('This account may not inspect returned goods.');
        }
    }
}
