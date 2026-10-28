<?php

namespace App\Domain\Cases\Queries;

use App\Domain\Access\Enums\PermissionAction;
use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Access\PermissionCatalogue;
use App\Domain\Billing\Enums\RefundStatus;
use App\Domain\Cases\CaseNextAction;
use App\Domain\Cases\Enums\EvidencePurpose;
use App\Domain\Cases\Models\CaseEvidence;
use App\Domain\Courier\Models\CourierProvider;
use App\Domain\Damage\Enums\DamageCause;
use App\Domain\Damage\Enums\DamageCondition;
use App\Domain\Damage\Enums\DamageStatus;
use App\Domain\Damage\Models\OrderDamage;
use App\Domain\Damage\Queries\DamagePayload;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Order\Actions\DecideOrderReturn;
use App\Domain\Order\Enums\ReturnChargeStatus;
use App\Domain\Order\Enums\ReturnDisposition;
use App\Domain\Order\Enums\ReturnParty;
use App\Domain\Order\Enums\ReturnRefundState;
use App\Domain\Order\Enums\ReturnStatus;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderReturn;
use App\Domain\Order\Models\OrderReturnItem;
use App\Domain\Order\Queries\ReturnRefundAmount;
use App\Models\User;
use App\Support\Money\Money;

/**
 * One case as the staff workspace reads it (D35): the header, the affected items,
 * the evidence, the inspection, the money, the linked orders, the timeline and the
 * accounting — and **only the next steps this person may take**.
 *
 * Every action offered is one the server will accept at this stage for this viewer:
 * the buttons are a convenience, never the boundary, and each action's route checks
 * the permission and the stage again. There is no status to pick. Before an action
 * that moves money, stock or responsibility, the payload carries the **server's own**
 * confirmation — the product and quantity, who bears it, the amounts, what happens
 * to the stock, and whether a reason is required — so what the person confirms is
 * what will happen, worked out here and not in the browser.
 */
class AdminCasePayload
{
    public function __construct(
        protected EvidencePayload $evidence,
        protected CaseFinancials $financials,
        protected CaseTimeline $timeline,
        protected ProductImage $images,
        protected ReturnRefundAmount $refundAmounts,
        protected DamagePayload $damagePayload,
        protected CaseLinks $links,
    ) {}

