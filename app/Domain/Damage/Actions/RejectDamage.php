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
 * End a damage case without a loss, with the reason (D35).
 *
 * Open to whoever holds the stage: an inspector until inspection is recorded
 * (a report that was withdrawn, a parcel that never came), the person who
 * assigns liability once it is waiting for that decision. A decided case is not
 * rejected: what it cost is corrected by a compensating entry, never by a
 * status. Units already moved by an inspection stay where the inspection put
 * them — a rejection is a decision about money, not a movement of stock.
 */
class RejectDamage
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
    public function handle(User $actor, OrderDamage $damage, string $reason): OrderDamage
    {
        $reason = trim($reason);

        if (mb_strlen($reason) < ApproveDamageLiability::MINIMUM_REASON) {
            throw DamageRefused::decisionNeedsAReason();
        }

        $rejected = $this->database->transaction(function () use ($actor, $damage, $reason) {
            $locked = $this->locked($damage);

            if ($locked->status === DamageStatus::Rejected) {
                return $locked;
            }

            // A decided case is not rejected, whoever asks.
            if (! in_array($locked->status, [
                DamageStatus::Reported, DamageStatus::AwaitingReceipt, DamageStatus::Received,
                DamageStatus::UnderInspection, DamageStatus::LiabilityReview,
            ], true)) {
                throw DamageRefused::notInThatState();
            }

            $this->authorizeStaff(
                $actor,
                $locked->status === DamageStatus::LiabilityReview ? PermissionAction::AssignLiability : PermissionAction::Inspect,
                'reject this damage',
            );

            // `received` has no direct way out: it is inspected, or it is not.
            if ($locked->status === DamageStatus::Received) {
                $locked->moveTo(
                    DamageStatus::UnderInspection,
                    new StatusChange(actorId: $actor->id, reason: 'Taken under inspection to be closed.'),
                    OrderStatusChangeSource::Staff,
                );
            }

            $locked->moveTo(
                DamageStatus::Rejected,
                new StatusChange(actorId: $actor->id, reason: $reason, publicNote: 'damages.notes.rejected'),
                OrderStatusChangeSource::Staff,
            );

            return $locked;
        });

        $this->audit->handle(new AuditEntry(
            action: 'damage.rejected',
            actorId: $actor->id,
            auditableType: OrderDamage::class,
            auditableId: $rejected->id,
            after: ['reference' => $rejected->reference],
            reason: $reason,
            accountId: $rejected->business_account_id,
            module: PermissionModule::Damage->value,
        ));

        $damage->setRawAttributes($rejected->getAttributes(), sync: true);

        return $rejected;
    }
}
