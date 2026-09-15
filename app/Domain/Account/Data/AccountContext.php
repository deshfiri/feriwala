<?php

namespace App\Domain\Account\Data;

use App\Domain\Account\Enums\AccountPermission;
use App\Domain\Account\Enums\AccountRole;
use App\Domain\Account\StaffAllowance;
use App\Domain\Inventory\Models\StockAllocation;
use App\Domain\Order\Enums\OrderSource;
use App\Domain\Order\Models\Order;
use App\Domain\Package\Entitlements;
use App\Domain\Package\Enums\PackageFeature;
use App\Domain\Wholesale\Models\CartItem;
use App\Models\User;

/**
 * The one account the signed-in person is working in (D1).
 *
 * Shared on every Inertia response in place of the starter kit's `currentTeam`
 * and `teams` pair. There is no list, because there is nothing to choose
 * between — a person belongs to one business account and cannot switch. The
 * front end renders this as a name, not as a control.
 *
 * `managesStaff` and `allowsStaff` are separate on purpose. The first is about
 * the person (a staff member is not a manager); the second is about the package
 * (a solo plan has no staff facility at all). When either is false the Staff
 * area is absent from the navigation rather than present and disabled — an
 * account that never bought staff should not be shown a door it cannot open.
 */
readonly class AccountContext
{
    public function __construct(
        public string $id,
        public string $name,
        public string $status,
        public string $role,
        public string $roleLabel,
        public bool $isOwner,
        public bool $managesStaff,
        public bool $allowsStaff,

        /*
         * Whether the account may use each business method right now (§10):
         * trading, and on a package that includes it. Separate, because a
         * package may grant one without the other, and the navigation shows
         * each catalogue only where it would not open onto an empty refusal.
         */
        public bool $allowsWholesale = false,
        public bool $allowsDropshipping = false,

        /*
         * Whether central stock is set aside for this account right now
         * (§19, P3-30). The navigation shows the allocated-stock page only then,
         * rather than a door onto an empty list for every account.
         */
        public bool $holdsAllocatedStock = false,

        /*
         * How many products this person has in their wholesale cart (§14,
         * P4-4), for the navigation. Counted only when the account may buy
         * wholesale at all.
         */
        public int $wholesaleCartLines = 0,

        /*
         * Whether the account has any wholesale order to follow (§10.2, P4-12).
         * The orders door stays open once there is one, even after the package
         * stops including wholesale — an order somebody paid for is theirs.
         */
        public bool $hasWholesaleOrders = false,
    ) {}

    public static function forUser(User $user, StaffAllowance $allowance, ?Entitlements $entitlements = null): ?self
    {
        $account = $user->businessAccount;
        $role = $user->accountRole();

        // Platform staff have no business account, and that is not a gap to fill
        // with a placeholder — the ERP shell simply has no account to name.
        if ($account === null || $role === null) {
            return null;
        }

        $allowsStaff = $allowance->allowsStaff($account);
        $allowsWholesale = $entitlements !== null && $account->canTransact()
            && $entitlements->allows($account, PackageFeature::WholesaleEnabled);

        return new self(
            id: $account->public_id,
            name: $account->name,
            status: $account->status->value,
            role: $role->value,
            roleLabel: $role->label(),
            isOwner: $role === AccountRole::Owner,
            managesStaff: $allowsStaff && $role->hasPermission(AccountPermission::InviteStaff),
            allowsStaff: $allowsStaff,
            allowsWholesale: $allowsWholesale,
            allowsDropshipping: $entitlements !== null && $account->canTransact()
                && $entitlements->allows($account, PackageFeature::DropshippingEnabled),
            // The same rows the allocated-stock page shows: units still set aside,
            // in a warehouse that is switched on.
            holdsAllocatedStock: StockAllocation::query()
                ->where('business_account_id', $account->id)
                ->where('quantity', '>', 0)
                ->whereHas('item.warehouse', fn ($query) => $query->where('is_active', true))
                ->exists(),
            wholesaleCartLines: $allowsWholesale
                ? CartItem::query()
                    ->whereHas('cart', fn ($query) => $query
                        ->where('user_id', $user->id)
                        ->where('business_account_id', $account->id))
                    ->count()
                : 0,
            hasWholesaleOrders: Order::query()
                ->where('business_account_id', $account->id)
                ->where('source', OrderSource::ErpWholesale)
                ->exists(),
        );
    }
}
