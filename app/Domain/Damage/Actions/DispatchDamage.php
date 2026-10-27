<?php

namespace App\Domain\Damage\Actions;

use App\Domain\Access\Enums\PermissionAction;
use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Courier\Models\CourierProvider;
use App\Domain\Damage\Actions\Concerns\GuardsDamageActions;
use App\Domain\Damage\Enums\DamageDispatchStatus;
use App\Domain\Damage\Enums\DamageStatus;
use App\Domain\Damage\Exceptions\DamageRefused;
use App\Domain\Damage\Models\DamageDispatch;
use App\Domain\Damage\Models\OrderDamage;
use App\Domain\Inventory\Enums\StockBucket;
use App\Domain\Order\Enums\OrderStatusChangeSource;
use App\Models\User;
use App\Support\StatusHistory\StatusChange;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DatabaseManager;

/**
 * Send a damaged item to the Partner who asked for it, and say how it can be
 * tracked (D35).
 *
 * Only staff who may dispatch, and only an item whose delivery has been requested
 * and paid for. The units leave stock **out of the damaged bucket and nowhere
 * else** — never into a sellable one — through the stock ledger, once, under a key
 * of their own: a second dispatch finds the first and moves nothing. The
 * dispatch records who sent it, when, with which courier and under which
 * tracking reference, so the Partner can follow it.
 */
class DispatchDamage
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
    public function handle(User $actor, OrderDamage $damage, string $trackingNumber, ?CourierProvider $courier = null): DamageDispatch
    {
        $this->authorizeStaff($actor, PermissionAction::Dispatch, 'dispatch a damaged item');

        $trackingNumber = trim($trackingNumber);

        if ($trackingNumber === '') {
            throw DamageRefused::trackingRequired();
        }

        $sent = false;

        $dispatch = $this->database->transaction(function () use ($actor, $damage, $trackingNumber, $courier, &$sent) {
            $locked = $this->locked($damage);

            /** @var DamageDispatch|null $dispatch */
            $dispatch = $locked->dispatch()->lockForUpdate()->first();

            if ($dispatch === null) {
                throw DamageRefused::notRequested();
            }

            // Already on its way, or arrived: the first dispatch stands.
            if ($dispatch->status !== DamageDispatchStatus::Requested) {
                return $dispatch;
            }

            if ($locked->status !== DamageStatus::DispatchRequested) {
                throw DamageRefused::notInThatState();
            }

            $movement = $this->stock->handle(
                $locked,
                StockBucket::Damaged,
                null,
                $dispatch->quantity,
                'dispatch',
                'Damaged item dispatched to the Partner on '.$locked->reference.'.',
                $actor,
            );

            $dispatch->forceFill([
                'status' => DamageDispatchStatus::Dispatched,
                'courier_provider_id' => $courier?->id,
                'tracking_number' => mb_substr($trackingNumber, 0, 120),
                'dispatched_by' => $actor->id,
                'dispatched_at' => CarbonImmutable::now(),
                'stock_movement_id' => $movement['stock_movement_id'],
                'partner_stock_movement_id' => $movement['partner_stock_movement_id'],
            ])->save();

            $locked->moveTo(
                DamageStatus::Dispatched,
                new StatusChange(
                    actorId: $actor->id,
                    reason: 'The item was dispatched to the Partner.',
                    publicNote: 'damages.notes.dispatched',
                ),
                OrderStatusChangeSource::Staff,
            );

            $sent = true;

            return $dispatch;
        });

        if ($sent) {
            $this->audit->handle(new AuditEntry(
                action: 'damage.dispatched',
                actorId: $actor->id,
                auditableType: OrderDamage::class,
                auditableId: $damage->id,
                after: [
                    'reference' => $damage->reference,
                    'quantity' => $dispatch->quantity,
                    'tracking_number' => $dispatch->tracking_number,
                    'dispatch' => $dispatch->public_id,
                ],
                accountId: $damage->business_account_id,
                module: PermissionModule::Damage->value,
            ));
        }

        $damage->refresh();

        return $dispatch;
    }
}
