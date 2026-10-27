<?php

namespace App\Domain\Damage\Actions;

use App\Domain\Access\Enums\PermissionAction;
use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Damage\Actions\Concerns\GuardsDamageActions;
use App\Domain\Damage\Enums\DamageStatus;
use App\Domain\Damage\Exceptions\DamageRefused;
use App\Domain\Damage\Models\OrderDamage;
use App\Domain\Inventory\Enums\StockBucket;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Order\Enums\OrderStatusChangeSource;
use App\Models\User;
use App\Support\StatusHistory\StatusChange;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DatabaseManager;

/**
 * Mark the parcel of a reported damage as having arrived (D35).
 *
 * Confirming that goods physically reached Banij is its own step and the first
 * thing that moves stock for a case opened from a delivered product: the units
 * leave `sold` and are held in `returned` — held apart, not sellable — until an
 * inspector decides what each is. The movement goes through the stock ledgers,
 * once, under a key of its own, so a repeat receives nothing twice.
 *
 * A case opened from goods already back never waits for a parcel and is never
 * received here.
 */
class ReceiveDamageParcel
{
    use GuardsDamageActions;

    public function __construct(
        protected MoveDamageStock $stock,
        protected RecordAuditLog $audit,
        protected DatabaseManager $database,
    ) {}

    /**
     * @throws DamageRefused
     * @throws AuthorizationException
     */
    public function handle(User $actor, OrderDamage $damage, ?Warehouse $warehouse = null): OrderDamage
    {
        $this->authorizeStaff($actor, PermissionAction::Inspect, 'receive a damaged item');

        $received = $this->database->transaction(function () use ($actor, $damage, $warehouse) {
            $locked = $this->locked($damage);

            // Already received by the request that got here first.
            if ($locked->received_at !== null) {
                return $locked;
            }

            if ($locked->status !== DamageStatus::AwaitingReceipt) {
                throw DamageRefused::notInThatState();
            }

            if ($warehouse !== null) {
                $locked->forceFill(['warehouse_id' => $warehouse->id]);
            }

            $this->stock->handle(
                $locked,
                StockBucket::Sold,
                StockBucket::Returned,
                $locked->quantity,
                'intake',
                'Damaged item received on '.$locked->reference.'.',
                $actor,
            );

            $locked->forceFill([
                'in_custody' => true,
                'held_bucket' => StockBucket::Returned,
                'received_by' => $actor->id,
                'received_at' => CarbonImmutable::now(),
            ]);

            $locked->moveTo(
                DamageStatus::Received,
                new StatusChange(actorId: $actor->id, reason: 'The parcel arrived.', publicNote: 'damages.notes.received'),
                OrderStatusChangeSource::Staff,
            );

            return $locked;
        });

        $this->audit->handle(new AuditEntry(
            action: 'damage.received',
            actorId: $actor->id,
            auditableType: OrderDamage::class,
            auditableId: $received->id,
            after: ['reference' => $received->reference, 'quantity' => $received->quantity],
            accountId: $received->business_account_id,
            module: PermissionModule::Damage->value,
        ));

        $damage->setRawAttributes($received->getAttributes(), sync: true);

        return $received;
    }
}
