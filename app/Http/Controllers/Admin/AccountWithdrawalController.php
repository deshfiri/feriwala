<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Withdrawal\Actions\AdvanceAccountWithdrawalStatus;
use App\Domain\Withdrawal\Actions\PayAccountWithdrawal;
use App\Domain\Withdrawal\Actions\RejectOrFailAccountWithdrawal;
use App\Domain\Withdrawal\Enums\AccountWithdrawalStatus;
use App\Domain\Withdrawal\Exceptions\AccountWithdrawalRefused;
use App\Domain\Withdrawal\Models\AccountWithdrawal;
use App\Domain\Withdrawal\Policies\AccountWithdrawalPolicy;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The staff Client/Partner withdrawal queue and its decisions (§27, mirrors
 * {@see SupplierWithdrawalController}).
 *
 * Reuses the existing `Module::Withdrawal` permissions rather than
 * account-specific ones ({@see AccountWithdrawalPolicy}). `markPaid()` sits
 * behind `RequirePassword` on the route — reaching it is the confirmation,
 * the same convention every other money-moving admin action in this
 * application follows.
 *
 * The linked business is returned as `account_name`/`account_id` fields on
 * the row rather than a top-level `account` prop: `HandleInertiaRequests`
 * already shares `account` for the viewer's own business, and a page prop of
 * the same name would silently overwrite it (`.ai/rules/account-access.md`).
 */
class AccountWithdrawalController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', AccountWithdrawal::class);

        $withdrawals = AccountWithdrawal::query()
            ->with('businessAccount:id,public_id,name')
            ->when($request->filled('account'), fn ($q) => $q->whereHas('businessAccount', fn ($a) => $a->where('public_id', $request->string('account')->toString())))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')->toString()))
            ->when($request->filled('currency'), fn ($q) => $q->where('currency_code', $request->string('currency')->toString()))
            ->when($request->filled('date_from'), fn ($q) => $q->where('requested_at', '>=', $request->date('date_from')))
            ->when($request->filled('date_to'), fn ($q) => $q->where('requested_at', '<=', $request->date('date_to')))
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (AccountWithdrawal $withdrawal) => $this->row($withdrawal));

        return Inertia::render('admin/account-withdrawals/index', [
            'withdrawals' => $withdrawals,
            'statuses' => array_map(
                fn (AccountWithdrawalStatus $case) => ['value' => $case->value, 'label' => $case->label()],
                AccountWithdrawalStatus::cases(),
            ),
        ]);
    }

    public function show(Request $request, AccountWithdrawal $withdrawal): Response
    {
        Gate::authorize('view', $withdrawal);

        $withdrawal->load(['businessAccount:id,public_id,name', 'statusHistory.changedBy']);

        return Inertia::render('admin/account-withdrawals/show', [
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

    public function approve(Request $request, AccountWithdrawal $withdrawal, AdvanceAccountWithdrawalStatus $advance): RedirectResponse
    {
        Gate::authorize('decide', $withdrawal);

        /** @var User $user */
        $user = $request->user();

        try {
            // Requested cannot skip straight to Approved (Under review comes
            // first); Approve is offered as one staff action regardless of
            // which of the two legal starting points the request is at.
            if ($withdrawal->status === AccountWithdrawalStatus::Requested) {
                $withdrawal = $advance->handle($withdrawal, AccountWithdrawalStatus::UnderReview, $user->id);
            }

            $advance->handle($withdrawal, AccountWithdrawalStatus::Approved, $user->id);
        } catch (AccountWithdrawalRefused $exception) {
            return back()->withErrors(['decision' => $exception->getMessage()]);
        }

        return back()->with('success', __('withdrawal.admin.approved'));
    }

    public function reject(Request $request, AccountWithdrawal $withdrawal, RejectOrFailAccountWithdrawal $reject): RedirectResponse
    {
        Gate::authorize('reject', $withdrawal);

        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);

        try {
            $reject->handle($withdrawal, AccountWithdrawalStatus::Rejected, $validated['reason'], $user->id);
        } catch (AccountWithdrawalRefused $exception) {
            return back()->withErrors(['decision' => $exception->getMessage()]);
        }

        return back()->with('success', __('withdrawal.admin.rejected'));
    }

    public function process(Request $request, AccountWithdrawal $withdrawal, AdvanceAccountWithdrawalStatus $advance): RedirectResponse
    {
        Gate::authorize('decide', $withdrawal);

        /** @var User $user */
        $user = $request->user();

        try {
            $advance->handle($withdrawal, AccountWithdrawalStatus::Processing, $user->id);
        } catch (AccountWithdrawalRefused $exception) {
            return back()->withErrors(['decision' => $exception->getMessage()]);
        }

        return back()->with('success', __('withdrawal.admin.processing'));
    }

    public function markPaid(Request $request, AccountWithdrawal $withdrawal, PayAccountWithdrawal $pay): RedirectResponse
    {
        Gate::authorize('releasePayment', $withdrawal);

        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate(['external_reference' => ['required', 'string', 'max:120']]);

        try {
            $pay->handle($withdrawal, $validated['external_reference'], $user->id);
        } catch (AccountWithdrawalRefused $exception) {
            return back()->withErrors(['decision' => $exception->getMessage()]);
        }

        return back()->with('success', __('withdrawal.admin.paid'));
    }

    public function markFailed(Request $request, AccountWithdrawal $withdrawal, RejectOrFailAccountWithdrawal $reject): RedirectResponse
    {
        Gate::authorize('reject', $withdrawal);

        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);

        try {
            $reject->handle($withdrawal, AccountWithdrawalStatus::Failed, $validated['reason'], $user->id);
        } catch (AccountWithdrawalRefused $exception) {
            return back()->withErrors(['decision' => $exception->getMessage()]);
        }

        return back()->with('success', __('withdrawal.admin.failed'));
    }

    /**
     * @return array<string, mixed>
     */
    protected function row(AccountWithdrawal $withdrawal): array
    {
        return [
            'id' => $withdrawal->public_id,
            'reference' => $withdrawal->reference,
            'account_name' => $withdrawal->businessAccount->name,
            'account_id' => $withdrawal->businessAccount->public_id,
            'amount' => $withdrawal->amount->jsonSerialize(),
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
