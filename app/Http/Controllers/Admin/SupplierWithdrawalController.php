<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Supplier\Actions\AdvanceSupplierWithdrawalStatus;
use App\Domain\Supplier\Actions\PaySupplierWithdrawal;
use App\Domain\Supplier\Actions\RejectOrFailSupplierWithdrawal;
use App\Domain\Supplier\Enums\SupplierWithdrawalStatus;
use App\Domain\Supplier\Exceptions\SupplierWithdrawalRefused;
use App\Domain\Supplier\Models\SupplierWithdrawal;
use App\Domain\Supplier\Policies\SupplierWithdrawalPolicy;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The staff Supplier withdrawal queue and its decisions (D25, P13-24).
 *
 * Reuses the existing `Module::Withdrawal` permissions rather than
 * Supplier-specific ones ({@see SupplierWithdrawalPolicy}):
 * `SupplierManager` may view, advance and reject; only `WithdrawalApprover`
 * may release a payment. `markPaid()` sits behind `RequirePassword` on the
 * route — reaching it is the confirmation, the same convention every other
 * money-moving admin action in this application follows.
 */
class SupplierWithdrawalController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', SupplierWithdrawal::class);

        $withdrawals = SupplierWithdrawal::query()
            ->with('supplier:id,public_id,business_name')
            ->when($request->filled('supplier'), fn ($q) => $q->whereHas('supplier', fn ($s) => $s->where('public_id', $request->string('supplier')->toString())))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')->toString()))
            ->when($request->filled('currency'), fn ($q) => $q->where('currency_code', $request->string('currency')->toString()))
            ->when($request->filled('date_from'), fn ($q) => $q->where('requested_at', '>=', $request->date('date_from')))
            ->when($request->filled('date_to'), fn ($q) => $q->where('requested_at', '<=', $request->date('date_to')))
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (SupplierWithdrawal $withdrawal) => $this->row($withdrawal));

        return Inertia::render('admin/supplier-withdrawals/index', [
            'withdrawals' => $withdrawals,
            'statuses' => array_map(
                fn (SupplierWithdrawalStatus $case) => ['value' => $case->value, 'label' => $case->label()],
                SupplierWithdrawalStatus::cases(),
            ),
        ]);
    }

    public function show(Request $request, SupplierWithdrawal $withdrawal): Response
    {
        Gate::authorize('view', $withdrawal);

        $withdrawal->load(['supplier:id,public_id,business_name,reference', 'statusHistory.changedBy']);

        $user = $request->user();

        return Inertia::render('admin/supplier-withdrawals/show', [
            'withdrawal' => [
                ...$this->row($withdrawal),
                'payout_snapshot' => $withdrawal->payout_snapshot,
                'failure_reason' => $withdrawal->failure_reason,
                'external_reference' => $withdrawal->external_reference,
                'decision_note' => $withdrawal->decision_note,
                'history' => $withdrawal->statusHistory->map(fn ($change) => [
                    'previous_status' => $change->previous_status?->label(),
                    'new_status' => $change->new_status->label(),
                    'reason' => $change->reason,
                    'changed_by' => $change->changedBy?->name,
                    'changed_at' => $change->changed_at->toIso8601String(),
                ])->all(),
            ],
            'can' => [
                'decide' => Gate::allows('decide', $withdrawal),
                'reject' => Gate::allows('reject', $withdrawal),
                'release_payment' => Gate::allows('releasePayment', $withdrawal),
            ],
            'password_confirmed' => time() - (int) $request->session()->get('auth.password_confirmed_at', 0) < (int) config('auth.password_timeout', 10800),
        ]);
    }

    public function approve(Request $request, SupplierWithdrawal $withdrawal, AdvanceSupplierWithdrawalStatus $advance): RedirectResponse
    {
        Gate::authorize('decide', $withdrawal);

        /** @var User $user */
        $user = $request->user();

        try {
            // Requested cannot skip straight to Approved (Under review comes
            // first); Approve is offered as one staff action regardless of
            // which of the two legal starting points the request is at.
            if ($withdrawal->status === SupplierWithdrawalStatus::Requested) {
                $withdrawal = $advance->handle($withdrawal, SupplierWithdrawalStatus::UnderReview, $user->id);
            }

            $advance->handle($withdrawal, SupplierWithdrawalStatus::Approved, $user->id);
        } catch (SupplierWithdrawalRefused $exception) {
            return back()->withErrors(['decision' => $exception->getMessage()]);
        }

        return back()->with('success', __('supplier.admin.withdrawals.approved'));
    }

    public function reject(Request $request, SupplierWithdrawal $withdrawal, RejectOrFailSupplierWithdrawal $reject): RedirectResponse
    {
        Gate::authorize('reject', $withdrawal);

        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);

        try {
            $reject->handle($withdrawal, SupplierWithdrawalStatus::Rejected, $validated['reason'], $user->id);
        } catch (SupplierWithdrawalRefused $exception) {
            return back()->withErrors(['decision' => $exception->getMessage()]);
        }

        return back()->with('success', __('supplier.admin.withdrawals.rejected'));
    }

    public function process(Request $request, SupplierWithdrawal $withdrawal, AdvanceSupplierWithdrawalStatus $advance): RedirectResponse
    {
        Gate::authorize('decide', $withdrawal);

        /** @var User $user */
        $user = $request->user();

        try {
            $advance->handle($withdrawal, SupplierWithdrawalStatus::Processing, $user->id);
        } catch (SupplierWithdrawalRefused $exception) {
            return back()->withErrors(['decision' => $exception->getMessage()]);
        }

        return back()->with('success', __('supplier.admin.withdrawals.processing'));
    }

    public function markPaid(Request $request, SupplierWithdrawal $withdrawal, PaySupplierWithdrawal $pay): RedirectResponse
    {
        Gate::authorize('releasePayment', $withdrawal);

        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate(['external_reference' => ['required', 'string', 'max:120']]);

        try {
            $pay->handle($withdrawal, $validated['external_reference'], $user->id);
        } catch (SupplierWithdrawalRefused $exception) {
            return back()->withErrors(['decision' => $exception->getMessage()]);
        }

        return back()->with('success', __('supplier.admin.withdrawals.paid'));
    }

    public function markFailed(Request $request, SupplierWithdrawal $withdrawal, RejectOrFailSupplierWithdrawal $reject): RedirectResponse
    {
        Gate::authorize('reject', $withdrawal);

        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);

        try {
            $reject->handle($withdrawal, SupplierWithdrawalStatus::Failed, $validated['reason'], $user->id);
        } catch (SupplierWithdrawalRefused $exception) {
            return back()->withErrors(['decision' => $exception->getMessage()]);
        }

        return back()->with('success', __('supplier.admin.withdrawals.failed'));
    }

    /**
     * @return array<string, mixed>
     */
    protected function row(SupplierWithdrawal $withdrawal): array
    {
        return [
            'id' => $withdrawal->public_id,
            'reference' => $withdrawal->reference,
            'supplier' => $withdrawal->supplier->business_name,
            'supplier_id' => $withdrawal->supplier->public_id,
            'amount' => $withdrawal->amount_minor->jsonSerialize(),
            'currency' => $withdrawal->currency_code,
            'status' => $withdrawal->status->value,
            'status_label' => $withdrawal->status->label(),
            'status_tone' => $withdrawal->status->tone(),
            'requested_at' => $withdrawal->requested_at->toIso8601String(),
            'decided_at' => $withdrawal->decided_at?->toIso8601String(),
            'paid_at' => $withdrawal->paid_at?->toIso8601String(),
        ];
    }
}
