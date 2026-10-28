<?php

namespace App\Domain\Damage\Actions;

use App\Domain\Access\Enums\PermissionAction;
use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Damage\Actions\Concerns\GuardsDamageActions;
use App\Domain\Damage\Exceptions\DamageRefused;
use App\Domain\Damage\Models\DamageCharge;
use App\Domain\Damage\Models\OrderDamage;
use App\Domain\Notification\Actions\AnnounceCaseEvent;
use App\Domain\Order\Enums\ReturnChargeKind;
use App\Domain\Order\Enums\ReturnParty;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DatabaseManager;

/**
 * Correct who bears a decided damage (D35).
 *
 * Only by someone who may assign liability, always with a reason, and never by
 * editing anything: the change is a new line of responsibility history (previous
 * value, new value, who, when, why), and if the loss was already posted under the
 * old attribution it is put right the only way a ledger allows. The posted loss
 * is **reversed** by a new entry pointing at the one it answers — the wallet gets
 * back exactly the kinds of money it lost — and **posted again** to the new party
 * under a key of its own revision. That is one compensating pair under one
 * correction. Moving the loss to Banij leaves the Partner's wallet whole; moving
 * it to the Partner debits them, or leaves an outstanding liability if they
 * cannot cover it, exactly as a first posting would.
 *
 * **Once, however many ask.** The case is locked while it is decided, and a
 * request for the attribution the case already has finds it and does nothing, so
 * two corrections racing make one pair, not two.
 *
 * Not once the item is being sent to the Partner: they have asked for it and paid
 * for its delivery on the strength of who bears it.
 */
class ChangeDamageResponsibility
{
    use GuardsDamageActions;

    public function __construct(
        protected PostDamageCharge $charges,
        protected ReverseDamageCharge $reversals,
        protected RecordAuditLog $audit,
        protected DatabaseManager $database,
    ) {}

    /**
     * @throws DamageRefused
     * @throws AuthorizationException
     */
    public function handle(User $actor, OrderDamage $damage, ReturnParty $to, string $reason): OrderDamage
    {
        $this->authorizeStaff($actor, PermissionAction::AssignLiability, 'change who bears a damage');

        $reason = trim($reason);

        if (mb_strlen($reason) < ApproveDamageLiability::MINIMUM_REASON) {
            throw DamageRefused::responsibilityNeedsAReason();
        }

        $previous = null;
        $changed = false;

        $result = $this->database->transaction(function () use ($actor, $damage, $to, $reason, &$previous, &$changed) {
            $locked = $this->locked($damage);

            // Not decided yet: the decision itself sets who bears it.
            if (! $locked->status->isDecided()) {
                throw DamageRefused::notInThatState();
            }

            // Already attributed this way: the first change stands.
            if ($locked->responsible_party === $to) {
                return $locked;
            }

            if ($locked->status->hasDispatchStarted()) {
                throw DamageRefused::responsibilityFixedOnceDispatching();
            }

            $previous = $locked->responsible_party;

            $locked->responsibilityChanges()->create([
                'previous_party' => $previous,
                'new_party' => $to,
                'changed_by' => $actor->id,
                'changed_at' => CarbonImmutable::now(),
                'reason' => $reason,
            ]);

            $locked->forceFill(['responsible_party' => $to])->save();

            if ($locked->status->isPosted()) {
                $this->correctPostedLoss($locked, $previous, $to, $reason, $actor);
            }

            $changed = true;

            return $locked;
        });

        if ($changed) {
            $this->audit->handle(new AuditEntry(
                action: 'damage.responsibility_changed',
                actorId: $actor->id,
                auditableType: OrderDamage::class,
                auditableId: $result->id,
                before: ['responsible_party' => $previous?->value],
                after: ['responsible_party' => $to->value, 'reference' => $result->reference],
                reason: $reason,
                accountId: $result->business_account_id,
                module: PermissionModule::Damage->value,
            ));

            app(AnnounceCaseEvent::class)->responsibilityCorrected($result, $result->responsibilityRevision());
        }

        $damage->setRawAttributes($result->getAttributes(), sync: true);

        return $result;
    }

    /**
     * One compensating pair: reverse what stands under the old attribution, post
     * it again under the new.
     */
    protected function correctPostedLoss(OrderDamage $damage, ?ReturnParty $from, ReturnParty $to, string $reason, User $actor): void
    {
        $revision = $damage->responsibilityRevision();

        $live = $damage->charges()
            ->where('entry_type', DamageCharge::CHARGE)
            ->where('kind', ReturnChargeKind::DamageLoss->value)
            ->whereIn('status', ['posted', 'settled', 'outstanding', 'recorded'])
            ->get();

        foreach ($live as $charge) {
            $this->reversals->handle(
                $charge,
                'Attribution changed from '.($from?->label() ?? 'nobody').' to '.$to->label().': '.$reason,
                $actor,
            );

            $this->charges->handle(
                damage: $damage,
                kind: ReturnChargeKind::DamageLoss,
                amount: $charge->amount,
                bearer: $to,
                reason: $charge->reason.' (re-attributed to '.$to->label().')',
                actor: $actor,
                revision: $revision,
            );
        }
    }
}
