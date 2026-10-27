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
use App\Domain\Order\Enums\ReturnChargeKind;
use App\Domain\Order\Enums\ReturnParty;
use App\Models\User;
use App\Support\Money\Money;
use App\Support\StatusHistory\StatusChange;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DatabaseManager;

/**
 * Post an approved damage loss, once (D35): the only step that moves money.
 *
 * Refuses until everything it depends on is true — the parcel received where one
 * was expected, the inspection complete, who bears it decided and the approved
 * loss recorded. Then, in one transaction under a lock on the case:
 *
 *   - **Banij bears it**: the loss is recorded in Banij's accounting. The
 *     Partner's wallet is not touched and the Partner gains no liability.
 *   - **The Partner bears it**: the loss is debited from their wallet through the
 *     ledger, platform credit first and withdrawable money after, with the exact
 *     split remembered. If the wallet cannot cover it the amount is an
 *     outstanding liability, never a negative balance.
 *
 * **Once.** The charge's key names the case, the kind and the attribution
 * revision, and is unique on the charge, the wallet transaction and the ledger
 * entry; a repeat, or two finalizations racing, finds the first and posts
 * nothing more. A loss of nothing posts nothing and is still finalized.
 */
class FinalizeDamage
{
    use GuardsDamageActions;

    public function __construct(
        protected PostDamageCharge $charges,
        protected RecordAuditLog $audit,
        protected DatabaseManager $database,
    ) {}

    /**
     * @throws DamageRefused
     * @throws AuthorizationException
     */
    public function handle(User $actor, OrderDamage $damage): OrderDamage
    {
        $this->authorizeStaff($actor, PermissionAction::Finalize, 'post a damage loss');

        $posted = false;

        $finalized = $this->database->transaction(function () use ($actor, $damage, &$posted) {
            $locked = $this->locked($damage);

            // Already posted by the request that got here first.
            if ($locked->status->isPosted()) {
                return $locked;
            }

            [$loss, $bearer] = $this->requireReadyToPost($locked);

            $charge = $this->charges->handle(
                damage: $locked,
                kind: ReturnChargeKind::DamageLoss,
                amount: $loss,
                bearer: $bearer,
                reason: 'Damage loss on '.$locked->reference.' ('.$locked->cause?->label().').',
                actor: $actor,
                revision: $locked->responsibilityRevision(),
            );

            $locked->forceFill([
                'finalized_by' => $actor->id,
                'finalized_at' => CarbonImmutable::now(),
            ]);

            $locked->moveTo(
                DamageStatus::Finalized,
                new StatusChange(
                    actorId: $actor->id,
                    reason: $charge === null
                        ? 'Finalized as a write-off: nothing was posted.'
                        : 'The loss was posted ('.$charge->status->label().').',
                    publicNote: 'damages.notes.finalized',
                ),
                OrderStatusChangeSource::Staff,
            );

            $posted = true;

            return $locked;
        });

        if ($posted) {
            $this->audit->handle(new AuditEntry(
                action: 'damage.finalized',
                actorId: $actor->id,
                auditableType: OrderDamage::class,
                auditableId: $finalized->id,
                after: [
                    'reference' => $finalized->reference,
                    'responsible_party' => $finalized->responsible_party?->value,
                    'loss' => $finalized->approved_loss_amount?->toDecimal(),
                    'currency' => $finalized->currency_code,
                ],
                accountId: $finalized->business_account_id,
                module: PermissionModule::Damage->value,
            ));
        }

        $damage->setRawAttributes($finalized->getAttributes(), sync: true);

        return $finalized;
    }

    /**
     * Nothing is posted before inspection is complete, liability is decided and
     * the approved loss is recorded.
     *
     * @return array{0: Money, 1: ReturnParty} the approved loss and who bears it
     *
     * @throws DamageRefused
     */
    protected function requireReadyToPost(OrderDamage $damage): array
    {
        if ($damage->parcel_expected && $damage->received_at === null) {
            throw DamageRefused::parcelNotReceived();
        }

        if ($damage->inspected_at === null) {
            throw DamageRefused::notInspected();
        }

        if ($damage->status !== DamageStatus::FinancialPostingPending
            || $damage->responsible_party === null
            || $damage->approved_loss_amount === null) {
            throw DamageRefused::notInThatState();
        }

        return [$damage->approved_loss_amount, $damage->responsible_party];
    }
}
