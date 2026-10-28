<?php

namespace App\Domain\Damage\Actions;

use App\Domain\Access\Enums\PermissionAction;
use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Access\PermissionCatalogue;
use App\Domain\Account\Enums\AccountPermission;
use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Damage\Data\DamageReport;
use App\Domain\Damage\Enums\DamageSource;
use App\Domain\Damage\Enums\DamageStatus;
use App\Domain\Damage\Exceptions\DamageRefused;
use App\Domain\Damage\Models\OrderDamage;
use App\Domain\Damage\Queries\DamageValuation;
use App\Domain\Notification\Enums\StaffQueue;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Enums\OrderStatusChangeSource;
use App\Domain\Order\Enums\ReturnDisposition;
use App\Domain\Order\Enums\ReturnInitiator;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderItem;
use App\Domain\Order\Models\OrderReturn;
use App\Domain\Order\Models\OrderReturnItem;
use App\Domain\Order\Queries\ReturnEligibility;
use App\Models\User;
use App\Support\StatusHistory\StatusChange;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Open a damage case (D35).
 *
 * A damage is a finding about goods, never a standalone record, and there are
 * exactly two ways one is opened:
 *
 *   - **From a returned product**, by staff, once the return's parcel has been
 *     received and its goods inspected: {@see fromReturn()}. The units are
 *     already in a stock bucket — the inspection put them there — and the case
 *     points at the return and its line.
 *   - **From a delivered product**, by the Partner whose order it is or by staff
 *     for them: {@see fromDelivered()}. The case points at the order line. The
 *     item may be coming back to Banij (a parcel is expected) or may stay where
 *     it is, in which case there is no stock to move and inspection is of the
 *     evidence.
 *
 * Either way the quantity is checked against what was sold (and, from a return,
 * against what came back), the product and variation are copied from the order
 * line, and **what the goods are worth is frozen here** from that line's own
 * snapshot — {@see DamageValuation}. Nothing the reporter sends can set a value,
 * a cause or who bears the loss: those are decided by people who can see the
 * goods.
 *
 * Asked twice with the same key, it answers once. The database refuses more
 * units than were sold, and a second live case on one returned line.
 */
class ReportDamage
{
    public const MINIMUM_DESCRIPTION = 10;

    public const MAXIMUM_EVIDENCE = 10;

    public function __construct(
        protected DamageValuation $valuation,
        protected ReturnEligibility $eligibility,
        protected RecordAuditLog $audit,
        protected DatabaseManager $database,
    ) {}

    /**
     * Report damage on a delivered product.
     *
     * @throws DamageRefused
     * @throws AuthorizationException
     */
    public function fromDelivered(User $actor, Order $order, string $orderItemPublicId, DamageReport $report): OrderDamage
    {
        $initiator = $this->authorizeDelivered($actor, $order);

        if ($existing = $this->existing($order->business_account_id, $report->idempotencyKey)) {
            return $existing;
        }

        try {
            $damage = $this->database->transaction(function () use ($actor, $order, $orderItemPublicId, $report, $initiator) {
                /** @var Order $locked */
                $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
                $locked->load(['items', 'statusHistory']);

                $this->requireDelivered($locked);

                /** @var OrderItem|null $item */
                $item = $locked->items->firstWhere('public_id', $orderItemPublicId);

                if ($item === null) {
                    throw DamageRefused::lineNotOnOrder();
                }

                $damage = $this->open($actor, $locked, $item, $report, $initiator, DamageSource::DeliveredReport, [
                    'parcel_expected' => $report->parcelExpected,
                ]);

                // Goods coming back wait for their parcel; goods staying where they are
                // go straight to inspection of the evidence.
                $this->move($damage, $report->parcelExpected ? DamageStatus::AwaitingReceipt : DamageStatus::UnderInspection, $actor, $initiator, $report->parcelExpected
                    ? 'The item is being sent back to Banij.'
                    : 'The damage is to be inspected from the evidence; the item stays where it is.', $report->parcelExpected
                    ? 'damages.notes.awaiting_receipt'
                    : 'damages.notes.under_inspection');

                return $damage;
            });
        } catch (UniqueConstraintViolationException $exception) {
            $existing = $this->existing($order->business_account_id, $report->idempotencyKey);

            if ($existing === null) {
                throw $exception;
            }

            return $existing;
        }

        $this->auditOpened($actor, $damage);

        // A Partner's report is news to the staff who may inspect; staff reporting it themselves is not.
        // Said once any surrounding transaction (a report with attachments) has committed.
        if ($initiator !== ReturnInitiator::Staff) {
            $this->database->afterCommit(fn () => StaffQueue::DamageCase->announce($damage));
        }

        return $damage->refresh();
    }

