<?php

namespace App\Domain\Payout\Actions;

use App\Domain\Address\Actions\SetDefaultSharedAddress;
use App\Domain\Payout\Enums\PayoutMethodStatus;
use App\Domain\Payout\Models\PayoutMethod;
use Illuminate\Database\DatabaseManager;
use RuntimeException;

/**
 * Make one payout method the default for its owner, clearing every other
 * default that owner holds — action logic, not only a database constraint
 * (the migration also adds a partial unique index as the backstop, the same
 * belt-and-braces {@see SetDefaultSharedAddress}
 * already applies to its own one-default-per-owner rule).
 */
class SetDefaultPayoutMethod
{
    public function __construct(protected DatabaseManager $database) {}

    public function handle(PayoutMethod $method): PayoutMethod
    {
        return $this->database->transaction(function () use ($method) {
            /** @var PayoutMethod $locked */
            $locked = PayoutMethod::query()->whereKey($method->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== PayoutMethodStatus::Active) {
                throw new RuntimeException('An archived payout method cannot be made the default.');
            }

            PayoutMethod::query()
                ->where('owner_type', $locked->owner_type)
                ->where('owner_id', $locked->owner_id)
                ->where('id', '!=', $locked->id)
                ->update(['is_default' => false]);

            $locked->forceFill(['is_default' => true])->save();

            return $locked->refresh();
        });
    }
}
