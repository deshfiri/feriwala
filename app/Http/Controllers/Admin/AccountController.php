<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Account\Actions\ReactivateAccount;
use App\Domain\Account\Actions\SuspendAccount;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Account\Queries\AccountDirectory;
use App\Domain\Account\Queries\AccountDossier;
use App\Domain\Kyc\Actions\CaptureRoundRequirements;
use App\Domain\Kyc\Enums\KycConsequence;
use App\Domain\Kyc\Models\KycSubmission;
use App\Domain\Package\Models\Package;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\StateMachine\Exceptions\IllegalStateTransition;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * One trading business, in full (P1-79).
 *
 * Distinct from the activation queue, which is scoped to accounts *awaiting*
 * activation — a trading account never appears there, so this is the only place
 * the §7.2 "request a KYC update" action can live (FD-1).
 *
 * Read-only wherever another task owns the action. The screen exists to answer
 * questions about an account; the one thing it does is ask for fresh KYC.
 */
class AccountController extends Controller
{
    /** Columns the directory may sort by. A whitelist, not the parameter. */
    protected const SORTABLE = ['name', 'created_at', 'activated_at', 'status'];

    public function __construct(
        protected AccountDossier $dossier,
        protected CaptureRoundRequirements $requirements,
        protected AccountDirectory $directory,
    ) {}

    /**
     * Every Client/Partner business, as an operations list.
     *
     * The activation queue answers "who is waiting on us"; this answers "who
     * are our accounts", which is the question nobody could ask before —
     * a trading business was reachable only by knowing its URL.
     *
     * **A directory, not a dossier.** No KYC document, payout detail, secret
     * or wallet figure is selected here; those live behind their own abilities
     * on the detail screen.
     */
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', BusinessAccount::class);

        $sort = $request->string('sort')->toString();
        $direction = $request->string('direction')->toString() === 'asc' ? 'asc' : 'desc';

        $accounts = $this->directory
            ->builder($request->only([
                'search', 'state', 'status', 'kyc_status', 'kyc_reverification',
                'package', 'package_status', 'facility', 'wallet_restriction',
                'registered_from', 'registered_to', 'activated_from', 'activated_to',
            ]))
            ->reorder(
                in_array($sort, self::SORTABLE, true) ? $sort : 'created_at',
                $direction,
            )
            ->paginate(25)
            ->withQueryString()
            ->through(fn (BusinessAccount $account) => [
                'id' => $account->public_id,
                'name' => $account->name,
                'owner' => $account->owner?->name,
                'email' => $account->owner?->email,
                'mobile' => $account->owner?->mobile,
                'email_verified' => $account->owner?->email_verified_at !== null,
                'mobile_verified' => $account->owner?->mobile_verified_at !== null,
                'status_label' => $account->status->label(),
                'status_tone' => $account->status->tone(),
                'package' => $account->currentPackage?->package?->name,
                'package_status' => $account->currentPackage?->status->label(),
                'registered_at' => $account->created_at?->toIso8601String(),
                'activated_at' => $account->activated_at?->toIso8601String(),
            ]);