    /**
     * Open a damage from a returned line that has been received and inspected.
     *
     * @throws DamageRefused
     * @throws AuthorizationException
     */
    public function fromReturn(User $actor, OrderReturn $case, OrderReturnItem $line, DamageReport $report): OrderDamage
    {
        if (! $actor->can(PermissionCatalogue::name(PermissionModule::Damage, PermissionAction::Inspect))) {
            throw new AuthorizationException('This account may not open a damage from returned goods.');
        }

        if ($existing = $this->existing($case->business_account_id, $report->idempotencyKey)) {
            return $existing;
        }

        $opened = false;

        try {
            $damage = $this->database->transaction(function () use ($actor, $case, $line, $report, &$opened) {
                /** @var OrderReturn $lockedCase */
                $lockedCase = OrderReturn::query()->lockForUpdate()->findOrFail($case->id);

                if (! $lockedCase->usesCaseWorkflow() || ! $lockedCase->status->isInspectionApproved() || $lockedCase->received_at === null) {
                    throw DamageRefused::returnNotInspected();
                }

                /** @var OrderReturnItem|null $returned */
                $returned = $lockedCase->items()->where('id', $line->id)->first();

                if ($returned === null) {
                    throw DamageRefused::lineNotOnOrder();
                }

                // A returned line has one live damage: asking again finds it.
                $already = OrderDamage::query()
                    ->where('order_return_item_id', $returned->id)
                    ->where('status', '<>', DamageStatus::Rejected->value)
                    ->first();

                if ($already !== null) {
                    return $already;
                }

                // Units put back on sale at inspection are no longer a finding; units
                // held as damaged or quarantined are exactly what a damage is for.
                if ($returned->disposition === null || $returned->disposition === ReturnDisposition::Restock || ! $returned->isRestored()) {
                    throw DamageRefused::returnLineNotDamageable();
                }

                /** @var Order $locked */
                $locked = Order::query()->lockForUpdate()->findOrFail($lockedCase->order_id);

                $item = $returned->orderItem;

                $damage = $this->open($actor, $locked, $item, $report, ReturnInitiator::Staff, DamageSource::ReturnInspection, [
                    'order_return_id' => $lockedCase->id,
                    'order_return_item_id' => $returned->id,
                    'parcel_expected' => true,
                    'in_custody' => true,
                    'held_bucket' => $returned->disposition->bucket(),
                    'warehouse_id' => $returned->warehouse_id,
                    'received_by' => $lockedCase->received_by,
                    'received_at' => $lockedCase->received_at,
                ]);

                $this->move($damage, DamageStatus::Received, $actor, ReturnInitiator::Staff, 'The returned parcel was already received and inspected.', 'damages.notes.received');

                $opened = true;

                return $damage;
            });
        } catch (UniqueConstraintViolationException $exception) {
            $existing = $this->existing($case->business_account_id, $report->idempotencyKey)
                ?? OrderDamage::query()->where('order_return_item_id', $line->id)->where('status', '<>', DamageStatus::Rejected->value)->first();

            if ($existing === null) {
                throw $exception;
            }

            return $existing;
        }

        if ($opened) {
            $this->auditOpened($actor, $damage);
        }

        return $damage->refresh();
    }

    /**
     * Write the case, with what the goods were worth frozen onto it.
     *
     * @param  array<string, mixed>  $extra
     *
     * @throws DamageRefused
     */
    protected function open(User $actor, Order $order, OrderItem $item, DamageReport $report, ReturnInitiator $initiator, DamageSource $source, array $extra): OrderDamage
    {
        if ($item->isSupplierBacked()) {
            throw DamageRefused::supplierBacked();
        }

        $this->requireEvidence($report);

        $claimed = (int) OrderDamage::query()
            ->where('order_item_id', $item->id)
            ->where('status', '<>', DamageStatus::Rejected->value)
            ->sum('quantity');

        $available = max(0, $item->quantity - $claimed);

        if ($report->quantity < 1 || $report->quantity > $available) {
            throw DamageRefused::quantityNotAvailable($report->quantity, $available);
        }

        $value = $this->valuation->for($item, $report->quantity);

        /** @var OrderDamage $damage */
        $damage = OrderDamage::create([
            'business_account_id' => $order->business_account_id,
            'website_id' => $order->website_id,
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'source' => $source,
            'in_custody' => false,
            'sku' => $item->sku,
            'product_name' => $item->product_name,
            'variant_label' => $item->variant_label,
            'quantity' => $report->quantity,
            'damage_condition' => $report->condition,
            'description' => $report->description === null ? null : trim($report->description),
            'evidence' => $this->evidence($report),
            'status' => DamageStatus::Reported,
            'initiated_by_type' => $initiator,
            'currency_code' => $value->maximum->currency->value,
            'valuation_basis' => $value->basis,
            'valuation_unit_price' => $value->unitPrice,
            'max_recoverable_value' => $value->maximum,
            'valuation_snapshot' => $value->snapshot,
            'reported_by' => $actor->id,
            'reported_at' => CarbonImmutable::now(),
            'idempotency_key' => $report->idempotencyKey,
            ...$extra,
        ]);

        $damage->recordOpening(
            new StatusChange(
                actorId: $actor->id,
                reason: 'Damage reported.',
                publicNote: 'damages.notes.reported',
            ),
            $this->source($initiator),
        );

        return $damage;
    }

