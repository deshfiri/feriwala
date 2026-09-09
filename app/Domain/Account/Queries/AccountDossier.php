<?php

namespace App\Domain\Account\Queries;

use App\Domain\Account\Enums\UserStatus;
use App\Domain\Account\Models\AccountMembership;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Account\Models\BusinessAccountStatusChange;
use App\Domain\Billing\Models\Payment;
use App\Domain\Kyc\Models\KycSubmission;
use App\Domain\Kyc\Models\KycSubmissionRequirement;
use App\Domain\Package\Queries\AccountSubscription;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Everything an administrator needs about one trading business (P1-79).
 *
 * Assembled here rather than in the controller because the same account is
 * described from several tables — identity, status history, KYC rounds,
 * subscription, payments, staff — and a controller that gathers them itself
 * ends up being the place the next screen copies from.
 *
 * **Reviewer-only material stays out of the applicant's view, not out of this
 * one.** This is the administrator's screen: internal reasons and staff notes
 * belong here, and it is `KycSubmission`'s applicant-facing payload that must
 * keep omitting them (§7.2).
 */
class AccountDossier
{
    public function __construct(
        protected AccountSubscription $subscriptions,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function identity(BusinessAccount $account): array
    {
        $owner = $account->owner;

        return [
            'id' => $account->public_id,
            'name' => $account->name,
            'status' => $account->status->value,
            'status_label' => $account->status->label(),
            'status_tone' => $account->status->tone(),
            'registered_at' => $account->created_at?->toIso8601String(),
            'activated_at' => $account->activated_at?->toIso8601String(),

            'owner' => $owner === null ? null : [
                'name' => $owner->name,
                'email' => $owner->email,
                'mobile' => $owner->mobile,
                'country' => $owner->country,
                'identity_status' => $owner->identity_status->value,
                'identity_status_label' => $owner->identity_status->label(),
                'identity_status_tone' => $owner->identity_status->tone(),
            ],
        ];
    }

    /**
     * Commercial status history, newest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function statusHistory(BusinessAccount $account): array
    {
        return $account->statusHistory()
            ->with('changedBy:id,name')
            ->latest('id')
            ->limit(30)
            ->get()
            ->map(fn (BusinessAccountStatusChange $entry) => [
                'id' => $entry->id,
                'to_status' => $entry->to_status->label(),
                'from_status' => $entry->from_status?->label(),
                // An automatic change has no person behind it, and saying so is
                // clearer than an em dash.
                'changed_by' => $entry->changedBy?->name,
                'reason' => $entry->reason,
                'created_at' => $entry->created_at->toIso8601String(),
            ])
            ->all();
    }

    /**
     * Every KYC round this account has had, newest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function kycRounds(BusinessAccount $account): array
    {
        return KycSubmission::query()
            ->where('business_account_id', $account->id)
            ->with(['requirements', 'requestedBy:id,name'])
            ->withCount('documents')
            ->orderByDesc('round')
            ->get()
            ->map(fn (KycSubmission $round) => [
                'id' => $round->public_id,
                'round' => $round->round,
                'status' => $round->status->value,
                'status_label' => $round->status->label(),
                'status_tone' => $round->status->tone(),
                'submitted_at' => $round->submitted_at?->toIso8601String(),
                'reviewed_at' => $round->reviewed_at?->toIso8601String(),
                'deadline_at' => $round->deadline_at?->toIso8601String(),
                'documents_count' => $round->documents_count,
                'requirements' => $round->requirements
                    ->map(fn (KycSubmissionRequirement $requirement) => [
                        'name' => $requirement->name,
                        'is_required' => $requirement->is_required,
                    ])
                    ->all(),

                /*
                 * The request half — present only on rounds an administrator
                 * opened, which is what makes this list double as the §7.2
                 * request history rather than needing a second one.
                 */
                'requested_at' => $round->requested_at?->toIso8601String(),
                'requested_by' => $round->requestedBy?->name,
                'request_reason' => $round->request_reason,
                'request_instructions' => $round->request_instructions,
            ])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function subscription(BusinessAccount $account): array
    {
        return [
            'current' => $this->subscriptions->current($account),
            'history' => $this->subscriptions->history($account),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function payments(BusinessAccount $account): array
    {
        return $account->payments()
            ->latest('id')
            ->limit(20)
            ->get()
            ->map(fn (Payment $payment) => [
                'id' => $payment->public_id,
                'reference' => $payment->reference,
                'status' => $payment->status->value,
                'status_label' => $payment->status->label(),
                'status_tone' => $payment->status->tone(),
                'purpose' => $payment->purpose->label(),
                // Formatted server-side; the client never divides by a hundred.
                'amount' => $payment->amount_minor->jsonSerialize(),
                'initiated_at' => $payment->initiated_at?->toIso8601String(),
                'completed_at' => $payment->completed_at?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function staff(BusinessAccount $account): array
    {
        return $account->memberships()
            ->with('user:id,name,email')
            ->get()
            ->map(fn (AccountMembership $membership) => [
                'id' => $membership->id,
                'name' => $membership->user->name,
                'email' => $membership->user->email,
                'role' => $membership->role->value,
                'role_label' => $membership->role->label(),
            ])
            ->all();
    }

    /**
     * The people who can sign in to this account, and whether they still can.
     *
     * A different question from {@see staff()}, which answers what somebody is
     * allowed to do *inside* the business. This one is about the login itself
     * (§6): a locked person keeps their role and loses the platform.
     *
     * Owner first, because they are who an administrator is usually looking for,
     * and because their membership row is one of the ones being listed —
     * ordering by anything else would bury them among their own staff.
     *
     * @return array<int, array<string, mixed>>
     */
    public function people(BusinessAccount $account): array
    {
        $owner = $account->owner;

        $memberships = $account->memberships()
            ->with('user')
            ->get()
            ->filter(fn (AccountMembership $membership) => $membership->user->id !== $owner?->id);

        $people = [];

        if ($owner !== null) {
            $people[] = $this->person($owner, __('security.lock.owner'));
        }

        foreach ($memberships as $membership) {
            $people[] = $this->person($membership->user, $membership->role->label());
        }

        return $people;
    }

    /**
     * @return array<string, mixed>
     */
    protected function person(User $user, string $role): array
    {
        return [
            'id' => $user->public_id,
            'name' => $user->name,
            'email' => $user->email,
            'role_label' => $role,
            'identity_status' => $user->identity_status->value,
            'identity_status_label' => $user->identity_status->label(),
            'identity_status_tone' => $user->identity_status->tone(),
            'is_locked' => $user->identity_status === UserStatus::Locked,
            'changed_at' => $user->identity_status_changed_at?->toIso8601String(),

            /*
             * Asked per person, not once for the screen. The policy refuses a
             * Super Admin and refuses the actor themselves, so a single "may I
             * lock" answer would offer a control that the action then declines
             * — which is a worse experience than not offering it.
             */
            'can_change' => Gate::allows('lock', $user),
        ];
    }
}
