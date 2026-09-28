<?php

namespace App\Domain\Address\Actions;

use App\Domain\Address\Enums\AddressStatus;
use App\Domain\Address\Models\SharedAddress;
use Illuminate\Database\DatabaseManager;

/**
 * Archive an address. Never a delete — a `SharedAddress` is never removed,
 * only moved out of use, through its own state machine
 * ({@see AddressStatus}). Clears `is_default`: an archived address is never
 * the one a future order or shipment would fall back to.
 */
class ArchiveSharedAddress
{
    public function __construct(protected DatabaseManager $database) {}

    public function handle(SharedAddress $address): SharedAddress
    {
        return $this->database->transaction(function () use ($address) {
            /** @var SharedAddress $locked */
            $locked = SharedAddress::query()->whereKey($address->id)->lockForUpdate()->firstOrFail();

            $locked->transitionTo(AddressStatus::Archived);
            $locked->is_default = false;
            $locked->save();

            return $locked->refresh();
        });
    }
}
