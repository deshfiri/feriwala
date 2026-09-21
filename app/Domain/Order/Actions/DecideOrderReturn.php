<?php

namespace App\Domain\Order\Actions;

use App\Domain\Access\Enums\PermissionAction;
use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Access\PermissionCatalogue;
use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
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
 * Staff deciding a return: take the goods back, or refuse (§18.2, §26.3,
 * P6-12).
 *
 * **A decision, with a reason, every time.** Refusing a customer's return and
 * agreeing to take stock back are both things somebody will be asked about
 * later, so the reason is required by this action, by the database, and is
 * written to the audit log with who decided it.
 *
 * Approving names a quantity per line, which may be fewer than was asked for —
 * two shirts sent back when three were claimed is a normal outcome, and the
 * approved figure is what everything afterwards is measured against. It may
 * never exceed what was asked for, and across every live return of a line it
 * may never exceed what that line sold; both are refused by the database.
 *
 * Approval moves nothing. The goods arrive, or they do not.
 */
class DecideOrderReturn
{
    public function __construct(
        protected AnnounceReturnStatus $announcements,
        protected RecordAuditLog $audit,
        protected DatabaseManager $database,
    ) {}

    public const MINIMUM_REASON = 10;

    /**
     * Agree to take back the quantities given, per returned line's public id.
     *
     * @param  array<string, int>  $quantities  returned-line public id => approved quantity
     *
     * @throws ReturnRefused
     * @throws AuthorizationException
     */
    public function approve(User $actor, OrderReturn $return, array $quantities, string $reason): OrderReturn
    {
        $this->authorize($actor, PermissionAction::Approve);

        return $this->decide($actor, $return, ReturnStatus::Approved, $reason, $quantities);
    }

    /**
     * Refuse it, with the reason the customer is told.
     *
     * @throws ReturnRefused
     * @throws AuthorizationException
     */
    public function reject(User $actor, OrderReturn $return, string $reason): OrderReturn
    {
        $this->authorize($actor, PermissionAction::Reject);

        return $this->decide($actor, $return, ReturnStatus::Rejected, $reason, []);
    }

    /**
     * @param  array<string, int>  $quantities
     *
     * @throws ReturnRefused
     */
    protected function decide(User $actor, OrderReturn $return, ReturnStatus $to, string $reason, array $quantities): OrderReturn
    {
        $reason = trim($reason);

        if (mb_strlen($reason) < self::MINIMUM_REASON) {
            throw ReturnRefused::decisionNeedsAReason();
        }

        $decided = $this->database->transaction(function () use ($actor, $return, $to, $reason, $quantities) {
            /** @var OrderReturn $locked */
            $locked = OrderReturn::query()->lockForUpdate()->findOrFail($return->id);
            $locked->load('items.orderItem');

            if ($locked->status !== ReturnStatus::Requested) {
                throw ReturnRefused::notInThatState();
            }

            if ($to === ReturnStatus::Approved) {
                $this->approveLines($locked, $quantities);
            }

            $locked->forceFill([
                'decision_note' => $reason,
                'decided_by' => $actor->id,
                'decided_at' => CarbonImmutable::now(),
            ]);

            $locked->moveTo(
                $to,
                new StatusChange(
                    actorId: $actor->id,
                    reason: $reason,
                    publicNote: $to === ReturnStatus::Approved ? 'returns.notes.approved' : 'returns.notes.rejected',
                ),
                OrderStatusChangeSource::Staff,
            );

            $this->announcements->handle($locked->refresh()->load('items'));

            return $locked;
        });

        $this->audit->handle(new AuditEntry(
            action: $to === ReturnStatus::Approved ? 'order_return.approved' : 'order_return.rejected',
            actorId: $actor->id,
            auditableType: OrderReturn::class,
            auditableId: $decided->id,
            after: [
                'status' => $to->value,
                'reference' => $decided->reference,
                'order_reference' => $decided->order->reference,
                'approved' => $decided->items->map(fn (OrderReturnItem $item) => [
                    'sku' => $item->orderItem->sku,
                    'quantity' => $item->approved_quantity,
                ])->all(),
            ],
            reason: $reason,
            accountId: $decided->business_account_id,
            module: PermissionModule::Order->value,
        ));

        $return->setRawAttributes($decided->getAttributes(), sync: true);

        return $decided;
    }

    /**
     * Write the approved quantity onto each line.
     *
     * A line nobody named is approved for what was asked; a line named with
     * zero is approved for nothing, which is how part of a return is refused
     * without refusing the whole of it.
     *
     * @param  array<string, int>  $quantities
     *
     * @throws ReturnRefused
     */
    protected function approveLines(OrderReturn $return, array $quantities): void
    {
        foreach ($return->items as $item) {
            $approved = $quantities[$item->public_id] ?? $item->quantity;

            if ($approved < 0 || $approved > $item->quantity) {
                throw ReturnRefused::lineNotReturnable($item->orderItem->sku, $approved, $item->quantity);
            }

            $item->forceFill(['approved_quantity' => $approved])->save();
        }

        $total = $return->items->sum(fn (OrderReturnItem $item) => (int) $item->approved_quantity);

        if ($total <= 0) {
            // Approving nothing is a refusal, and is recorded as one.
            throw ReturnRefused::noLines();
        }
    }

    /**
     * @throws AuthorizationException
     */
    protected function authorize(User $actor, PermissionAction $action): void
    {
        if (! $actor->can(PermissionCatalogue::name(PermissionModule::Order, $action))) {
            throw new AuthorizationException('This account may not decide returns.');
        }
    }
}
