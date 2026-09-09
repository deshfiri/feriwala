<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Account\Queries\AccountDossier;
use App\Domain\Kyc\Actions\CaptureRoundRequirements;
use App\Domain\Kyc\Models\KycSubmission;
use App\Domain\Package\Models\Package;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

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
    public function __construct(
        protected AccountDossier $dossier,
        protected CaptureRoundRequirements $requirements,
    ) {}

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
                ->orderBy('fee_minor')
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
            ],

            'blockers' => $blockers,
        ]);
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
            return [__('This account has never submitted verification, so there is nothing to update.')];
        }

        if ($latest->status->isEditable() || $latest->status->awaitsReview()) {
            return [__('A verification round is already in progress.')];
        }

        return [];
    }
}