    /**
     * The original Order, the Exchange Order and the exchanges before and after, each
     * with an address only where this person may open it.
     *
     * @return array<string, mixed>
     */
    protected function linksFor(User $viewer, OrderReturn $case): array
    {
        $links = $this->links->forStaff($viewer, $case);

        return [
            'order' => ['id' => $case->order->public_id, ...$links['order']],
            'exchange_order' => $links['exchange_order'] === null ? null : [...$links['exchange_order'], 'number' => $case->exchange_number],
            'previous_exchange' => $links['previous_exchange'],
            'next_exchange' => $links['next_exchange'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function forReturn(User $viewer, OrderReturn $case): array
    {
        $case->loadMissing([
            'order:id,public_id,reference,currency_code,source,payment_id', 'order.payment:id,gateway,status',
            'businessAccount:id,name', 'website:id,name',
            'items.orderItem.product', 'items.orderItem.stockReservation', 'items.warehouse:id,code,name',
            'exchangeOrder.items', 'exchangeOrder:id,public_id,reference,status', 'previousExchange:id,reference,exchange_number',
            'refundRequest', 'evidenceFiles.file', 'evidenceFiles.uploader:id,name',
        ]);

        $kind = $case->isExchange() ? 'exchange' : 'return';
        $version = (int) $case->workflow_version;
        $status = $case->status;
        $replacements = $case->exchangeOrder?->items->keyBy('replaces_order_item_id');

        $items = $case->items->map(fn (OrderReturnItem $line) => [
            'id' => $line->public_id,
            'sku' => $line->orderItem->sku,
            'name' => $line->orderItem->product_name,
            'variant' => $line->orderItem->variant_label,
            'image' => $this->images->for($line->orderItem),
            'sold' => $line->orderItem->quantity,
            'requested' => $line->quantity,
            'approved' => $line->approved_quantity,
            'received' => $line->received_quantity,
            'disposition' => $line->disposition?->value,
            'disposition_label' => $line->disposition?->label(),
            'restored' => $line->isRestored(),
            'note' => $line->inspection_note,
            'unit_price' => $line->orderItem->unit_price->jsonSerialize(),
            'supplier_backed' => $line->orderItem->isSupplierBacked(),
            'replaced_with' => $replacements?->get($line->orderItem->id) === null ? null : [
                'sku' => $replacements->get($line->orderItem->id)->sku,
                'name' => $replacements->get($line->orderItem->id)->product_name,
                'quantity' => $replacements->get($line->orderItem->id)->quantity,
            ],
        ])->values()->all();

        $damages = OrderDamage::query()->where('order_return_id', $case->id)->get();

        return [
            'case' => [
                'kind' => $kind,
                'id' => $case->public_id,
                'reference' => $case->reference,
                'legacy' => $version < 2,
                'status' => $status->value,
                'status_label' => $status->label(),
                'status_tone' => $status->tone(),
                'responsibility' => $case->responsible_party?->value,
                'responsibility_label' => $case->responsible_party?->label(),
                'initiated_by' => $case->initiated_by_type?->label(),
                'reason_label' => $case->reason->label(),
                'description' => $case->customer_note,
                'created_at' => $case->requested_at->toIso8601String(),
                'decided_at' => $case->decided_at?->toIso8601String(),
                'received_at' => $case->received_at?->toIso8601String(),
                'inspected_at' => $case->inspected_at?->toIso8601String(),
                ...$this->linksFor($viewer, $case),
                'partner' => $case->businessAccount->name,
                'store' => $case->website?->name,
                'cash_on_delivery' => $case->isCashOnDelivery(),
                'next_action' => CaseNextAction::offeredTo($viewer, $kind, $status->value, $version),
                'waiting_for' => CaseNextAction::waitingOn($kind, $status->value, $version),
                'legacy_url' => $version < 2 ? route('admin.returns.show', $case->public_id) : null,
            ],
            'items' => $items,
            'evidence' => $this->evidenceFor($case->evidenceFiles),
            // What the earlier, reference-only way recorded, shown as it was written.
            'legacy_evidence' => $this->evidence->legacy($case->evidence),
            'inspection' => [
                'recorded' => $case->inspection_outcome !== null,
                'outcome' => $case->inspection_outcome,
                'note' => $case->inspection_note,
                'inspected_at' => $case->inspected_at?->toIso8601String(),
                'dispositions' => $this->dispositions(true),
                'warehouses' => $this->warehouses(),
            ],
            'financials' => $this->financials->forReturn($case, $viewer),
            'linked' => [
                'damages' => $damages->map(fn (OrderDamage $damage) => [
                    'reference' => $damage->reference,
                    'status_label' => $damage->status->label(),
                    'url' => route('admin.cases.damages.show', $damage->public_id),
                ])->all(),
            ],
            'timeline' => $this->timeline->forReturn($case, true),
            'actions' => $version < 2 ? [] : $this->returnActions($viewer, $case, $items),
            'evidence_purposes' => $this->purposes($viewer, $case),
            'evidence_url' => route('admin.cases.returns.evidence.store', $case->public_id),
            // Opening a damage is the inspector's: only offered to someone who may do it.
            'open_damage_url' => $viewer->can(PermissionCatalogue::name(PermissionModule::Damage, PermissionAction::Inspect))
                ? route('admin.cases.returns.damages.store', $case->public_id)
                : null,
            'warnings' => collect([
                collect($items)->contains(fn (array $item) => $item['supplier_backed']) ? [
                    'key' => 'supplier_backed',
                    'text' => (string) __('cases.warnings.supplier_backed'),
                ] : null,
            ])->filter()->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function forDamage(User $viewer, OrderDamage $damage): array
    {
        $damage->loadMissing([
            'order:id,public_id,reference,currency_code', 'businessAccount:id,name', 'website:id,name',
            'orderItem.product', 'orderItem.stockReservation', 'orderReturn:id,public_id,reference',
            'dispositions', 'dispatch.courierProvider', 'evidenceFiles.file', 'evidenceFiles.uploader:id,name', 'warehouse:id,code,name', 'charges',
        ]);

        $status = $damage->status;
        $remainsWithCustomer = ! $damage->in_custody && ! $damage->parcel_expected;

        return [
            'case' => [
                'kind' => 'damage',
                'id' => $damage->public_id,
                'reference' => $damage->reference,
                'legacy' => false,
                'status' => $status->value,
                'status_label' => $status->label(),
                'status_tone' => $status->tone(),
                'responsibility' => $damage->responsible_party?->value,
                'responsibility_label' => $damage->responsible_party?->label(),
                'initiated_by' => $damage->initiated_by_type->label(),
                'source_label' => $damage->source->label(),
                'description' => $damage->description,
                'created_at' => $damage->reported_at->toIso8601String(),
                'received_at' => $damage->received_at?->toIso8601String(),
                'inspected_at' => $damage->inspected_at?->toIso8601String(),
                'order' => $this->order($viewer, $damage->order),
                'return' => $damage->orderReturn === null ? null : [
                    'reference' => $damage->orderReturn->reference,
                    'url' => route('admin.cases.returns.show', $damage->orderReturn->public_id),
                ],
                'partner' => $damage->businessAccount->name,
                'store' => $damage->website?->name,
                'remains_with_customer' => $remainsWithCustomer,
                'next_action' => CaseNextAction::offeredTo($viewer, 'damage', $status->value),
                'waiting_for' => CaseNextAction::waitingOn('damage', $status->value),
            ],
            'items' => [[
                'id' => $damage->public_id,
                'sku' => $damage->sku,
                'name' => $damage->product_name,
                'variant' => $damage->variant_label,
                'image' => $this->images->for($damage->orderItem),
                'sold' => $damage->orderItem->quantity,
                'requested' => $damage->quantity,
                'approved' => null,
                'received' => null,
                'disposition' => null,
                'disposition_label' => null,
                'restored' => false,
                'note' => null,
                'unit_price' => $damage->orderItem->unit_price->jsonSerialize(),
                'supplier_backed' => false,
                'replaced_with' => null,
                'condition_label' => $damage->damage_condition->label(),
            ]],
            'evidence' => $this->evidenceFor($damage->evidenceFiles),
            'legacy_evidence' => $this->evidence->legacy($damage->evidence),
            'inspection' => [
                'recorded' => $damage->inspected_at !== null,
                'outcome' => $damage->inspected_at === null ? null : 'recorded',
                'note' => $damage->inspection_note,
                'inspected_at' => $damage->inspected_at?->toIso8601String(),
                'cause_label' => $damage->cause?->label(),
                'dispositions' => $this->dispositions($damage->in_custody),
                'recorded_dispositions' => $damage->dispositions->map(fn ($row) => [
                    'label' => $row->disposition->label(),
                    'quantity' => $row->quantity,
                ])->all(),
                'warehouses' => $this->warehouses(),
                'needs_warehouse' => $damage->warehouse_id === null && $damage->orderItem->stockReservation?->isPartnerSourced() !== true,
                'causes' => array_map(fn (DamageCause $cause) => ['value' => $cause->value, 'label' => $cause->label()], DamageCause::cases()),
                'conditions' => array_map(fn (DamageCondition $condition) => ['value' => $condition->value, 'label' => $condition->label()], DamageCondition::cases()),
                'valuation' => [
                    'basis_label' => $damage->valuation_basis->label(),
                    'maximum' => $damage->max_recoverable_value->jsonSerialize(),
                    'approved' => $damage->approved_loss_amount?->jsonSerialize(),
                    'write_off_reason' => $damage->write_off_reason,
                ],
            ],
            'financials' => $this->financials->forDamage($damage, $viewer),
            'linked' => ['damages' => []],
            'delivery' => $damage->dispatch === null ? null : [
                'status' => $damage->dispatch->status->value,
                'status_label' => $damage->dispatch->status->label(),
                'quantity' => $damage->dispatch->quantity,
                'tracking_number' => $damage->dispatch->tracking_number,
                'courier' => $damage->dispatch->courierProvider?->name,
                'requested_at' => $damage->dispatch->requested_at->toIso8601String(),
                'dispatched_at' => $damage->dispatch->dispatched_at?->toIso8601String(),
                'received_at' => $damage->dispatch->received_at?->toIso8601String(),
            ],
            'couriers' => CourierProvider::query()->where('is_enabled', true)->orderBy('name')->get(['code', 'name'])
                ->map(fn (CourierProvider $courier) => ['code' => $courier->code, 'name' => $courier->name])->all(),
            'storage_state' => $this->damagePayload->storageState($damage),
            'timeline' => $this->timeline->forDamage($damage, true),
            'actions' => $this->damageActions($viewer, $damage),
            'evidence_purposes' => $this->purposes($viewer, $damage),
            'evidence_url' => route('admin.cases.damages.evidence.store', $damage->public_id),
            'open_damage_url' => null,
            'warnings' => [],
        ];
    }

    /**
     * What the Partner or staff attached, ready to open.
     *
     * @param  iterable<int, CaseEvidence>  $files
     * @return list<array<string, mixed>>
     */
    protected function evidenceFor(iterable $files): array
    {
        $shown = [];

        foreach ($files as $file) {
            if (! $file->isDraft()) {
                $shown[] = $this->evidence->for($file);
            }
        }

        return $shown;
    }

    /**
     * @return array{id: string, reference: string, url: string|null}
     */
    protected function order(User $viewer, Order $order): array
    {
        return [
            'id' => $order->public_id,
            'reference' => $order->reference,
            // Only to someone who may open Orders: a reference is never a dead link.
            'url' => $viewer->can('viewAny', Order::class) ? route('admin.orders.show', $order->public_id) : null,
        ];
    }

    /**
     * What an inspector may choose, reflecting what the backend can do.
     *
     * "Dispatched to Partner" is never here: it is a later damage action, not a
     * finding. "Returned to Supplier" is not offered while the supplier ledger has
     * no movement for it.
     *
     * @return list<array{value: string, label: string}>
     */
    protected function dispositions(bool $inCustody): array
    {
        $options = $inCustody
            ? [ReturnDisposition::Restock, ReturnDisposition::Damaged, ReturnDisposition::Quarantine]
            : [ReturnDisposition::Damaged];

        return array_map(fn (ReturnDisposition $disposition) => ['value' => $disposition->value, 'label' => $disposition->label()], $options);
    }

    /**
     * @return list<array{id: string, label: string}>
     */
    protected function warehouses(): array
    {
        return array_values(Warehouse::query()
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->orderBy('code')
            ->get(['public_id', 'code', 'name'])
            ->map(fn (Warehouse $warehouse) => ['id' => $warehouse->public_id, 'label' => $warehouse->code.' · '.$warehouse->name])
            ->all());
    }

    /**
     * The kinds of evidence this person may add to this case.
     *
     * @return list<array{value: string, label: string}>
     */
    protected function purposes(User $viewer, OrderReturn|OrderDamage $case): array
    {
        $allowed = [];

        foreach (EvidencePurpose::forStaff() as $purpose) {
            $permission = $case instanceof OrderDamage
                ? PermissionCatalogue::name(PermissionModule::Damage, $purpose === EvidencePurpose::ResponsibilityReview ? PermissionAction::AssignLiability : PermissionAction::Inspect)
                : PermissionCatalogue::name(PermissionModule::ReturnCase, match ($purpose) {
                    EvidencePurpose::Receipt => PermissionAction::Receive,
                    EvidencePurpose::Inspection => PermissionAction::Inspect,
                    default => PermissionAction::Review,
                });

            if ($viewer->can($permission)) {
                $allowed[] = ['value' => $purpose->value, 'label' => $purpose->label()];
            }
        }

        return $allowed;
    }

    /**
     * The valid next steps of a return or exchange case, for this viewer.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    protected function returnActions(User $viewer, OrderReturn $case, array $items): array
    {
        $kind = $case->isExchange() ? 'exchange' : 'return';
        $status = $case->status;
        $can = fn (PermissionModule $module, PermissionAction $action) => $viewer->can(PermissionCatalogue::name($module, $action));
        $url = fn (string $route) => route('admin.cases.returns.'.$route, $case->public_id);
        $reviewModule = $case->isExchange() ? PermissionModule::Exchange : PermissionModule::ReturnCase;
        $actions = [];

        $summary = fn (array $extra = []) => $this->confirmation($items, $case->responsible_party, $extra);

        if ($status === ReturnStatus::Requested && $can($reviewModule, PermissionAction::Review)) {
            $actions[] = $this->action('approve', $url('approval'), $summary(['reason' => DecideOrderReturn::MINIMUM_REASON]), 'primary');
            $actions[] = $this->action('reject', $url('rejection'), $summary(['reason' => DecideOrderReturn::MINIMUM_REASON]), 'danger');
        }

        if ($status === ReturnStatus::WaitingForParcel && $can(PermissionModule::ReturnCase, PermissionAction::Receive)) {
            $actions[] = $this->action('receive_parcel', $url('parcel'), $summary());
        }

        if ($status === ReturnStatus::ParcelReceived && $can(PermissionModule::ReturnCase, PermissionAction::Inspect)) {
            $actions[] = $this->action('start_inspection', $url('inspection-start'), $summary());
        }

        if ($status === ReturnStatus::UnderInspection && $can(PermissionModule::ReturnCase, PermissionAction::Inspect)) {
            $actions[] = $this->action('approve_inspection', $url('inspection-approval'), $summary(['disposition' => true]), 'primary');
            $actions[] = $this->action('reject_inspection', $url('inspection-rejection'), $summary(['reason' => 10]), 'danger');
        }

        if ($status === ReturnStatus::InspectionApproved && $can(PermissionModule::ReturnCase, PermissionAction::Refund)) {
            $actions[] = $this->action('settle', $url('settlement'), $summary(['amounts' => $this->settlementAmounts($case)]), 'primary');
        }

        if ($status === ReturnStatus::SettlementPending) {
            $refund = $case->refundRequest;

            if ($case->exchange_difference_state === 'collection_pending' && $can(PermissionModule::ReturnCase, PermissionAction::Refund)) {
                $actions[] = $this->action('record_collection', $url('collection'), $summary(['reason' => 10, 'amounts' => $this->collectionAmounts($case)]));
            }

            if ($refund?->status === RefundStatus::Requested && $can(PermissionModule::Payment, PermissionAction::Approve)) {
                $actions[] = $this->action('approve_refund', route('admin.returns.refund.decision.store', $case->public_id), $summary(['amounts' => $this->refundAmounts($case)]), 'primary', ['decision' => 'approve']);
            }

            if ($refund?->status === RefundStatus::Requested && $can(PermissionModule::Payment, PermissionAction::Reject)) {
                $actions[] = $this->action('reject_refund', route('admin.returns.refund.decision.store', $case->public_id), $summary(['reason' => 10]), 'danger', ['decision' => 'reject']);
            }

            if ($case->refund_state === ReturnRefundState::ManualReview && $can(PermissionModule::Payment, PermissionAction::ReverseTransaction)) {
                $actions[] = $this->action('settle_manually', route('admin.returns.refund.settlement.store', $case->public_id), $summary(['reason' => 10, 'amounts' => $this->refundAmounts($case)]), 'primary');
            }
        }

        // Putting right who bears a case is a decision of its own, and may follow a completed one.
        if (in_array($status, [ReturnStatus::Approved, ReturnStatus::WaitingForParcel, ReturnStatus::ParcelReceived, ReturnStatus::UnderInspection, ReturnStatus::InspectionApproved, ReturnStatus::SettlementPending, ReturnStatus::Completed], true)
            && $case->responsible_party !== null
            && $can(PermissionModule::ReturnCase, PermissionAction::Review)) {
            $to = $case->responsible_party === ReturnParty::Partner ? ReturnParty::Banij : ReturnParty::Partner;

            $actions[] = $this->action('change_responsibility', $url('responsibility'), $summary(['reason' => 10, 'amounts' => $this->chargeAmounts($case)]), 'default', ['party' => $to->value, 'party_label' => $to->label()]);
        }

        return $actions;
    }

    /**
     * The valid next steps of a damage case, for this viewer.
     *
     * @return list<array<string, mixed>>
     */
    protected function damageActions(User $viewer, OrderDamage $damage): array
    {
        $status = $damage->status;
        $can = fn (PermissionAction $action) => $viewer->can(PermissionCatalogue::name(PermissionModule::Damage, $action));
        $url = fn (string $route) => route('admin.cases.damages.'.$route, $damage->public_id);
        $items = [['name' => $damage->product_name, 'requested' => $damage->quantity]];
        $summary = fn (array $extra = []) => $this->confirmation($items, $damage->responsible_party, $extra);
        $actions = [];

        if ($status === DamageStatus::AwaitingReceipt && $can(PermissionAction::Inspect)) {
            $actions[] = $this->action('receive_parcel', $url('parcel'), $summary());
        }

        if ($status === DamageStatus::Received && $can(PermissionAction::Inspect)) {
            $actions[] = $this->action('start_inspection', $url('inspection-start'), $summary());
        }

        if ($status === DamageStatus::UnderInspection && $can(PermissionAction::Inspect)) {
            $actions[] = $this->action('record_inspection', $url('inspection'), $summary(['disposition' => true]), 'primary');
        }

        if (in_array($status, [DamageStatus::Reported, DamageStatus::AwaitingReceipt, DamageStatus::Received, DamageStatus::UnderInspection], true) && $can(PermissionAction::Inspect)) {
            $actions[] = $this->action('reject', $url('rejection'), $summary(['reason' => 10]), 'danger');
        }

        if ($status === DamageStatus::LiabilityReview && $can(PermissionAction::AssignLiability)) {
            $actions[] = $this->action('assign_liability', $url('liability'), $summary(['reason' => 10]), 'primary');
            $actions[] = $this->action('reject', $url('rejection'), $summary(['reason' => 10]), 'danger');
        }

        if ($status === DamageStatus::FinancialPostingPending && $can(PermissionAction::Finalize)) {
            $actions[] = $this->action('finalize', $url('finalization'), $summary(['amounts' => $this->lossAmounts($damage), 'disposition' => true]), 'primary');
        }

        if ($status->isDecided() && ! $status->hasDispatchStarted() && $damage->responsible_party !== null && $can(PermissionAction::AssignLiability)) {
            $to = $damage->responsible_party === ReturnParty::Partner ? ReturnParty::Banij : ReturnParty::Partner;

            $actions[] = $this->action('change_responsibility', $url('responsibility'), $summary(['reason' => 10, 'amounts' => $this->lossAmounts($damage)]), 'default', ['party' => $to->value, 'party_label' => $to->label()]);
        }

        if ($status === DamageStatus::DispatchRequested && $can(PermissionAction::Dispatch)) {
            $actions[] = $this->action('dispatch', $url('dispatch'), $summary(['disposition' => true]), 'primary');
        }

        if ($status === DamageStatus::Dispatched && $can(PermissionAction::Dispatch)) {
            $actions[] = $this->action('confirm_receipt', $url('receipt'), $summary());
        }

        foreach ($damage->charges as $charge) {
            if ($charge->status === ReturnChargeStatus::Outstanding && ! $charge->isReversal() && $can(PermissionAction::Finalize)) {
                $actions[] = $this->action('settle_outstanding', route('admin.cases.damages.outstanding', [$damage->public_id, $charge->public_id]), $summary(['amounts' => [['key' => 'outstanding', 'amount' => $charge->amount->jsonSerialize(), 'direction' => 'debit']]]));
            }
        }

        return $actions;
    }

    /**
     * @param  array<string, mixed>  $confirmation
     * @param  array<string, mixed>  $fields  values the form sends with the action, fixed by the server
     * @return array<string, mixed>
     */
    protected function action(string $key, string $url, array $confirmation, string $tone = 'default', array $fields = []): array
    {
        return [
            'key' => $key,
            'label' => (string) __('cases.actions.'.$key),
            'url' => $url,
            'tone' => $tone,
            'confirmation' => $confirmation,
            'fields' => $fields,
        ];
    }

    /**
     * What the person is shown before they confirm: the product and quantity,
     * who bears it, the amounts, what happens to the stock, and whether a reason
     * is required.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @param  array{reason?: int, amounts?: list<array<string, mixed>>, disposition?: bool}  $extra
     * @return array<string, mixed>
     */
    protected function confirmation(array $items, ?ReturnParty $responsible, array $extra = []): array
    {
        return [
            'items' => array_map(fn (array $item) => [
                'name' => $item['name'],
                'variant' => $item['variant'] ?? null,
                'quantity' => $item['requested'],
            ], $items),
            'responsible' => $responsible?->value,
            'responsible_label' => $responsible?->label(),
            'amounts' => $extra['amounts'] ?? [],
            'shows_disposition' => (bool) ($extra['disposition'] ?? false),
            'reason_required' => isset($extra['reason']),
            'reason_minimum' => $extra['reason'] ?? null,
        ];
    }

    /**
     * What settling posts and opens, from the server's own snapshots.
     *
     * @return list<array<string, mixed>>
     */
    protected function settlementAmounts(OrderReturn $case): array
    {
        $amounts = $this->chargeAmounts($case);

        if (! $case->isExchange()) {
            try {
                $amounts[] = ['key' => 'product_refund', 'amount' => $this->refundAmounts($case)[0]['amount'] ?? null, 'direction' => 'credit'];
            } catch (\Throwable) {
                // Nothing is shown rather than a guess.
            }
        } elseif ($case->exchange_refund_due?->isPositive() === true) {
            $amounts[] = ['key' => 'exchange_refund_due', 'amount' => $case->exchange_refund_due->jsonSerialize(), 'direction' => 'credit'];
        }

        return array_values(array_filter($amounts, fn (array $row) => $row['amount'] !== null));
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function chargeAmounts(OrderReturn $case): array
    {
        $rows = [['key' => 'return_charge', 'amount' => $case->return_charge?->jsonSerialize(), 'direction' => 'debit']];

        if ($case->isExchange()) {
            $rows[] = ['key' => 'exchange_delivery', 'amount' => $case->exchange_delivery_charge?->jsonSerialize(), 'direction' => 'debit'];
        }

        return array_values(array_filter($rows, fn (array $row) => $row['amount'] !== null));
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function refundAmounts(OrderReturn $case): array
    {
        $amount = $case->refund_amount ?? ($case->isExchange() ? $case->exchange_refund_due : $this->estimatedRefund($case));

        return $amount === null ? [] : [['key' => 'product_refund', 'amount' => $amount->jsonSerialize(), 'direction' => 'credit']];
    }

    protected function estimatedRefund(OrderReturn $case): ?Money
    {
        try {
            return $this->refundAmounts->for($case)['total'];
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function collectionAmounts(OrderReturn $case): array
    {
        return $case->exchange_additional_due === null ? [] : [[
            'key' => 'exchange_additional_due', 'amount' => $case->exchange_additional_due->jsonSerialize(), 'direction' => 'credit',
        ]];
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function lossAmounts(OrderDamage $damage): array
    {
        return $damage->approved_loss_amount === null ? [] : [[
            'key' => 'damage_loss', 'amount' => $damage->approved_loss_amount->jsonSerialize(), 'direction' => 'debit',
        ]];
    }
}
