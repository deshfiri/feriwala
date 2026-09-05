<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Account\Actions\ActivateAccount;
use App\Domain\Account\Actions\RequestKycResubmission;
use App\Domain\Account\Actions\SuspendAccount;
use App\Domain\Account\ActivationRequirements;
use App\Domain\Account\Exceptions\ActivationBlocked;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Account\Queries\PendingActivationQuery;
use App\Domain\Billing\Enums\PaymentPurpose;
use App\Domain\Billing\Models\Payment;
use App\Domain\Kyc\Enums\KycStatus;
use App\Domain\Kyc\Models\KycSubmission;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The activation approval queue (§5.1, §44).
 *
 * The last gate before someone can trade. Everything upstream — verification,
 * KYC, payment — is a precondition this screen displays but does not re-decide;
 * what it decides is whether a human is willing to let this account onto the
 * platform.
 */
class ActivationReviewController extends Controller
{
    /** Columns the table may sort by. A whitelist, not the parameter itself. */
    protected const SORTABLE = ['approval_pending_at', 'created_at', 'name'];

    public function __construct(
        protected ActivationRequirements $requirements,
    ) {}

    public function index(Request $request, PendingActivationQuery $pending): Response
    {
        Gate::authorize('viewAny', BusinessAccount::class);

        $accounts = $pending->builder()
            // The owner comes along for the row: the queue shows a business, but
            // a reviewer identifies it by the person behind it.
            ->with('owner:id,name,email,mobile,country')
            ->when($request->string('search')->toString(), fn ($query, string $search) => $query
                ->where(fn ($q) => $q
                    ->where('business_accounts.name', 'ilike', "%{$search}%")
                    ->orWhereHas('owner', fn ($owner) => $owner
                        ->where('name', 'ilike', "%{$search}%")
                        ->orWhere('email', 'ilike', "%{$search}%")
                        ->orWhere('mobile', 'ilike', "%{$search}%"))))
            ->when(
                in_array($request->string('sort')->toString(), self::SORTABLE, true),
                fn ($query) => $query->reorder(
                    $request->string('sort')->toString(),
                    $request->string('direction')->toString() === 'desc' ? 'desc' : 'asc',
                ),
            )
            ->paginate(25)
            ->withQueryString()
            ->through(fn (BusinessAccount $account) => [
                'id' => $account->public_id,
                'name' => $account->name,
                'owner' => $account->owner?->name,
                'email' => $account->owner?->email,
                'mobile' => $account->owner?->mobile,
                'country' => $account->owner?->country,
                'status_label' => $account->status->label(),
                'status_tone' => $account->status->tone(),
                'ready_since' => $account->approval_pending_at?->toIso8601String(),
                'waiting_days' => $this->waitingDays($account),
            ]);

        return Inertia::render('admin/activations/index', [
            'accounts' => $accounts,
        ]);
    }

    public function show(Request $request, BusinessAccount $account): Response
    {
        Gate::authorize('view', $account);

        /** @var User $reviewer */
        $reviewer = $request->user();

        $unmet = $this->requirements->unmet($account);

        return Inertia::render('admin/activations/show', [
            'account' => [
                'id' => $account->public_id,
                'name' => $account->name,
                'owner' => $account->owner?->name,
                'email' => $account->owner?->email,
                'mobile' => $account->owner?->mobile,
                'country' => $account->owner?->country,
                'registered_at' => $account->created_at?->toIso8601String(),
                'status' => $account->status->value,
                'status_label' => $account->status->label(),
                'status_tone' => $account->status->tone(),
                // The queue is a view; this is the decision. Both ask the same
                // requirements object, and the action asks it a third time.
                'is_ready' => $unmet === [],
                'unmet' => $unmet,

                // Three separate abilities, not one. Suspension is a different
                // permission from approval (§5.3, D18), and the interface has to
                // show that rather than offering all three to whoever holds one.
                'can_approve' => $reviewer->can('approveActivation', $account),
                'can_request_resubmission' => $reviewer->can('requestKycResubmission', $account),
                'can_suspend' => $reviewer->can('suspend', $account),
            ],

            // The three §5.1 conditions, each with the evidence behind it. A
            // reviewer approving an account should see what they are relying on,
            // not a green tick they have to take on trust.
            'conditions' => $this->conditions($account),

            'history' => $account->statusHistory()->with('changedBy:id,name')->get()
                ->map(fn ($change) => [
                    'to_status' => $change->to_status->label(),
                    'changed_by' => $change->changedBy?->name,
                    'reason' => $change->reason,
                    'internal_note' => $change->internal_note,
                    'user_visible_note' => $change->user_visible_note,
                    'created_at' => $change->created_at->toIso8601String(),
                ])->all(),
        ]);
    }

