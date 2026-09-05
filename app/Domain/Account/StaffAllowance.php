<?php

namespace App\Domain\Account;

use App\Domain\Account\Enums\AccountRole;
use App\Domain\Account\Models\AccountInvitation;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Package\Entitlements;
use App\Domain\Package\Enums\PackageFeature;

/**
 * How many staff a package allows, and how many are already spoken for (§8.1).
 *
 * Two rules make this more than a row count.
 *
 * **The owner does not consume a seat.** They pay for the package; charging
 * them one would make a limit of one an account that can hold nobody but its
 * owner, which is not what "one staff member" means to the person buying it.
 *
 * **A live invitation occupies a seat.** Counting only accepted memberships
 * would let a manager send ten invitations against a limit of two and have all
 * ten accepted — the limit would hold at the moment of each invite and fail at
 * the moment they were used, which is exactly when nobody is watching.
 * Revoking, declining or letting one expire releases the seat.
 */
class StaffAllowance
{
    public function __construct(
        protected Entitlements $entitlements,
    ) {}

    /**
     * The package's cap, or null for unlimited.
     */
    public function limit(BusinessAccount $account): ?int
    {
        return $this->entitlements->limit($account, PackageFeature::StaffLimit);
    }

    /**
     * Seats already taken: staff who have joined, plus invitations still open.
     */
    public function used(BusinessAccount $account): int
    {
        return $this->activeStaff($account) + $this->openInvitations($account);
    }

    public function activeStaff(BusinessAccount $account): int
    {
        return $account->memberships()
            ->where('role', '!=', AccountRole::Owner->value)
            ->count();
    }

    public function openInvitations(BusinessAccount $account): int
    {
        return AccountInvitation::query()
            ->where('business_account_id', $account->id)
            ->live()
            ->count();
    }

    /**
     * Seats left, or null when the package sets no limit.
     */
    public function remaining(BusinessAccount $account): ?int
    {
        $limit = $this->limit($account);

        if ($limit === null) {
            return null;
        }

        return max($limit - $this->used($account), 0);
    }

    /**
     * Whether one more may be added.
     *
     * A limit of zero is a package that grants no staff at all, and is
     * deliberately distinguishable from an absent limit meaning unlimited.
     */
    public function hasRoom(BusinessAccount $account, int $additional = 1): bool
    {
        $limit = $this->limit($account);

        if ($limit === null) {
            return true;
        }

        return $this->used($account) + $additional <= $limit;
    }

    /**
     * Whether this account may manage staff at all.
     *
     * A package with no staff facility should show no staff interface (D1) —
     * not an empty one with a disabled button.
     */
    public function allowsStaff(BusinessAccount $account): bool
    {
        $limit = $this->limit($account);

        return $limit === null || $limit > 0;
    }
}
