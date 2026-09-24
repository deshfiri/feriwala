<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Access\Enums\PermissionAction;
use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Access\Exceptions\SensitiveActionRefused;
use App\Domain\Access\PermissionCatalogue;
use App\Domain\Billing\Actions\DecideRefund;
use App\Domain\Billing\Enums\RefundStatus;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Order\Actions\DecideOrderReturn;
use App\Domain\Order\Actions\ReceiveReturnedItems;
use App\Domain\Order\Actions\RefundOrderReturn;
use App\Domain\Order\Actions\SettleReturnRefundManually;
use App\Domain\Order\Data\ReturnedLine;
use App\Domain\Order\Enums\ReturnDisposition;
use App\Domain\Order\Enums\ReturnRefundState;
use App\Domain\Order\Enums\ReturnStatus;
use App\Domain\Order\Exceptions\ReturnRefused;
use App\Domain\Order\Models\OrderReturn;
use App\Domain\Order\Models\OrderReturnItem;
use App\Domain\Order\Models\OrderReturnStatusChange;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The platform's returns desk (§18.2, §19.1, §26.3, P6-12).
 *
 * Every step of a return is a different job and asks for its own permission,
 * so nobody can take goods back, put them on sale and send the money in one
 * sitting unless they hold every one of those rights:
 *
 *   - reading returns is `order.view`, or a payment right that works one of
 *     its money steps (`payment.approve`, `payment.reverse_transaction`);
 *   - approving is `order.approve`, refusing is `order.reject`, both with a reason;
 *   - counting goods back into stock is `order.edit` **and** `inventory.edit`;
 *   - opening the refund is `order.approve`;
 *   - deciding the refund is `payment.approve` / `payment.reject` (D17);
 *   - sending it is `payment.reverse_transaction`, on the existing refund
 *     route, behind a freshly confirmed password;
 *   - recording a refund settled by hand is the same reversal permission,
 *     password and two-factor.
 *
 * All of them are platform permissions, which the ordered `Gate::before`
 * refuses to anybody holding a business identity — owning the shop a return
 * came through is never a way in here.
 */
class OrderReturnController extends Controller
{
    public const PER_PAGE = 25;

    public function __construct(
        protected DecideOrderReturn $decisions,
        protected ReceiveReturnedItems $receipts,
        protected RefundOrderReturn $refunds,
        protected SettleReturnRefundManually $settlements,
        protected DecideRefund $refundDecisions,
    ) {}

    public function index(Request $request): Response
    {
        $this->reader($request);

        $status = ReturnStatus::tryFrom($request->string('status')->toString());
        $search = trim($request->string('search')->toString());
        $search = $search === '' ? null : mb_substr($search, 0, 120);

        $returns = OrderReturn::query()
            ->with(['order:id,reference,public_id', 'businessAccount:id,name', 'website:id,name'])
            ->withSum('items as quantity', 'quantity')
            ->when($status !== null, fn (Builder $query) => $query->where('status', $status))
            ->when($search !== null, fn (Builder $query) => $query->where(fn (Builder $inner) => $inner
                ->where('reference', 'ilike', "%{$search}%")
                ->orWhereHas('order', fn (Builder $order) => $order->where('reference', 'ilike', "%{$search}%"))
                ->orWhereHas('businessAccount', fn (Builder $account) => $account->where('name', 'ilike', "%{$search}%"))))
            // What somebody has to act on first: waiting for a decision, then goods on the way, then money.
            ->orderByRaw("CASE status WHEN 'requested' THEN 0 WHEN 'approved' THEN 1 WHEN 'received' THEN 2 ELSE 3 END")
            ->orderByDesc('requested_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (OrderReturn $return) => [
                'id' => $return->public_id,
                'reference' => $return->reference,
                'order' => $return->order->reference,
                'account' => $return->businessAccount->name,
                'website' => $return->website?->name,
                'status' => $return->status->value,
                'status_tone' => $return->status->tone(),
                'refund_state' => $return->refund_state->value,
                'refund_tone' => $return->refund_state->tone(),
                'needs_attention' => $return->refund_state->needsAttention(),
                'reason' => $return->reason->value,
                'quantity' => (int) $return->getAttribute('quantity'),
                'requested_at' => $return->requested_at->toIso8601String(),
            ]);

