<?php

namespace App\Domain\Payout\Queries;

use App\Domain\Payout\Enums\PayoutOwnerType;
use App\Domain\Payout\Models\PayoutMethod;
use Illuminate\Database\Eloquent\Collection;

/**
 * Self-scoped reads over the shared payout-method table (§31.3). The owner
 * is always a parameter, never resolved from `auth()` in here — the
 * controller resolves the acting `BusinessAccount` or Supplier from its own
 * guard and passes the id down.
 */
class PayoutMethodsForOwner
{
    /**
     * @return Collection<int, PayoutMethod>
     */
    public function forOwner(PayoutOwnerType $ownerType, int $ownerId): Collection
    {
        return PayoutMethod::query()
            ->where('owner_type', $ownerType->value)
            ->where('owner_id', $ownerId)
            ->with(['bank', 'branch'])
            ->orderByDesc('is_default')
            ->orderByDesc('id')
            ->get();
    }

    public function findForOwner(PayoutOwnerType $ownerType, int $ownerId, string $publicId): ?PayoutMethod
    {
        return PayoutMethod::query()
            ->where('owner_type', $ownerType->value)
            ->where('owner_id', $ownerId)
            ->where('public_id', $publicId)
            ->first();
    }
}
