<?php

namespace App\Domain\Address\Queries;

use App\Domain\Address\Enums\AddressOwnerType;
use App\Domain\Address\Models\SharedAddress;
use App\Http\Controllers\Supplier\PayoutMethodController;
use Illuminate\Database\Eloquent\Collection;

/**
 * Self-scoped reads over the shared address book (§31.3).
 *
 * The owner is always a parameter, never resolved from `auth()` in here —
 * the controller resolves the acting `BusinessAccount` or Supplier from its
 * own guard and passes the id down, the same convention
 * {@see PayoutMethodController} already uses.
 */
class SharedAddressesForOwner
{
    /**
     * @return Collection<int, SharedAddress>
     */
    public function forOwner(AddressOwnerType $ownerType, int $ownerId): Collection
    {
        return SharedAddress::query()
            ->where('owner_type', $ownerType->value)
            ->where('owner_id', $ownerId)
            ->with(['division', 'district', 'upazila', 'union'])
            ->orderByDesc('is_default')
            ->orderByDesc('id')
            ->get();
    }

    public function findForOwner(AddressOwnerType $ownerType, int $ownerId, string $publicId): ?SharedAddress
    {
        return SharedAddress::query()
            ->where('owner_type', $ownerType->value)
            ->where('owner_id', $ownerId)
            ->where('public_id', $publicId)
            ->first();
    }
}