        return Inertia::render('admin/returns/index', [
            'returns' => $returns,
            'filters' => ['search' => $search, 'status' => $status?->value],
            'statuses' => array_map(fn (ReturnStatus $status) => $status->value, ReturnStatus::cases()),
        ]);
    }

    public function show(Request $request, string $return): Response
    {
        $actor = $this->reader($request);

        $record = $this->find($return);
        $record->load([
            // `source` too: whether the money came on delivery is read from it.
            'order:id,public_id,reference,source,currency_code,payment_id',
            'order.payment:id,gateway,status',
            'businessAccount:id,name',
            'website:id,name',
            'items.orderItem',
            'items.warehouse:id,code,name',
            'statusHistory.changedBy:id,name',
            'refundRequest',
        ]);

        $refund = $record->refundRequest;
        $can = fn (PermissionModule $module, PermissionAction $action) => $actor->can(PermissionCatalogue::name($module, $action));

        return Inertia::render('admin/returns/show', [
            'orderReturn' => [
                'id' => $record->public_id,
                'reference' => $record->reference,
                'status' => $record->status->value,
                'status_tone' => $record->status->tone(),
                'reason' => $record->reason->value,
                'customer_note' => $record->customer_note,
                'evidence' => $record->evidence ?? [],
                'decision_note' => $record->decision_note,
                'source' => $record->source->value,
                'requested_at' => $record->requested_at->toIso8601String(),
                'decided_at' => $record->decided_at?->toIso8601String(),
                'received_at' => $record->received_at?->toIso8601String(),
                'order' => ['id' => $record->order->public_id, 'reference' => $record->order->reference],
                'account' => $record->businessAccount->name,
                'website' => $record->website?->name,
                'cash_on_delivery' => $record->isCashOnDelivery(),
                'lines' => $record->items->map(fn (OrderReturnItem $item) => [
                    'id' => $item->public_id,
                    'sku' => $item->orderItem->sku,
                    'name' => $item->orderItem->product_name,
                    'variant' => $item->orderItem->variant_label,
                    'sold' => $item->orderItem->quantity,
                    'quantity' => $item->quantity,
                    'approved_quantity' => $item->approved_quantity,
                    'received_quantity' => $item->received_quantity,
                    'disposition' => $item->disposition?->value,
                    'warehouse' => $item->warehouse === null ? null : $item->warehouse->code.' · '.$item->warehouse->name,
                    'restored' => $item->isRestored(),
                    'unit_price' => $item->orderItem->unit_price->jsonSerialize(),
                    'refund_amount' => $item->refund_amount?->jsonSerialize(),
                ])->all(),
                'refund' => [
                    'state' => $record->refund_state->value,
                    'tone' => $record->refund_state->tone(),
                    'amount' => $record->refund_amount?->jsonSerialize(),
                    // A key when the system wrote it, a person's words when a person did.
                    'note' => $record->refund_note === null ? null : (string) __($record->refund_note),
                    'refunded_at' => $record->refunded_at?->toIso8601String(),
                    'request' => $refund === null ? null : [
                        'id' => $refund->public_id,
                        'status' => $refund->status->value,
                        'failure_reason' => $refund->failure_reason,
                    ],
                ],
                'history' => $record->statusHistory->map(fn (OrderReturnStatusChange $change) => [
                    'previous_status' => $change->previous_status?->value,
                    'new_status' => $change->new_status->value,
                    'at' => $change->changed_at->toIso8601String(),
                    'source' => $change->source->value,
                    'actor' => $change->changedBy?->name,
                    'reason' => $change->reason,
                    'internal_note' => $change->internal_note,
                    'public_note' => $change->public_note === null ? null : (string) __($change->public_note),
                ])->all(),
            ],
            'warehouses' => Warehouse::query()
                ->where('is_active', true)
                ->orderByDesc('is_default')
                ->orderBy('code')
                ->get(['id', 'public_id', 'code', 'name'])
                ->map(fn (Warehouse $warehouse) => ['id' => $warehouse->public_id, 'label' => $warehouse->code.' · '.$warehouse->name])
                ->all(),
            'dispositions' => array_map(fn (ReturnDisposition $disposition) => $disposition->value, ReturnDisposition::cases()),
            'can' => [
                'approve' => $record->status === ReturnStatus::Requested && $can(PermissionModule::Order, PermissionAction::Approve),
                'reject' => $record->status === ReturnStatus::Requested && $can(PermissionModule::Order, PermissionAction::Reject),
                'receive' => $record->status === ReturnStatus::Approved
                    && $can(PermissionModule::Order, PermissionAction::Edit)
                    && $can(PermissionModule::Inventory, PermissionAction::Edit),
                'start_refund' => $record->status === ReturnStatus::Received
                    && in_array($record->refund_state, [ReturnRefundState::NotRequired, ReturnRefundState::Failed], true)
                    && $refund === null
                    && $can(PermissionModule::Order, PermissionAction::Approve),
                'decide_refund' => $refund?->status === RefundStatus::Requested
                    && $can(PermissionModule::Payment, PermissionAction::Approve),
                'send_refund' => in_array($refund?->status, [RefundStatus::Approved, RefundStatus::Failed], true)
                    && $can(PermissionModule::Payment, PermissionAction::ReverseTransaction),
                'settle_manually' => $record->refund_state === ReturnRefundState::ManualReview
                    && $record->status === ReturnStatus::Received
                    && $can(PermissionModule::Payment, PermissionAction::ReverseTransaction),
            ],
        ]);
    }

    public function approve(Request $request, string $return): RedirectResponse
    {
        $actor = $this->require($request, PermissionModule::Order, PermissionAction::Approve);
        $record = $this->find($return);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:'.DecideOrderReturn::MINIMUM_REASON, 'max:1000'],
            'quantities' => ['sometimes', 'array'],
            'quantities.*' => ['integer', 'min:0'],
        ]);

        return $this->attempt(fn () => $this->decisions->approve(
            $actor,
            $record,
            array_map('intval', $validated['quantities'] ?? []),
            $validated['reason'],
        ), 'returns.admin.flash.approved', 'reason');
    }

    public function reject(Request $request, string $return): RedirectResponse
    {
        $actor = $this->require($request, PermissionModule::Order, PermissionAction::Reject);
        $record = $this->find($return);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:'.DecideOrderReturn::MINIMUM_REASON, 'max:1000'],
        ]);

        return $this->attempt(
            fn () => $this->decisions->reject($actor, $record, $validated['reason']),
            'returns.admin.flash.rejected',
            'reason',
        );
    }

    public function receive(Request $request, string $return): RedirectResponse
    {
        // Both rights before anything is read from the request: somebody who
        // may not change stock is told so, not told their form was incomplete.
        $actor = $this->require($request, PermissionModule::Order, PermissionAction::Edit);
        abort_unless($actor->can(PermissionCatalogue::name(PermissionModule::Inventory, PermissionAction::Edit)), 403);
        $record = $this->find($return);

        $validated = $request->validate([
            'warehouse' => ['required', 'string', 'exists:warehouses,public_id'],
            'note' => ['nullable', 'string', 'max:1000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.quantity' => ['required', 'integer', 'min:0'],
            'lines.*.disposition' => ['required', Rule::enum(ReturnDisposition::class)],
        ]);

        /** @var Warehouse $warehouse */
        $warehouse = Warehouse::query()->where('public_id', $validated['warehouse'])->firstOrFail();

        $lines = [];

        foreach ($validated['lines'] as $lineId => $line) {
            $lines[] = new ReturnedLine((string) $lineId, (int) $line['quantity'], ReturnDisposition::from($line['disposition']));
        }

        return $this->attempt(
            fn () => $this->receipts->handle($actor, $record, $lines, $warehouse, $validated['note'] ?? null),
            'returns.admin.flash.received',
            'lines',
        );
    }

    public function refund(Request $request, string $return): RedirectResponse
    {
        $actor = $this->require($request, PermissionModule::Order, PermissionAction::Approve);
        $record = $this->find($return);

        return $this->attempt(fn () => $this->refunds->handle($actor, $record), 'returns.admin.flash.refund_started', 'refund');
    }

    /**
     * Decide the refund request a return opened (D17).
     *
     * The same decision every refund gets, taken here because this is where
     * the person deciding can see what came back and in what state.
     */
    public function decideRefund(Request $request, string $return): RedirectResponse
    {
        $actor = $this->actor($request);
        $record = $this->find($return);

        $validated = $request->validate([
            'decision' => ['required', 'in:approve,reject'],
            'note' => ['nullable', 'required_if:decision,reject', 'string', 'max:1000'],
        ]);

        $approve = $validated['decision'] === 'approve';

        abort_unless($actor->can(PermissionCatalogue::name(
            PermissionModule::Payment,
            $approve ? PermissionAction::Approve : PermissionAction::Reject,
        )), 403);

        $refund = $record->refundRequest;

        if ($refund === null || $refund->status !== RefundStatus::Requested) {
            throw ValidationException::withMessages(['refund' => __('returns.admin.refused.refund_not_waiting')]);
        }

        if ($approve) {
            $this->refundDecisions->approve($refund, $actor, $validated['note'] ?? null);
        } else {
            $this->refundDecisions->reject($refund, $actor, (string) $validated['note']);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __($approve ? 'returns.admin.flash.refund_approved' : 'returns.admin.flash.refund_rejected')]);

        return back();
    }

    /**
     * Record a refund settled by hand (§28): a cash-on-delivery return, or one
     * no gateway can send.
     */
    public function settle(Request $request, string $return): RedirectResponse
    {
        $actor = $this->require($request, PermissionModule::Payment, PermissionAction::ReverseTransaction);
        $record = $this->find($return);

        $validated = $request->validate([
            'how' => ['required', 'string', 'min:'.SettleReturnRefundManually::MINIMUM_NOTE, 'max:1000'],
        ]);

        try {
            $this->settlements->handle(
                $actor,
                $record,
                $validated['how'],
                // Behind RequirePassword on the route: reaching here is the confirmation.
                passwordConfirmed: true,
                twoFactorEnabled: $actor->hasEnabledTwoFactorAuthentication(),
            );
        } catch (ReturnRefused $refused) {
            throw ValidationException::withMessages(['how' => $refused->getMessage()]);
        } catch (SensitiveActionRefused $refused) {
            abort(403, $refused->getMessage());
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('returns.admin.flash.settled')]);

        return back();
    }

    /**
     * Run a step, turning a refusal into a message on the form it came from.
     *
     * @param  callable(): mixed  $step
     */
    protected function attempt(callable $step, string $success, string $field): RedirectResponse
    {
        try {
            $step();
        } catch (ReturnRefused $refused) {
            throw ValidationException::withMessages([$field => $refused->getMessage()]);
        } catch (AuthorizationException) {
            abort(403);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __($success)]);

        return back();
    }

    protected function find(string $publicId): OrderReturn
    {
        /** @var OrderReturn $return */
        $return = OrderReturn::query()->where('public_id', $publicId)->firstOrFail();

        return $return;
    }

    /**
     * Somebody who may read the desk.
     *
     * Everybody who works one of its steps: the order staff who decide and
     * count goods back in, and the finance staff who decide, send or settle
     * the money — who could not otherwise open the return they are settling.
     */
    protected function reader(Request $request): User
    {
        $actor = $this->actor($request);

        abort_unless(
            $actor->can(PermissionCatalogue::name(PermissionModule::Order, PermissionAction::View))
            || $actor->can(PermissionCatalogue::name(PermissionModule::Payment, PermissionAction::Approve))
            || $actor->can(PermissionCatalogue::name(PermissionModule::Payment, PermissionAction::ReverseTransaction)),
            403,
        );

        return $actor;
    }

    protected function require(Request $request, PermissionModule $module, PermissionAction $action): User
    {
        $actor = $this->actor($request);

        abort_unless($actor->can(PermissionCatalogue::name($module, $action)), 403);

        return $actor;
    }

    protected function actor(Request $request): User
    {
        $actor = $request->user();

        abort_if(! $actor instanceof User, 403);

        return $actor;
    }
}