    protected function move(OrderDamage $damage, DamageStatus $to, User $actor, ReturnInitiator $initiator, string $reason, string $publicNote): void
    {
        $damage->moveTo($to, new StatusChange(actorId: $actor->id, reason: $reason, publicNote: $publicNote), $this->source($initiator));
    }

    protected function source(ReturnInitiator $initiator): OrderStatusChangeSource
    {
        return $initiator === ReturnInitiator::Staff ? OrderStatusChangeSource::Staff : OrderStatusChangeSource::Account;
    }

    /**
     * Who is reporting: the Partner whose order it is, or staff who may inspect.
     *
     * A Partner is never "staff" here: the permission gate refuses every
     * `damage.*` ability to a business identity, so being asked is enough to tell
     * them apart, and a Partner's own scope is checked from their own account.
     *
     * @throws AuthorizationException
     */
    protected function authorizeDelivered(User $actor, Order $order): ReturnInitiator
    {
        if ($actor->can(PermissionCatalogue::name(PermissionModule::Damage, PermissionAction::Inspect))) {
            return ReturnInitiator::Staff;
        }

        $account = $actor->businessAccount;

        if ($account === null || $account->id !== $order->business_account_id
            || ! ($actor->accountRole()?->hasPermission(AccountPermission::ViewOrders) ?? false)) {
            throw new AuthorizationException('This account may not report damage on that order.');
        }

        return ReturnInitiator::Partner;
    }

    /**
     * @throws DamageRefused
     */
    protected function requireDelivered(Order $order): void
    {
        if (! in_array($order->status, [OrderStatus::Delivered, OrderStatus::Completed, OrderStatus::ReturnRequested], true)) {
            throw DamageRefused::notDelivered();
        }

        $closes = $this->eligibility->windowClosesAt($order);

        if ($closes !== null && $closes->isPast()) {
            throw DamageRefused::windowClosed($closes->toIso8601String());
        }
    }

    /**
     * @throws DamageRefused
     */
    protected function requireEvidence(DamageReport $report): void
    {
        if (count($report->evidence) > self::MAXIMUM_EVIDENCE) {
            throw DamageRefused::tooMuchEvidence();
        }

        $described = mb_strlen(trim((string) $report->description)) >= self::MINIMUM_DESCRIPTION;

        if (! $described && $this->evidence($report) === []) {
            throw DamageRefused::needsEvidence();
        }
    }

    /**
     * The evidence, reduced to what is kept: a kind, a reference and a note.
     * References to files held elsewhere, never the files themselves.
     *
     * @return list<array{type: string, reference: string, note: string|null}>
     */
    protected function evidence(DamageReport $report): array
    {
        $kept = [];

        foreach ($report->evidence as $piece) {
            $reference = trim($piece['reference']);

            if ($reference === '') {
                continue;
            }

            $type = (string) ($piece['type'] ?? 'photo');
            $note = isset($piece['note']) ? trim((string) $piece['note']) : null;

            $kept[] = [
                'type' => in_array($type, ['photo', 'video', 'document', 'other'], true) ? $type : 'other',
                'reference' => mb_substr($reference, 0, 255),
                'note' => $note === null || $note === '' ? null : mb_substr($note, 0, 500),
            ];
        }

        return $kept;
    }

    protected function existing(int $accountId, ?string $key): ?OrderDamage
    {
        if ($key === null) {
            return null;
        }

        /** @var OrderDamage|null $damage */
        $damage = OrderDamage::query()
            ->where('business_account_id', $accountId)
            ->where('idempotency_key', $key)
            ->first();

        return $damage;
    }

    protected function auditOpened(User $actor, OrderDamage $damage): void
    {
        $this->audit->handle(new AuditEntry(
            action: 'damage.reported',
            actorId: $actor->id,
            auditableType: OrderDamage::class,
            auditableId: $damage->id,
            after: [
                'reference' => $damage->reference,
                'source' => $damage->source->value,
                'quantity' => $damage->quantity,
                'condition' => $damage->damage_condition->value,
                'initiated_by' => $damage->initiated_by_type->value,
                'valuation_basis' => $damage->valuation_basis->value,
                'max_recoverable_value' => $damage->max_recoverable_value->toDecimal(),
            ],
            accountId: $damage->business_account_id,
            module: PermissionModule::Damage->value,
        ));
    }
}
