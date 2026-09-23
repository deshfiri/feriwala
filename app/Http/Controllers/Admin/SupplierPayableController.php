<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Access\Enums\PermissionAction;
use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Access\PermissionCatalogue;
use App\Domain\Supplier\Actions\BulkSettleSupplierPayables;
use App\Domain\Supplier\Actions\SettleSupplierPayable;
use App\Domain\Supplier\Enums\PayableStatus;
use App\Domain\Supplier\Exceptions\SupplierPayableSettlementRefused;
use App\Domain\Supplier\Models\SupplierPayable;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Staff visibility into every Supplier payable, and its settlement (D25,
 * P13-22/P13-23).
 *
 * Viewing and settlement are deliberately separate permission strings —
 * `supplier_payable.view` and `supplier_payable.approve` — so a role can see
 * what is owed to Suppliers without holding the right to move money for it.
 * The settlement routes sit behind `RequirePassword`; reaching this
 * controller's `settle()`/`bulkSettle()` is the confirmation itself, the
 * same convention every other money-moving admin action in this application
 * already follows.
 */
class SupplierPayableController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', SupplierPayable::class);

        $payables = SupplierPayable::query()
            ->with(['supplier:id,public_id,business_name', 'order:id,reference'])
            ->when($request->filled('supplier'), fn ($q) => $q->whereHas('supplier', fn ($s) => $s->where('public_id', $request->string('supplier')->toString())))
            ->when($request->filled('order'), fn ($q) => $q->whereHas('order', fn ($o) => $o->where('reference', 'ilike', '%'.$request->string('order').'%')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')->toString()))
            ->when($request->filled('currency'), fn ($q) => $q->where('currency_code', $request->string('currency')->toString()))
            ->when($request->filled('date_from'), fn ($q) => $q->where('created_at', '>=', $request->date('date_from')))
            ->when($request->filled('date_to'), fn ($q) => $q->where('created_at', '<=', $request->date('date_to')))
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (SupplierPayable $payable) => $this->row($payable));

        $user = $request->user();

        return Inertia::render('admin/supplier-payables/index', [
            'payables' => $payables,
            'statuses' => array_map(
                fn (PayableStatus $case) => ['value' => $case->value, 'label' => $case->label()],
                PayableStatus::cases(),
            ),
            'can' => [
                // Not `Gate::allows('settle', SupplierPayable::class)` — the
                // policy method is typed to a real instance, and a class
                // string in its place would throw rather than refuse. Asked
                // for directly instead, exactly the permission `settle()`
                // itself wraps.
                'settle' => $user !== null && $user->can(PermissionCatalogue::name(PermissionModule::SupplierPayable, PermissionAction::Approve)),
            ],
            'password_confirmed' => $this->passwordConfirmed($request),
        ]);
    }

    public function show(Request $request, SupplierPayable $payable): Response
    {
        Gate::authorize('view', $payable);

        $payable->load(['supplier:id,public_id,business_name', 'order:id,reference', 'orderItem:id,product_name,variant_label', 'reversals', 'statusHistory.changedBy']);

        return Inertia::render('admin/supplier-payables/show', [
            'payable' => [
                ...$this->row($payable),
                'product_name' => $payable->orderItem->product_name,
                'variant_label' => $payable->orderItem->variant_label,
                'reversals' => $payable->reversals->map(fn ($reversal) => [
                    'quantity' => $reversal->quantity,
                    'amount' => $reversal->amount_minor->jsonSerialize(),
                    'reason' => $reversal->reason,
                    'wallet_reference' => $reversal->settlement_reversal_reference,
                    'created_at' => $reversal->created_at->toIso8601String(),
                ])->all(),
                'history' => $payable->statusHistory->map(fn ($change) => [
                    'previous_status' => $change->previous_status?->label(),
                    'new_status' => $change->new_status->label(),
                    'reason' => $change->reason,
                    'changed_by' => $change->changedBy?->name,
                    'changed_at' => $change->changed_at->toIso8601String(),
                ])->all(),
            ],
            'can' => [
                'settle' => Gate::allows('settle', $payable),
            ],
            'password_confirmed' => $this->passwordConfirmed($request),
        ]);
    }

    public function settle(Request $request, SupplierPayable $payable, SettleSupplierPayable $settle): RedirectResponse
    {
        Gate::authorize('settle', $payable);

        /** @var User $user */
        $user = $request->user();

        try {
            $settle->handle($payable, $user->id);
        } catch (SupplierPayableSettlementRefused $exception) {
            return back()->withErrors(['settlement' => $exception->getMessage()]);
        }

        return back()->with('success', __('supplier.admin.payables.settled'));
    }

    public function bulkSettle(Request $request, BulkSettleSupplierPayables $bulk): RedirectResponse
    {
        // Not `Gate::authorize('settle', SupplierPayable::class)` — the
        // policy method is typed to a real instance, and a class string in
        // its place would be a TypeError, not a refusal. `settle()` never
        // actually reads the instance it is handed (the same shape
        // `viewAny()` already has), so the permission check it wraps is
        // asked for directly here instead.
        Gate::authorize('viewAny', SupplierPayable::class);

        /** @var User $user */
        $user = $request->user();

        abort_unless(
            $user->can(PermissionCatalogue::name(PermissionModule::SupplierPayable, PermissionAction::Approve)),
            403,
        );

        $validated = $request->validate([
            'payables' => ['required', 'array', 'min:1', 'max:100'],
            'payables.*' => ['required', 'string'],
        ]);

        $payables = SupplierPayable::query()->whereIn('public_id', $validated['payables'])->get();

        $results = $bulk->handle($payables, $user->id);

        return back()->with('bulk_result', [
            'succeeded' => count(array_filter($results, fn (array $result) => $result['ok'])),
            'refused' => array_values(array_map(
                fn (array $result) => ['reference' => $result['reference'], 'reason' => $result['message']],
                array_filter($results, fn (array $result) => ! $result['ok']),
            )),
        ]);
    }

    protected function passwordConfirmed(Request $request): bool
    {
        return time() - (int) $request->session()->get('auth.password_confirmed_at', 0) < (int) config('auth.password_timeout', 10800);
    }

    /**
     * @return array<string, mixed>
     */
    protected function row(SupplierPayable $payable): array
    {
        return [
            'id' => $payable->public_id,
            'reference' => $payable->reference,
            'supplier' => $payable->supplier->business_name,
            'order_reference' => $payable->order->reference,
            'quantity' => $payable->quantity,
            'supplier_rate' => $payable->supplier_rate_minor->jsonSerialize(),
            'gross_amount' => $payable->gross_amount_minor->jsonSerialize(),
            'net_amount' => $payable->netAmount()->jsonSerialize(),
            'status' => $payable->status->value,
            'status_label' => $payable->status->label(),
            'status_tone' => $payable->status->tone(),
            'delivered_at' => $payable->delivered_at?->toIso8601String(),
            'payment_settled_at' => $payable->payment_settled_at?->toIso8601String(),
            'eligible_at' => $payable->eligible_at?->toIso8601String(),
            'settled_at' => $payable->settled_at?->toIso8601String(),
            'settlement_reference' => $payable->settlement_reference,
            'created_at' => $payable->created_at->toIso8601String(),
        ];
    }
}
