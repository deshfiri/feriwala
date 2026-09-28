<?php

namespace App\Domain\Address\Actions;

use App\Domain\Address\Enums\AddressStatus;
use App\Domain\Address\Models\SharedAddress;
use App\Domain\Supplier\Actions\SavePayoutMethod;
use Illuminate\Database\DatabaseManager;
use RuntimeException;

/**
 * Make one address the default for its owner and type, clearing every other
 * default that owner holds under that same type — one-default-per-owner the
 * same way {@see SavePayoutMethod} already enforces it: action logic, not a
 * database constraint.
 */
class SetDefaultSharedAddress
{
    public function __construct(protected DatabaseManager $database) {}

    public function handle(SharedAddress $address): SharedAddress
    {
        return $this->database->transaction(function () use ($address) {
            /** @var SharedAddress $locked */
            $locked = SharedAddress::query()->whereKey($address->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== AddressStatus::Active) {
                throw new RuntimeException('An archived address cannot be made the default.');
            }

            SharedAddress::query()
                ->where('owner_type', $locked->owner_type)
                ->where('owner_id', $locked->owner_id)
                ->where('type', $locked->type)
                ->where('id', '!=', $locked->id)
                ->update(['is_default' => false]);

            $locked->forceFill(['is_default' => true])->save();

            return $locked->refresh();
        });
    }
}