    public function approve(
        Request $request,
        BusinessAccount $account,
        ActivateAccount $activate,
    ): RedirectResponse {
        Gate::authorize('approveActivation', $account);

        /** @var User $reviewer */
        $reviewer = $request->user();

        $validated = $request->validate([
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $activate->handle($account, $reviewer->id, $validated['note'] ?? null);
        } catch (ActivationBlocked $blocked) {
            // Between the queue rendering and this request, a condition can have
            // come undone — a refunded payment, a withdrawn KYC approval. The
            // reviewer gets the reasons rather than a generic failure.
            throw ValidationException::withMessages([
                'activation' => $blocked->reasons ?: [$blocked->getMessage()],
            ]);
        }

        return to_route('admin.activations.index')->with('success', 'Account activated.');
    }

    /**
     * Send the applicant back to fix their evidence.
     *
     * Its own endpoint rather than a branch of a generic "decline", because it
     * carries different requirements and a different permission from suspension
     * — and because a route named `decline` invites a fourth outcome nobody
     * designed.
     */
    public function requestResubmission(
        Request $request,
        BusinessAccount $account,
        RequestKycResubmission $action,
    ): RedirectResponse {
        Gate::authorize('requestKycResubmission', $account);

        /** @var User $reviewer */
        $reviewer = $request->user();

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
            // Required here, not merely checked in the action: the applicant has
            // to be told what to fix or they resubmit the same thing.
            'feedback' => ['required', 'string', 'max:1000'],
            'internal_note' => ['nullable', 'string', 'max:2000'],
        ]);

        $action->handle(
            account: $account,
            decidedBy: $reviewer->id,
            reason: $validated['reason'],
            feedback: $validated['feedback'],
            internalNote: $validated['internal_note'] ?? null,
        );

        return to_route('admin.activations.index')
            ->with('success', 'Correction requested.');
    }

    public function suspend(
        Request $request,
        BusinessAccount $account,
        SuspendAccount $suspend,
    ): RedirectResponse {
        // A separate permission from approval (§5.3, D18). Suspension removes
        // the ability to trade and must not be reachable by everyone who can
        // approve.
        Gate::authorize('suspend', $account);

        /** @var User $reviewer */
        $reviewer = $request->user();

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
            'feedback' => ['nullable', 'string', 'max:1000'],
            'internal_note' => ['nullable', 'string', 'max:2000'],
        ]);

        $suspend->handle(
            account: $account,
            decidedBy: $reviewer->id,
            reason: $validated['reason'],
            userVisibleNote: $validated['feedback'] ?? null,
            internalNote: $validated['internal_note'] ?? null,
        );

        return to_route('admin.activations.index')
            ->with('success', 'Account suspended.');
    }

    /**
     * Whole days this account has been waiting on us, or null if it is not yet
     * waiting on anyone but itself.
     */
    protected function waitingDays(BusinessAccount $account): ?int
    {
        $readySince = $account->approval_pending_at;

        if ($readySince === null) {
            return null;
        }

        return (int) $readySince->startOfDay()->diffInDays(now()->startOfDay());
    }

    /**
     * @return array<int, array{key: string, label: string, met: bool, detail: string|null}>
     */
    protected function conditions(BusinessAccount $account): array
    {
        $kyc = KycSubmission::query()
            ->where('business_account_id', $account->id)
            ->where('status', KycStatus::Approved)
            ->latest('reviewed_at')
            ->first();

        $payment = Payment::query()
            ->where('business_account_id', $account->id)
            ->where('purpose', PaymentPurpose::Activation)
            ->settled()
            ->latest('completed_at')
            ->first();

        return [
            [
                'key' => 'verified',
                'label' => 'Email and mobile verified',
                // Verification is a fact about the owner, not the business.
                'met' => $this->requirements->ownerVerified($account),
                'detail' => $this->requirements->ownerVerified($account)
                    ? null
                    : ($account->owner?->email_verified_at === null ? 'Email not verified' : 'Mobile not verified'),
            ],
            [
                'key' => 'kyc',
                'label' => 'KYC approved',
                'met' => $kyc !== null,
                'detail' => $kyc?->reviewed_at?->toIso8601String(),
            ],
            [
                'key' => 'payment',
                'label' => 'Activation payment verified',
                'met' => $payment !== null,
                'detail' => $payment?->reference,
            ],
        ];
    }
}
