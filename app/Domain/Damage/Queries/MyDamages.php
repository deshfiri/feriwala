<?php

namespace App\Domain\Damage\Queries;

use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Damage\Enums\DamageStatus;
use App\Domain\Damage\Models\OrderDamage;
use App\Domain\Order\Enums\ReturnInitiator;
use App\Domain\Order\Enums\ReturnParty;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * A Partner's own damaged items — `My Products > My Damages` (D35).
 *
 * **Self-scoped in the query, not in the screen.** Every row is filtered by the
 * account the person is signed in to, never by anything a request names, so
 * another Partner's case cannot be reached by changing a parameter.
 *
 * What belongs here is what the Partner **bears the loss for**: the item the
 * Partner or their customer damaged, once the loss has been decided. A damage
 * that was Banij's fault — its packing, its warehouse, its handling — is
 * Banij's loss and is not in this list at all.
 */
class MyDamages
{
    /**
     * @return Builder<OrderDamage>
     */
    public function query(BusinessAccount $account): Builder
    {
        $values = fn (array $statuses) => array_map(fn (DamageStatus $status) => $status->value, $statuses);

        return OrderDamage::query()
            ->where('business_account_id', $account->id)
            ->where(fn (Builder $either) => $either
                // What the Partner bears, once the loss has been decided…
                ->where(fn (Builder $bears) => $bears
                    ->where('responsible_party', ReturnParty::Partner->value)
                    ->whereIn('status', $values([
                        DamageStatus::FinancialPostingPending, DamageStatus::Finalized, DamageStatus::DispatchRequested,
                        DamageStatus::Dispatched, DamageStatus::ReceivedByPartner,
                    ])))
                // …and the Partner's own reports while they are being looked at, or if they were refused.
                // A damage that turns out to be Banij's is not here at all.
                ->orWhere(fn (Builder $reported) => $reported
                    ->where('initiated_by_type', ReturnInitiator::Partner->value)
                    ->whereIn('status', $values([
                        DamageStatus::Reported, DamageStatus::AwaitingReceipt, DamageStatus::Received,
                        DamageStatus::UnderInspection, DamageStatus::LiabilityReview, DamageStatus::Rejected,
                    ]))))
            ->with(['order:id,public_id,reference', 'dispositions', 'dispatch', 'statusHistory'])
            ->orderByDesc('id');
    }

    /**
     * @return LengthAwarePaginator<int, OrderDamage>
     */
    public function paginate(BusinessAccount $account, int $perPage = 20): LengthAwarePaginator
    {
        return $this->query($account)->paginate($perPage);
    }

    /**
     * One of the Partner's own damaged items, or nothing: never another's.
     */
    public function find(BusinessAccount $account, string $publicId): ?OrderDamage
    {
        /** @var OrderDamage|null $damage */
        $damage = $this->query($account)->where('public_id', $publicId)->first();

        return $damage;
    }
}
