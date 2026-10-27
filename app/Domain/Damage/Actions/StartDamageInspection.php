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
use App\Domain\Order\Enums\OrderStatusChangeSource;
use App\Models\User;
use App\Support\StatusHistory\StatusChange;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DatabaseManager;

/**
 * Take a received damaged item under inspection (D35).
 *
 * Only an item that has *arrived* can be inspected: one still waiting for its
 * parcel is refused in those words, and nothing else about the case can move
 * until a person has the goods in front of them.
 */
class StartDamageInspection
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
    public function handle(User $actor, OrderDamage $damage): OrderDamage
    {
        $this->authorizeStaff($actor, PermissionAction::Inspect, 'inspect a damaged item');

        $started = false;

        $inspecting = $this->database->transaction(function () use ($actor, $damage, &$started) {
            $locked = $this->locked($damage);

            // Already under inspection, or past it: the first request's answer stands.
            if ($locked->status === DamageStatus::UnderInspection || $locked->status->isDecided() || $locked->status === DamageStatus::LiabilityReview) {
                return $locked;
            }

            if ($locked->status === DamageStatus::AwaitingReceipt) {
                throw DamageRefused::parcelNotReceived();
            }

            if ($locked->status !== DamageStatus::Received) {
                throw DamageRefused::notInThatState();
            }

            $locked->moveTo(
                DamageStatus::UnderInspection,
                new StatusChange(actorId: $actor->id, reason: 'Inspection started.', publicNote: 'damages.notes.under_inspection'),
                OrderStatusChangeSource::Staff,
            );

            $started = true;

            return $locked;
        });

        if ($started) {
            $this->audit->handle(new AuditEntry(
                action: 'damage.inspection_started',
                actorId: $actor->id,
                auditableType: OrderDamage::class,
                auditableId: $inspecting->id,
                after: ['reference' => $inspecting->reference],
                accountId: $inspecting->business_account_id,
                module: PermissionModule::Damage->value,
            ));
        }

        $damage->setRawAttributes($inspecting->getAttributes(), sync: true);

        return $inspecting;
    }
}
