<?php

namespace App\Domain\ExternalStore\Queries;

use App\Domain\Account\Models\BusinessAccount;
use App\Domain\ExternalStore\Models\ExternalStore;
use App\Domain\Package\Entitlements;
use App\Domain\Package\Enums\PackageFeature;

/**
 * What the account's package allows in external stores (D29).
 *
 * The one answer the screens show and the connect action enforces. Only live
 * connections count: a disconnected store keeps its orders and holds no place.
 */
class ExternalStoreAllowance
{
    public function __construct(
        protected Entitlements $entitlements,
    ) {}

    /**
     * @return array{allowed: bool, limit: int|null, used: int, remaining: int|null}
     */
    public function for(BusinessAccount $account): array
    {
        $used = ExternalStore::query()->forAccount($account)->live()->count();

        return [
            'allowed' => $this->allows($account),
            'limit' => $this->entitlements->limit($account, PackageFeature::ExternalStoreLimit),
            'used' => $used,
            'remaining' => $this->entitlements->remaining($account, PackageFeature::ExternalStoreLimit, $used),
        ];
    }

    public function allows(BusinessAccount $account): bool
    {
        return $account->canTransact()
            && $this->entitlements->allows($account, PackageFeature::ExternalStoreAccess);
    }

    public function hasRoomFor(BusinessAccount $account): bool
    {
        $used = ExternalStore::query()->forAccount($account)->live()->count();

        return $this->entitlements->hasCapacityFor($account, PackageFeature::ExternalStoreLimit, $used);
    }

    public function limit(BusinessAccount $account): int
    {
        return $this->entitlements->limit($account, PackageFeature::ExternalStoreLimit) ?? 0;
    }
}
