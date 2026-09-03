<?php

namespace App\Domain\Account\Queries;

use App\Domain\Account\Actions\ActivateAccount;
use App\Domain\Account\Actions\ChangeAccountStatus;
use App\Domain\Account\Actions\EvaluateActivationReadiness;
use App\Domain\Account\ActivationRequirements;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Billing\Enums\PaymentPurpose;
use App\Domain\Billing\Enums\PaymentStatus;
use App\Domain\Kyc\Enums\KycStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Accounts waiting for an activation decision (§5.1, §44).
 *
 * {@see AccountStatus::ApprovalPending} is the canonical state, maintained by
 * {@see EvaluateActivationReadiness} whenever a requirement moves. This query
 * reads it rather than re-deriving eligibility on every page load.
 *
 * The condition checks below are a **compatibility net**, not the definition.
 * They catch an account that met every requirement before the orchestration
 * existed, or one whose readiness event was lost — without them such an account
 * would sit unreachable, having done everything asked of it. They are expected
 * to match nothing once the orchestration has been running, and
 * {@see ActivationRequirements} remains the authority in both branches:
 * the controller re-checks per row, and {@see ActivateAccount}
 * re-checks again under a row lock, which is what actually decides.
 *
 * Ordering is by `approval_pending_at` — when the account started waiting on us.
 * Ordering by registration would put someone who signed up in March above
 * someone who completed everything last week, which inverts the queue exactly
 * where it matters.
 */
class PendingActivationQuery
{
    /**
     * Statuses that are never in this queue, whatever else is true.
     *
     * Suspended and closed accounts are not awaiting a decision, and every
     * activated status is excluded by `activated_at` rather than by listing the
     * twelve of them — one column that {@see ChangeAccountStatus}
     * sets in one place cannot fall out of step the way a list would.
     */
    public const INELIGIBLE = [
        AccountStatus::Suspended,
        AccountStatus::Closed,
        AccountStatus::TemporarilyDisabled,
    ];

    /**
     * @return Builder<User>
     */
    public function builder(): Builder
    {
        return User::query()
            ->select('users.*')

            ->whereNull('activated_at')
            ->whereNotIn('status', self::INELIGIBLE)

            ->where(fn (Builder $query) => $query
                // Canonical: at the gate *and* stamped. The stamp is what
                // EvaluateActivationReadiness clears when a requirement is
                // reversed — without it in the condition, an account whose
                // payment was refunded would keep its place in the queue purely
                // because nothing had moved its status yet.
                ->where(fn (Builder $canonical) => $canonical
                    ->where('status', AccountStatus::ApprovalPending)
                    ->whereNotNull('approval_pending_at'))
                ->orWhere(fn (Builder $fallback) => $this->meetsEveryRequirement($fallback)))

            // Nulls last, so an account recovered by the compatibility net sorts
            // after the ones with a real readiness time rather than ahead of
            // them. PostgreSQL puts nulls first on an ascending sort otherwise.
            ->orderByRaw('approval_pending_at asc nulls last')

            // A stable tie-break. Without it two accounts stamped in the same
            // transaction can swap places between pages, and an account can be
            // shown twice or skipped entirely while a reviewer pages through.
            ->orderBy('users.id');
    }

    /**
     * The §5.1 conditions in SQL.
     *
     * `whereExists` rather than a join throughout: a join against payments would
     * return one row per settled payment, so an account that paid twice would
     * appear twice in the queue.
     *
     * @param  Builder<User>  $query
     */
    protected function meetsEveryRequirement(Builder $query): void
    {
        $query
            ->whereNotNull('email_verified_at')
            ->whereNotNull('mobile_verified_at')
            ->whereExists(fn ($kyc) => $kyc
                ->selectRaw('1')
                ->from('kyc_submissions')
                ->whereColumn('kyc_submissions.user_id', 'users.id')
                ->where('status', KycStatus::Approved->value))
            ->whereExists(fn ($payment) => $payment
                ->selectRaw('1')
                ->from('payments')
                ->whereColumn('payments.user_id', 'users.id')
                ->where('purpose', PaymentPurpose::Activation->value)
                // The same statuses as Payment::scopeSettled(), which is not
                // reachable from a plain query builder inside whereExists.
                ->whereIn('status', [
                    PaymentStatus::Paid->value,
                    PaymentStatus::PartiallyRefunded->value,
                ]));
    }
}
