<?php

namespace App\Domain\Kyc;

use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Kyc\Enums\KycConsequence;
use App\Domain\Kyc\Enums\KycRoundPurpose;
use App\Domain\Kyc\Enums\KycStatus;
use App\Domain\Kyc\Models\KycSubmission;

/**
 * What an outstanding re-verification stops a business doing (§7.2, §7.4).
 *
 * The one place that answers it, asked from inside the server-side Actions
 * that would otherwise let the activity through. A restriction enforced only
 * by a hidden React button is not a restriction: the route is still there, the
 * API is still there, and the person most likely to find them is the one the
 * restriction is aimed at.
 *
 * **Only new activity is ever blocked.** Nothing here cancels an order,
 * releases a reservation, unpublishes a website, reverses a ledger entry or
 * touches an invoice — and nothing here can block a payment settling, a refund,
 * a reconciliation or a financial reversal, because those are how existing
 * obligations get closed safely. A business asked to re-verify has not been
 * found guilty of anything; it has been asked a question.
 *
 * At most one open re-verification exists per account — a partial unique index
 * says so — which is why this can read "the" case rather than reconcile several
 * that disagree.
 */
class KycRestrictions
{
    /**
     * Cheap per-request memoisation: several Actions ask within one request.
     *
     * @var array<int, KycSubmission|null>|null
     */
    protected ?array $cache = null;

    public function blocksNewOrders(BusinessAccount $account): bool
    {
        return $this->imposes($account, KycConsequence::BlockNewOrders);
    }

    public function blocksPublishing(BusinessAccount $account): bool
    {
        return $this->imposes($account, KycConsequence::BlockPublishing);
    }

    public function blocksWithdrawals(BusinessAccount $account): bool
    {
        return $this->imposes($account, KycConsequence::BlockWithdrawals);
    }

    /**
     * Whether this account's open re-verification imposes `$consequence` now.
     */
    public function imposes(BusinessAccount $account, KycConsequence $consequence): bool
    {
        return $this->openCase($account)?->imposes($consequence) ?? false;
    }

    /**
     * The live re-verification requirement, if there is one.
     *
     * "Open" means exactly what the partial unique index means: a
     * re-verification round that has not been approved, rejected or withdrawn.
     */
    public function openCase(BusinessAccount $account): ?KycSubmission
    {
        if ($this->cache !== null && array_key_exists($account->id, $this->cache)) {
            return $this->cache[$account->id];
        }

        $case = KycSubmission::query()
            ->where('business_account_id', $account->id)
            ->where('purpose', KycRoundPurpose::Reverification)
            ->whereNull('cancelled_at')
            ->whereNotIn('status', [KycStatus::Approved->value, KycStatus::Rejected->value])
            ->orderByDesc('round')
            ->first();

        $this->cache ??= [];
        $this->cache[$account->id] = $case;

        return $case;
    }

    /**
     * The reason a piece of activity was refused, in words the account holder
     * can act on.
     *
     * Never the internal reason the round was opened for (§7.3) — only that
     * verification is outstanding and by when. An account told "blocked:
     * suspected forgery" learns something the reviewer wrote privately.
     */
    public function refusalReason(BusinessAccount $account): string
    {
        $case = $this->openCase($account);
        $deadline = $case?->deadline_at;

        if ($deadline !== null && ! $deadline->isPast()) {
            return __('kyc.restriction.outstanding_by', [
                'date' => $deadline->toFormattedDayDateString(),
            ]);
        }

        return __('kyc.restriction.outstanding');
    }

    /**
     * Forget what was read, for a long-running process whose account state
     * moved underneath it — a queue worker, or a test.
     */
    public function forget(): void
    {
        $this->cache = null;
    }
}
