<?php

namespace App\Domain\Damage\Actions;

use App\Domain\Access\Enums\PermissionAction;
use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Access\PermissionCatalogue;
use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Damage\Actions\Concerns\GuardsDamageActions;
use App\Domain\Damage\Enums\DamageDispatchStatus;
use App\Domain\Damage\Enums\DamageStatus;
use App\Domain\Damage\Exceptions\DamageRefused;
use App\Domain\Damage\Models\DamageDispatch;
use App\Domain\Damage\Models\OrderDamage;
use App\Domain\Order\Enums\OrderStatusChangeSource;
use App\Models\User;
use App\Support\StatusHistory\StatusChange;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DatabaseManager;

/**
 * Record that the Partner has the damaged item in their hands (D35).
 *
 * By the Partner whose item it is, or by staff who may dispatch it on their
 * behalf. Only a dispatched item can be received, once; a repeat finds the first.
 */
class ConfirmDamageReceivedByPartner
{
    use GuardsDamageActions;

    public function __construct(
        protected RecordAuditLog $audit,
        protected DatabaseManager $database,
    ) {}

    /**
     * @throws DamageRefused
     * @throws AuthorizationException
     */
    public function handle(User $actor, OrderDamage $damage): DamageDispatch
    {
        $byStaff = $actor->can(PermissionCatalogue::name(PermissionModule::Damage, PermissionAction::Dispatch));

        if (! $byStaff) {
            $this->authorizeOwner($actor, $damage, true, 'confirm a damaged item arrived');
        }

        $recorded = false;

        $dispatch = $this->database->transaction(function () use ($actor, $damage, &$recorded) {
            $locked = $this->locked($damage);

            /** @var DamageDispatch|null $dispatch */
            $dispatch = $locked->dispatch()->lockForUpdate()->first();

            if ($dispatch === null) {
                throw DamageRefused::notRequested();
            }

            if ($dispatch->status === DamageDispatchStatus::ReceivedByPartner) {
                return $dispatch;
            }

            if ($dispatch->status !== DamageDispatchStatus::Dispatched) {
                throw DamageRefused::notDispatched();
            }

            $dispatch->forceFill([
                'status' => DamageDispatchStatus::ReceivedByPartner,
                'received_by' => $actor->id,
                'received_at' => CarbonImmutable::now(),
            ])->save();

            $locked->moveTo(
                DamageStatus::ReceivedByPartner,
                new StatusChange(
                    actorId: $actor->id,
                    reason: 'The Partner received the item.',
                    publicNote: 'damages.notes.received_by_partner',
                ),
                $actor->businessAccount === null ? OrderStatusChangeSource::Staff : OrderStatusChangeSource::Account,
            );

            $recorded = true;

            return $dispatch;
        });

        if ($recorded) {
            $this->audit->handle(new AuditEntry(
                action: 'damage.received_by_partner',
                actorId: $actor->id,
                auditableType: OrderDamage::class,
                auditableId: $damage->id,
                after: ['reference' => $damage->reference, 'dispatch' => $dispatch->public_id],
                accountId: $damage->business_account_id,
                module: PermissionModule::Damage->value,
            ));
        }

        $damage->refresh();

        return $dispatch;
    }
}