        return Inertia::render('admin/accounts/index', [
            'accounts' => $accounts,
            'summary' => $this->directory->summary(),
            'packages' => Package::query()
                ->orderBy('name')
                ->get(['public_id', 'name'])
                ->map(fn (Package $package) => [
                    'id' => $package->public_id,
                    'name' => $package->name,
                ]),
            'filters' => $request->only([
                'search', 'state', 'status', 'kyc_status', 'kyc_reverification',
                'package', 'package_status', 'facility', 'wallet_restriction',
                'registered_from', 'registered_to', 'activated_from', 'activated_to',
                'sort', 'direction',
            ]),
        ]);
    }

    public function show(BusinessAccount $account): Response
    {
        // `viewDossier`, not `view`: this screen carries internal reasons and
        // reviewer notes, and `view` admits the account's own members (§7.2).
        Gate::authorize('viewDossier', $account);

        $subscription = $this->dossier->subscription($account);
        $blockers = $this->blockers($account);

        return Inertia::render('admin/accounts/show', [
            /*
             * `business`, not `account`. `HandleInertiaRequests` shares an
             * `account` prop describing the *viewer's* own account, and a page
             * prop of the same name overwrites it — which left the sidebar badge
             * naming the business being looked at and the "My business" group
             * appearing for platform staff who have no account at all.
             */
            'business' => $this->dossier->identity($account),
            'status_history' => $this->dossier->statusHistory($account),
            'kyc_rounds' => $this->dossier->kycRounds($account),
            'subscription' => $subscription['current'],
            'subscription_history' => $subscription['history'],
            'payments' => $this->dossier->payments($account),
            'staff' => $this->dossier->staff($account),

            /*
             * Who can sign in, and whether they still can (§6, P1-17). A
             * different question from `staff`, which is about what somebody may
             * do inside the business — a locked person keeps their role and
             * loses the platform.
             */
            'people' => $this->dossier->people($account),

            /*
             * The plans an administrator could grant this account (§8.3, P1-40).
             * Only what is on sale — assigning an archived plan would hand out
             * terms nobody can renew onto.
             */
            'assignable_packages' => Package::query()
                ->available()
                ->orderBy('fee')
                ->get()
                // Asked of each package, because the policy refuses an archived
                // one on its own terms — a single class-level answer would
                // offer plans the action then declines.
                ->filter(fn (Package $package) => Gate::allows('assign', $package))
                ->map(fn (Package $package) => [
                    'slug' => $package->slug,
                    'name' => $package->name,
                ])
                ->values()
                ->all(),

            /*
             * The documents this account is actually subject to, so the request
             * dialog offers a real list rather than a free-text box. Same source
             * the round captures from, or the two would disagree the first time
             * a scope rule changed.
             */
            'document_types' => $this->requirements
                ->applicableFor(
                    $this->requirements->packageIdForAccount($account),
                    $account->owner?->country,
                )
                ->map(fn ($type) => [
                    'id' => $type->public_id,
                    'name' => $type->name,
                ])
                ->all(),

            /*
             * What staff may attach to a re-verification (§7.4). Sent from the
             * enum rather than hard-coded in React, so a consequence added or
             * withdrawn here cannot leave the dialog offering one the server
             * refuses — and the labels arrive already translated.
             *
             * "Warning only" is offered as the explicit default. It stores as
             * nothing, which is exactly what it means.
             */
            'kyc_consequences' => array_map(
                fn (KycConsequence $consequence) => [
                    'value' => $consequence->value,
                    'label' => $consequence->label(),
                    'applies_before_deadline' => $consequence->appliesBeforeDeadline(),
                ],
                KycConsequence::cases(),
            ),

            'can' => [
                /*
                 * Permission **and** the invariant together. Super Admin passes
                 * every policy through `Gate::before`, so a permission check
                 * alone would offer the button on an account the action will
                 * refuse — the round already in progress is the same guard the
                 * action applies, surfaced before the click rather than after.
                 */
                'request_kyc_update' => $blockers === []
                    && Gate::allows('requestUpdate', [KycSubmission::class, $account]),

                /*
                 * Withdrawing a round (§7.2). The permission only — *which*
                 * round may be withdrawn rides on each row's
                 * `is_withdrawable`, because that is a fact about the round
                 * rather than about the person looking at it.
                 */
                'withdraw_kyc_request' => Gate::allows('requestUpdate', [KycSubmission::class, $account]),

                /*
                 * Suspension was previously reachable only from the activation
                 * queue, which holds no trading account — so a business that
                 * had already activated could not be suspended from anywhere.
                 * The invariant rides with the permission here too: the two
                 * are mutually exclusive by status, and offering both would
                 * be offering one that will certainly be refused.
                 */
                'suspend' => ! $account->isActivated()
                    ? false
                    : Gate::allows('suspendTrading', $account),
                'reactivate' => $account->status === AccountStatus::Suspended
                    && Gate::allows('reactivate', $account),
            ],

            'blockers' => $blockers,
        ]);
    }

    /**
     * Stop a trading business (§5.3).
     *
     * Its own endpoint on this screen rather than a reuse of the activation
     * queue's: that one is scoped to accounts awaiting activation, and a
     * trading account never appears in it.
     */
    public function suspend(Request $request, BusinessAccount $account, SuspendAccount $action): RedirectResponse
    {
        // `suspendTrading`, not `suspend`: the latter is the activation
        // queue's decision about an applicant and rides on `account.reject`.
        // Stopping a live business is `account.suspend`.
        Gate::authorize('suspendTrading', $account);

        /** @var User $staff */
        $staff = $request->user();

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
            'feedback' => ['nullable', 'string', 'max:1000'],
            'internal_note' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $action->handle(
                account: $account,
                decidedBy: $staff->id,
                reason: $validated['reason'],
                userVisibleNote: $validated['feedback'] ?? null,
                internalNote: $validated['internal_note'] ?? null,
            );
        } catch (InvalidArgumentException|IllegalStateTransition $refused) {
            throw ValidationException::withMessages(['reason' => $refused->getMessage()]);
        }

        return back()->with('success', __('Account suspended.'));
    }

    /**
     * Lift a suspension (§5.3).
     *
     * The same authority as imposing one — a reversible decision nobody can
     * reverse is closure under a kinder name.
     */
    public function reactivate(Request $request, BusinessAccount $account, ReactivateAccount $action): RedirectResponse
    {
        Gate::authorize('reactivate', $account);

        /** @var User $staff */
        $staff = $request->user();

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
            'feedback' => ['nullable', 'string', 'max:1000'],
            'internal_note' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $action->handle(
                account: $account,
                decidedBy: $staff->id,
                reason: $validated['reason'],
                userVisibleNote: $validated['feedback'] ?? null,
                internalNote: $validated['internal_note'] ?? null,
            );
        } catch (InvalidArgumentException|IllegalStateTransition $refused) {
            throw ValidationException::withMessages(['reason' => $refused->getMessage()]);
        }

        return back()->with('success', __('Account reactivated.'));
    }

    /**
     * Why the request action is unavailable, in the reader's own words.
     *
     * Named rather than implied: a disabled button that will not say why sends
     * an administrator to support to find out.
     *
     * @return array<int, string>
     */
    protected function blockers(BusinessAccount $account): array
    {
        $latest = KycSubmission::query()
            ->where('business_account_id', $account->id)
            ->orderByDesc('round')
            ->first();

        if ($latest === null) {
            return [__('kyc.request.never_submitted')];
        }

        if ($latest->status->isEditable() || $latest->status->awaitsReview()) {
            return [__('kyc.request.already_in_progress')];
        }

        return [];
    }
}
